<?php

namespace FormItem\Ueditor\Lib\Action;

use FormItem\Ueditor\Lib\Uploader;

class WxCrawlerAction extends AAction
{
    private const DEFAULT_ALLOWED_HOSTS = [
        'mp.weixin.qq.com',
        'weixin.qq.com',
    ];

    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 15;
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    protected function fetchWxContent(array $target): bool|string
    {
        $verifyTls = filter_var(
            env('UEDITOR_WX_CRAWLER_VERIFY_TLS', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $verifyTls = $verifyTls ?? true;

        $body = '';
        $tooLarge = false;
        $opts = array(
            CURLOPT_URL => $target['url'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml'),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.71 Safari/537.36',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($chunk) > self::MAX_RESPONSE_BYTES - strlen($body)) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            },
        );

        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if (!$target['is_ip']) {
            if (!defined('CURLOPT_RESOLVE')) {
                return false;
            }

            $ips = $target['ips'];
            if (version_compare(curl_version()['version'], '7.59.0', '<')) {
                $ipv4 = array_values(array_filter(
                    $ips,
                    static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ));
                $ips = array_slice($ipv4 ?: $ips, 0, 1);
            }
            $addresses = array_map(
                static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
                $ips
            );
            $opts[CURLOPT_RESOLVE] = array(
                $target['host'] . ':' . $target['port'] . ':' . implode(',', $addresses),
            );
        }

        $ch = curl_init();
        if (!curl_setopt_array($ch, $opts)) {
            curl_close($ch);
            return false;
        }

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge || $result === false || $error !== '') {
            return false;
        }

        return $body;
    }

    public function run():string
    {
        $url = trim(urldecode((string)($this->get_data['url'] ?? '')));
        $allowedHosts = $this->getAllowedHosts();
        $target = $this->inspectRemoteUrl($url, $allowedHosts);
        if ($target === null) {
            return $this->errorResponse(400, 'invalid url');
        }

        $prefix = (string) env('UEDITOR_WX_CRAWLER_PREFIX', '');
        $requestUrl = $prefix === '' ? $url : $prefix . $url;
        if ($requestUrl !== $url) {
            $target = $this->inspectRemoteUrl($requestUrl, $allowedHosts);
            if ($target === null) {
                return $this->errorResponse(400, 'invalid url');
            }
        }

        $target['ips'] = $this->resolvePublicIps($target['host']);
        if ($target['ips'] === []) {
            return $this->errorResponse(400, 'invalid url');
        }

        $text = $this->fetchWxContent($target);
        if (!is_string($text) || $text === '') {
            return $this->errorResponse(502, 'failed to fetch wx content');
        }

        try {
            $cssToInlineStyles = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
            $text = $cssToInlineStyles->convert($text);
        } catch (\Throwable) {
            return $this->errorResponse(502, 'failed to process wx content');
        }

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $js_url = htmlspecialchars(asset('ueditor/third-party/jquery-1.10.2.min.js'), ENT_QUOTES, 'UTF-8');
        $text .= <<<EOF
    <script type="text/javascript" src="$js_url"></script>
    <script>
            function handleImgSrc(){
                //因微信公众号文章采用懒加载 这里要取消懒加载
                //处理img
                $('#js_content').find('img').each(function (index, item){
                    var ORIGIN_SRC =  $(item).data('src');
                    if(ORIGIN_SRC){
                       $(item).attr({
                           src: ORIGIN_SRC,
                       });
                    }
                });
            }
            
            function handleImgCss(){
               $('#js_content').find('img').each(function (index, item){
                  $(item)
                      .css({
                            width: 'auto',
                            'max-width': '100%',
                            height: 'auto',
                            filter: 'unset',
                            backgound: 'auto',
                            display: 'unset',
                            'padding-bottom': 'unset',
                       })
                       .removeClass('img_loading');
                });
            }
            
            $(function (){
                handleImgCss();
                handleImgSrc();
                
                parent.window.onChildIFreamLoad(); 
            });
    </script>
EOF;

        return $text;
    }

    private function getAllowedHosts(): array
    {
        $configured = env(
            'UEDITOR_WX_CRAWLER_ALLOWED_HOSTS',
            implode(',', self::DEFAULT_ALLOWED_HOSTS)
        );

        $hosts = is_array($configured) ? $configured : explode(',', (string) $configured);
        $hosts = array_values(array_unique(array_filter(array_map(
            static fn($host): string => strtolower(rtrim(trim((string) $host), '.')),
            $hosts
        ))));

        return $hosts ?: self::DEFAULT_ALLOWED_HOSTS;
    }

    private function inspectRemoteUrl(string $url, array $allowedHosts): ?array
    {
        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(rtrim(trim((string)($parts['host'] ?? ''), '[]'), '.'));
        if ($host === '' || !Uploader::isAllowedRemoteDomain($host, $allowedHosts)) {
            return null;
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $port;
        }

        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return array(
            'url' => $scheme . '://' . $authority . ($path === '' ? '/' : $path) . $query . $fragment,
            'host' => $host,
            'port' => $port,
            'is_ip' => filter_var($host, FILTER_VALIDATE_IP) !== false,
        );
    }

    private function resolvePublicIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? array($host) : array();
        }

        $ips = array();
        $recordTypes = defined('DNS_AAAA') ? DNS_A | DNS_AAAA : DNS_A;
        $records = @dns_get_record($host, $recordTypes);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? ($record['ipv6'] ?? ''));
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        $ips = array_values(array_unique($ips));
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return array();
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE
        ) === false) {
            return false;
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        // PHP's global-range flag also accepts multicast addresses.
        if (strlen($binary) === 4) {
            return ord($binary[0]) < 224;
        }

        if (ord($binary[0]) === 0xff) {
            return false;
        }

        // The global-range flag still accepts these non-public IPv6 blocks:
        // fec0::/10 site-local (deprecated by RFC 3879), 2002::/16 6to4 and
        // 64:ff9b::/96 NAT64 (both can embed a private IPv4 address),
        // 3fff::/20 documentation (RFC 9637), 64:ff9b:1::/48 local-use NAT64
        // (RFC 8215) and 5f00::/16 SRv6 SIDs (RFC 9602).
        foreach (array(
            array('fec0::', 10),
            array('2002::', 16),
            array('3fff::', 20),
            array('64:ff9b::', 96),
            array('64:ff9b:1::', 48),
            array('5f00::', 16),
        ) as $range) {
            if ($this->ipInRange($binary, $range[0], $range[1])) {
                return false;
            }
        }

        return true;
    }

    private function ipInRange(string $binary, string $network, int $prefixLength): bool
    {
        $networkBinary = inet_pton($network);
        if ($networkBinary === false || strlen($networkBinary) !== strlen($binary)) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        if ($wholeBytes > 0 && strncmp($binary, $networkBinary, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($binary[$wholeBytes]) & $mask) === (ord($networkBinary[$wholeBytes]) & $mask);
    }

    private function errorResponse(int $status, string $message): string
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        return htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
