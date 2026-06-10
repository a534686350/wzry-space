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

注意：当前 GitHub/Gitee 仓库是私有仓库。私有仓库的 raw 文件通常不能被 APP 用户匿名下载。正式使用 GitHub/Gitee 做更新下载时，有两种选择：

1. 将仓库改为公开仓库。
2. 新建只放 APK 的公开发布仓库，把后台里的 GitHub/Gitee APK 地址改成公开仓库的 raw 地址。

## 发新版流程

1. 修改 `APP/wzry_overlay_apk/app/build.gradle` 的 `versionCode` 和 `versionName`。
2. 如需同步默认远程配置，更新 `APP/api/core.php`、`网页前后台/api/core.php` 以及对应 SQL 文件里的版本号和 APK 地址。
3. 运行打包并发布到双远端：

```powershell
powershell -ExecutionPolicy Bypass -File ".\sync-remotes.ps1" -BuildApk -CommitMessage "Release v6.1.12"
```

脚本会：

- 构建 release APK。
- 复制 APK 到 `网页前后台/apk/`。
- 提交本地改动。
- 推送到 Gitee。
- 推送到 GitHub；如果普通 push 失败，自动使用 GitHub API 镜像本地内容。

如果已经手动提交，只同步远端：

```powershell
powershell -ExecutionPolicy Bypass -File ".\sync-remotes.ps1"
```
