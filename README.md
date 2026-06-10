# 王者荣耀空间

王者荣耀雷达运营版项目，包含网页前后台、PHP API、WebSocket 服务部署脚本，以及 Android 悬浮窗 APP 源码。

## 目录

- `网页前后台/`：可部署的网站发布包，包含后台、API、资源和 APK 下载目录。
- `APP/`：开发与部署工作目录，包含 Android 工程和服务端脚本。
- `APP/wzry_overlay_apk/`：Android APP 源码与打包脚本。

## 敏感配置

以下文件不会提交到 Git，请在服务器或本机自行配置：

- `APP/auth/config.php`
- `网页前后台/auth/config.php`
- `APP/wzry_overlay_apk/yuanma/config.php`
- `APP/wzry_overlay_apk/keystore.properties`
- `APP/wzry_overlay_apk/*.keystore`

Android 签名配置请复制 `APP/wzry_overlay_apk/keystore.properties.example` 为 `keystore.properties` 后填写。

## 打包发布 APP

在 Windows PowerShell 中运行：

```powershell
powershell -ExecutionPolicy Bypass -File "APP\wzry_overlay_apk\build-and-publish-release.ps1"
```

脚本会构建 release APK，并复制到：

```text
网页前后台/apk/ALinRadar-v6.1.11.apk
```

## 同步到 GitHub 和 Gitee

当前远程仓库：

- GitHub: `https://github.com/a534686350/wzry-space`
- Gitee: `https://gitee.com/hl515/wzry-space`

普通同步：

```powershell
powershell -ExecutionPolicy Bypass -File ".\sync-remotes.ps1" -CommitMessage "Update release"
```

打包、提交、同步一步完成：

```powershell
powershell -ExecutionPolicy Bypass -File ".\sync-remotes.ps1" -BuildApk -CommitMessage "Release v6.1.12"
```

脚本会先推送 Gitee，再推送 GitHub；如果 GitHub 普通 `git push` 因网络失败，会自动改用 GitHub Git Data API 同步内容。

## 云服务器 SSH 一键搭建

一键安装脚本位于 `scripts/cloud-install.sh`，完整教程见 `CLOUD_INSTALL.md`。

服务器 SSH 里执行后一切按提示填写，包含授权码、域名、数据库密码、后台账号密码：

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh
```

服务器后续 SSH 远程更新源码：

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh && bash /tmp/wzry-update.sh
```

## 远程更新

后台的 APP 远程管理支持配置：

- 主 APK 地址
- GitHub APK 直链
- Gitee APK 直链

APP 会优先尝试主地址，失败后自动切换备用线路。更多说明见 `REMOTE_UPDATE.md`。
