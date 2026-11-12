<?php
/**
 * 根据微信url获取富文本
 * User: viki
 * Date: 21-07-22
 * Time: 下午14:33
 */

require_once __DIR__ . '/Uploader.class.php';

function fetchWxContent(array $target): bool|string
{
    $maxBytes = 5 * 1024 * 1024;
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FAILONERROR => true,
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml'),
        CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.131 Safari/537.36",
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
            if (strlen($chunk) > $maxBytes - strlen($body)) {
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

function getWxCrawlerAllowedHosts(): array
{
    $defaults = array('mp.weixin.qq.com', 'weixin.qq.com');
    $configured = env('UEDITOR_WX_CRAWLER_ALLOWED_HOSTS', implode(',', $defaults));
    $hosts = is_array($configured) ? $configured : explode(',', (string)$configured);
    $hosts = array_values(array_unique(array_filter(array_map(
        static fn($host): string => strtolower(rtrim(trim((string)$host), '.')),
        $hosts
    ))));

    return $hosts ?: $defaults;
}

function inspectWxCrawlerUrl(string $url, array $allowedHosts): ?array
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

function resolveWxCrawlerPublicIps(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return isWxCrawlerPublicIp($host) ? array($host) : array();
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
        if (!isWxCrawlerPublicIp($ip)) {
            return array();
        }
    }

    return $ips;
}

function isWxCrawlerPublicIp(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
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
    // 3fff::/20 documentation (RFC 9637).
    static $deniedRanges = array(
        array('fec0::', 10),
        array('2002::', 16),
        array('3fff::', 20),
        array('64:ff9b::', 96),
        array('64:ff9b:1::', 48),
        array('5f00::', 16),
    );
    foreach ($deniedRanges as $range) {
        if (wxCrawlerIpInRange($binary, $range[0], $range[1])) {
            return false;
        }
    }

    return true;
}

function wxCrawlerIpInRange(string $binary, string $network, int $prefixLength): bool
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

function wxCrawlerErrorResponse(int $status, string $message): string
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    return htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$text = '';
$url = trim(urldecode((string)($_GET['url'] ?? '')));
$allowedHosts = getWxCrawlerAllowedHosts();
$target = inspectWxCrawlerUrl($url, $allowedHosts);
if ($target === null) {
    return wxCrawlerErrorResponse(400, 'invalid url');
}

$prefix = (string)env('UEDITOR_WX_CRAWLER_PREFIX', '');
$requestUrl = $prefix === '' ? $url : $prefix . $url;
if ($requestUrl !== $url) {
    $target = inspectWxCrawlerUrl($requestUrl, $allowedHosts);
    if ($target === null) {
        return wxCrawlerErrorResponse(400, 'invalid url');
    }
}

$target['ips'] = resolveWxCrawlerPublicIps($target['host']);
if ($target['ips'] === []) {
    return wxCrawlerErrorResponse(400, 'invalid url');
}

$text = fetchWxContent($target);
if (!is_string($text) || $text === '') {
    return wxCrawlerErrorResponse(502, 'failed to fetch wx content');
}

try {
    $cssToInlineStyles = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
    $text = $cssToInlineStyles->convert($text);
} catch (\Throwable) {
    return wxCrawlerErrorResponse(502, 'failed to process wx content');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$text .= <<<EOF
    <script type="text/javascript" src="../third-party/jquery-1.10.2.min.js"></script>
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
