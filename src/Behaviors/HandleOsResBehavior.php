<?php

namespace FormItem\Ueditor\Behaviors;

use FormItem\Ueditor\Lib\Helper;

class HandleOsResBehavior
{
    public function run(&$params)
    {
        $file_data = $params['file_data'];
        $custom_param =  $params['param'];
        if ($custom_param['scence'] === 'ueditor'){
            $data = [
                "state" => 'SUCCESS',
                "url" => Helper::parseUrl($file_data["url"] , 0
                    , $custom_param['url_prefix'], $custom_param['url_suffix'], $custom_param),
                "size" => $file_data["size"],
                "title" => htmlspecialchars($file_data["title"]),
                "original" => htmlspecialchars($file_data["original"]),
                "source" => $file_data
            ];

            $params['file_data'] = $data;
            $params['res'] = true;
        }

    }

}