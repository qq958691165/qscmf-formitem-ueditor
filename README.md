# quansitech/qscmf-formitem-ueditor

```text
qscmf 表单组件--ueditor

富文本组件
```

#### 安装

```php
composer require quansitech/qscmf-formitem-ueditor
```

#### [升级指南](Upgrade.md)


#### 用法
+ 简单用法
  ```php
  ->addFormItem('desc', 'ueditor', '商家简介')
  ```

+ 设置上传文件（或抓取远程图）的url前缀，和url后缀
  ```php
  //addFormItem第七个参数，传递指定的上传处理地址，加上url_prefix参数和url_suffix
  //拼接出的url结果： url_prefix . url原来的相对路径. url_suffix
  ->addFormItem('desc', 'ueditor', '商家简介', '', '', '', 'data-url="/extends/ueditor/index?url_prefix=prefix地址&url_suffix=后缀"')
  
  //场景举例：
  //某些管理员在上传富文本图片时，会上传一张非常大的图片，这样会导致用户访问该页面异常缓慢
  //这时可以利用url_prefix配合imageproxy做到自动降低图片大小，降低图片占用的网络带宽
  
  $url_prefix = U('/ip/q90', '', false, true) . '/' . U('/', '', false, true);
  //url_prefix = http://域名/ip/q90/http://域名/图片地址
  ->addFormItem('desc', 'ueditor', '商家简介', '', '', '', 'data-url="/extends/ueditor/index?url_prefix=' . $url_prefix . '"')
  
  // 使用CDN
  $domain = HTTP_PROTOCOL . "://" . SITE_URL;
  $url_prefix = injecCdntUrl();

  ->addFormItem("content", "ueditor", "内容", "", "", "", "data-url=$domain/extends/ueditor/index?type=editor&url_prefix=$url_prefix data-forcecatchremote='true'")
  ```
  
+ insertframe: 默认启用。用于插入```<iframe></iframe>```或```url```，可以编辑宽高，边框，是否允许滚动,对齐方式等属性,其他属性会被删除。

+ insert_richtext: 默认启用。通过```微信公众号url```，可以抓取微信公众号的文章内容以及图片

+ [自定义上传文件至不同云服务商功能](https://github.com/quansitech/qscmf-formitem-object-storage/blob/main/README.md#%E4%BD%BF%E7%94%A8)

+ 通过forcecatchremote属性设置是否强制要求抓取外链图片至本地，该属性默认为true。
  ```blade
  复制外链文章时，会抓取外链图片至本地。若该属性为true，则未抓取完会显示loadding图片且不能保存；若该属性为false，如果未等全部抓取完就保存，此时图片还是外链。
  ```
  ```php
  //addFormItem第七个参数，设置data-forcecatchremote="true"
  ->addFormItem('desc', 'ueditor', '商家简介', '', '', '', 'data-forcecatchremote="true"')
  ```

+ 重新指定UE的JS CONFIG文件的路径
  ```php
  //在Common/Conf/config.php中新增配置值
  'CUSTOM_UEDITOR_JS_CONFIG' => __ROOT__ . '/Public/static/ueditor.config.js'  //注意必须加上__ROOT__，为了兼容根目录是网站子路径的情况
  ```

+ 设置ue的option参数
  ```php
  //如：想通过form.options来配置ue的toolbars参数
  //组件会自动完成php数组--》js json对象的转换，并传入ue中
  ->addFormItem('content', 'ueditor', '内容', '', ['toolbars' => [['attachment']]])
  ```

+ 自定义UE色板
  ```php
  全局配置
  1.先COPY ueditor.config.js 文件到项目路径，重新指定JS CONFIG路径
  2.修改ueditor.config.js 的customColors配置项，第一行10色块为主题色块， 最后一行10色块为标准色块，可按照需要自行增删改里面的色值。
  
  
  局部配置
  1. 在Formbuilder设置formItem时，可传递customColors的设置，详细方法查看“设置ue的option参数”
  ```

+ 自定义上传config设置
  
  ```blade
  在app/Common/Conf 下新增ueditor_config.json或者ueditor_config.php(返回数组)，该文件将会替换掉默认的config.json。如有客制化config.json的需求，定制该文件即可。
  ```

+ 自定义微信文章获取前缀设置

  ```dotenv
  # 在项目.env文件中添加如下配置
  UEDITOR_WX_CRAWLER_PREFIX=https://【域名】/?url=
  ```

  