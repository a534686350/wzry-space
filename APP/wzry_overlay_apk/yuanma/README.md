# ALin雷达 APK 远程管理后台

上传 `yuanma` 文件夹到网站根目录，访问：

`http://你的服务器IP或域名/yuanma/admin.php`

默认后台密码在 `config.php`：

`admin123456`

请上传后先修改密码。

APK 启动时会自动请求：

`http://你的服务器IP或域名/yuanma/api.php?action=config`

后台可以配置：

- 新版本检测：`version_code` 大于 APK 当前版本时提示更新
- APK 下载地址
- 是否强制更新
- 打开 APP 的弹窗公告
- 弹窗按钮链接，比如加群链接
- 领取卡密、购买卡密、下载客户端链接
