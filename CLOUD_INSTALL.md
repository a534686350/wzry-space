# 云服务器 SSH 一键搭建教程

本文档用于在云服务器上全程通过 SSH 搭建项目。推荐使用全新的 Ubuntu 22.04/24.04、Debian 12、CentOS Stream、Rocky Linux 或 AlmaLinux，并使用 `root` 登录执行。

脚本会做成“向导式安装”，在 SSH 窗口里依次提示：

```text
开始一键搭建，请按提示填写信息
请输入安装授权码
请选择源码下载源
是否需要添加域名
请输入数据库密码
请输入后台用户名和密码
正在远程下载源码
源码下载完成
现在开始搭建前端
前端搭建完成，开始搭建后台
正在搭建 JAVA 端
正在开放 80 / 8888 / 9999 端口
全部搭建成功
```

## 一键搭建命令：Gitee

在云服务器 SSH 窗口执行：

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh
```

脚本启动后会让你输入安装授权码、后台账号、数据库密码等信息。

## 一键搭建命令：GitHub

```bash
curl -fsSL https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh --source github
```

## SSH 远程更新源码

以后发了新版本，服务器 SSH 里执行下面命令即可更新源码、前台、后台、APK、数据库升级 SQL，并重载服务：

Gitee 更新：
```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh && bash /tmp/wzry-update.sh
```

GitHub 更新：

```bash
curl -fsSL https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh && bash /tmp/wzry-update.sh --source github
```

## 安装过程中会问什么

授权码：

```text
请输入安装授权码:
```

仓库里只保存授权码的 SHA256 哈希，不保存明文授权码。需要更换授权码时，在本地或服务器执行：

```bash
printf '%s' '你的新授权码' | sha256sum | awk '{print $1}'
```

然后有两种用法：

```bash
export WZRY_INSTALL_CODE_SHA256='上一步得到的SHA256'
bash /tmp/wzry-install.sh
```

或者把 `scripts/cloud-install.sh` 顶部的 `INSTALL_CODE_SHA256_DEFAULT` 改成新的 SHA256 后提交。

源码源：

```text
请选择源码下载源：github 或 gitee [gitee]:
```

域名：

```text
是否需要添加域名 [y/N]:
```

如果暂时没有域名，直接回车，脚本会使用服务器 IP。

数据库：

```text
请输入 MySQL root 密码（新服务器可直接回车）:
请输入数据库名 [wzry_space]:
请输入数据库用户名 [wzry_space]:
请输入数据库密码（直接回车自动生成）:
```

后台账号：

```text
请输入后台用户名 [admin]:
请输入后台密码（直接回车自动生成）:
```

密码留空时脚本会自动生成，并在最后输出。

## 脚本会自动完成

- 安装 Nginx、PHP-FPM、MariaDB、OpenJDK 17、Git、rsync、ipset、iptables。
- 从 GitHub 或 Gitee 下载源码。
- 只把 `网页前后台/` 发布到 Web 根目录，不把 Android 源码暴露到网站目录。
- 创建数据库、导入数据表、创建后台管理员。
- 生成 `auth/config.php`。
- 配置 Nginx 前台、后台、API 和 8888/9999 WebSocket 代理。
- 安装 `home-server` Java systemd 服务。
- 开放服务器系统端口 `80`、`8888`、`9999`。
- 限制内部 Java 端口 `18888`、`19999` 只允许本机访问。

默认目录：

```text
源码目录：/opt/wzry-space-src
网站目录：/www/wwwroot/wzry-space
安装记录：/root/wzry-space-install.env
```

## 安装完成后会输出

```text
你的前台地址是：    http://服务器IP/
你的后台地址是：    http://服务器IP/admin/
后台用户名是：      admin
后台密码是：        自动生成或你输入的密码
数据库名是：        wzry_space
数据库用户名是：    wzry_space
数据库密码是：      自动生成或你输入的密码
APP 下载路径是：    http://服务器IP/apk/ALinRadar-v6.1.11.apk
WebSocket 端口是：  8888 / 9999
```

## 端口说明

- `80`：前台、后台、API、APK 下载。
- `8888`：对外 WebSocket 端口，Nginx 做访问校验。
- `9999`：对外 WebSocket 端口，Nginx 做访问校验。
- `18888`、`19999`：Java 内部端口，只允许本机访问，不要对公网开放。

脚本会在服务器系统防火墙里处理这些端口。云厂商安全组如果额外拦截，仍需在云厂商控制台放行 `80`、`8888`、`9999`。

## 后续更新

以后推荐执行专用更新命令：

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh && bash /tmp/wzry-update.sh
```

更新脚本会保留：

```text
/www/wwwroot/wzry-space/auth/config.php
```

不会覆盖数据库配置和后台密码。

如果你不是更新，而是想重新安装并强制重建数据库和后台账号，需要重新执行安装脚本并加：

```bash
--reinstall-db
```

例如：

```bash
bash /tmp/wzry-install.sh --source gitee --reinstall-db
```

## 常用 SSH 检查命令

```bash
systemctl status nginx
systemctl status home-server
journalctl -u home-server -f
nginx -t && systemctl reload nginx
ipset list ws_whitelist
```
