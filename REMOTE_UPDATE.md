# GitHub / Gitee 远程更新

当前仓库：

- GitHub: `https://github.com/a534686350/wzry-space`
- Gitee: `https://gitee.com/hl515/wzry-space`

当前 APK 路径：

```text
网页前后台/apk/ALinRadar-v6.1.11.apk
```

可用于后台 APP 远程管理的直链模板：

```text
https://raw.githubusercontent.com/a534686350/wzry-space/main/%E7%BD%91%E9%A1%B5%E5%89%8D%E5%90%8E%E5%8F%B0/apk/ALinRadar-v6.1.11.apk
https://gitee.com/hl515/wzry-space/raw/main/%E7%BD%91%E9%A1%B5%E5%89%8D%E5%90%8E%E5%8F%B0/apk/ALinRadar-v6.1.11.apk
```

注意：当前 GitHub/Gitee 仓库是私有仓库。私有仓库的 raw 文件普通 APP 用户无法匿名下载。正式使用 GitHub/Gitee 做更新下载时，有两种选择：

1. 将仓库改为公开仓库。
2. 新建只放 APK 的公开发布仓库，把后台里的 GitHub/Gitee APK 地址改成公开仓库的 raw 地址。

发新版流程：

1. 修改 `APP/wzry_overlay_apk/app/build.gradle` 的 `versionCode` 和 `versionName`。
2. 运行 `APP/wzry_overlay_apk/build-and-publish-release.ps1`。
3. 提交并推送到 Gitee/GitHub。
4. 在后台 APP 远程管理里更新版本号、版本名、GitHub APK 地址、Gitee APK 地址。
