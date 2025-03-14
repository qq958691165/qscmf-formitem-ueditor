<?php

namespace FormItem\Ueditor\Lib\Action;

class WxCrawlerAction extends AAction
{

    protected function fetchWxContent($url): bool|string
    {
        $opts = array(
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [],
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.71 Safari/537.36",
        );

        $opts[CURLOPT_URL] = $url ;

        /* 初始化并执行curl请求 */
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $data  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if($error) {
            E('请求发生错误：' . $error);
        }
        return  $data;
    }

    public function run():string
    {
        $js_url = asset('ueditor/third-party/jquery-1.10.2.min.js');
        $url = $this->get_data['url'];
        $url = urldecode($url);
        $text = $this->fetchWxContent($url);

        $cssToInlineStyles = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
        $text=$cssToInlineStyles->convert($text);
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

}