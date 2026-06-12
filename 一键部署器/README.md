# 一键远程部署器

通过网页表单填写 SSH 账号密码，自动部署“纯净版 / 卡密版 / 运营版”。纯净版和卡密版会把本地 `网页源码` 上传到目标服务器；运营版会在目标服务器中优先拉取 GitHub 源码，网络不好时自动切换 Gitee。

## ✨ 它做了什么

访客在网页上填写：服务器 IP、SSH 端口（默认 22）、用户名、密码。后端通过 SSH 在目标服务器上：

1. 自动识别发行版（Debian/Ubuntu 或 CentOS/Rocky/Alma）
2. 自动安装 Java 8（若未安装）
3. 自动安装并启动 Nginx（若未安装）
4. 通过 SFTP 把源码上传到 `/www/wwwroot/<IP>/`
5. 改写 `.user.ini` 中的 `open_basedir` 为实际路径
6. 写入 Nginx 站点配置，监听 **85**（默认，可在页面修改）
7. 注册 `radar-java.service` systemd 服务并启动（监听 **8888 / 9999**）
8. 自动放行 `firewalld / ufw / iptables` 的 `85 / 8888 / 9999` 端口
9. 做一轮健康检查，给出可点击的访问链接
10. 部署成功后把 SSH、数据库、后台和 APP 路径记录到 `http://部署器域名/admin`
11. 部署卡密只在部署成功后失效，失败或取消不会消耗

整个过程通过 WebSocket 把实时日志推送到浏览器。

> ⚠️ 云服务商的"安全组"需要在控制台里额外放行 `85 / 8888 / 9999`，SSH 自动化改不了安全组。

## 📁 目录结构

```
S43雷达共享源码纯净版/
├── 网页源码/                    ← 要被部署的源码（会自动上传）
│   ├── home-server-0.0.1-SNAPSHOT.jar
│   ├── index.html
│   ├── .user.ini
│   ├── layui/
│   └── map.png
└── 一键部署器/                  ← 本工具
    ├── server.js                 后端入口（Express + Socket.IO）
    ├── deployer.js               SSH/SFTP 部署逻辑
    ├── package.json
    ├── public/                   前端
    │   ├── index.html
    │   ├── style.css
    │   └── app.js
    └── README.md
```

## 🚀 本地试跑

需要 **Node.js 18+**。

```bash
cd 一键部署器
npm install
npm start
```

浏览器打开 <http://localhost:3000> 即可。

若你想把"源码目录"放到别处，设置环境变量：

```bash
# PowerShell
$env:PAYLOAD_DIR = "D:\some\other\path\网页源码"
npm start

# Linux / macOS
PAYLOAD_DIR=/path/to/网页源码 npm start
```

## 🌐 发布到公网给别人用

把整个工作区（**两个文件夹一起**）上传到一台云服务器，例如 `/opt/radar-deployer`：

```
/opt/radar-deployer/
├── 网页源码/
└── 一键部署器/
```

### 1. 安装依赖

```bash
cd /opt/radar-deployer/一键部署器
npm install --production
```

### 2. 用 systemd 常驻

新建 `/etc/systemd/system/radar-deployer.service`：

```ini
[Unit]
Description=Radar One-Click Deployer
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/radar-deployer/一键部署器
ExecStart=/usr/bin/node server.js
Environment=PORT=3000
Environment=HOST=0.0.0.0
Environment=ADMIN_PASSWORD=请改成强后台密码
# 可选：运营版安装授权码。配置后页面可不再手填安装授权码。
# Environment=OPS_INSTALL_CODE=请改成你的安装授权码
# 如果部署器前面有可信 Nginx/HTTPS 反代，并需要按真实访客 IP 做登录限制，打开：
# Environment=TRUST_PROXY=true
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now radar-deployer
```

### 3. 用 Nginx 反代 + HTTPS（推荐）

```nginx
server {
    listen 443 ssl http2;
    server_name deploy.example.com;

    ssl_certificate     /etc/letsencrypt/live/deploy.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/deploy.example.com/privkey.pem;

    client_max_body_size 50m;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # WebSocket（Socket.IO）
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }
}
```

### 4. 放行端口

部署器自身监听 `3000`。公网访问请根据实际情况用反代（443）或直接开 3000。

## 🔐 安全说明

- **成功记录会落盘**：按业务要求，部署成功后会把 SSH、数据库、后台账号保存到部署器服务器的 `data/deploy-records.json`，并通过 `/admin` 后台查看。这个文件已经加入 `.gitignore`，不会上传到公开仓库。
- **后台统一管理**：`/admin` 使用 `ADMIN_USERNAME` / `ADMIN_PASSWORD` 登录，可生成一次性部署卡密，也可查看已部署成功的信息。
- **后台登录限制**：默认同一来源/账号 15 分钟内失败 10 次会临时拒绝，可用 `ADMIN_LOGIN_WINDOW_MS` / `ADMIN_LOGIN_MAX_FAILURES` 调整。
- **反代真实 IP**：默认不信任客户端传入的 `X-Forwarded-For`；只有部署在可信反代后面时才设置 `TRUST_PROXY=true`。
- **一次性部署卡密**：前台部署必须填写后台生成的部署卡密；部署成功后卡密自动失效，部署失败或取消会释放卡密。
- **建议启用 HTTPS**：通过反代 + Let's Encrypt 提供 HTTPS，避免密码在传输层被嗅探。
- **最小权限**：建议用户使用一个仅限本次部署的 SSH 账号或临时密码，用完改掉。
- **速率限制**：如对外开放，可考虑在反代层加 IP 级速率限制。
- **sudo 密码注入**：若用户非 root，部署器会把密码通过 `sudo -S` 临时注入，仅存在内存中。

## 🛠️ 目标服务器的要求

- 干净或接近干净的 CentOS 7+/Rocky 8+/AlmaLinux 8+/Ubuntu 18.04+/Debian 10+
- 能访问公网（`apt-get / yum / dnf` 能拉包）
- 使用 **root** 或 **具备免密 sudo 能力**（或知道密码）的账号
- 已开放 `22` 供 SSH 连接
- 云厂商安全组已放行 `85 / 8888 / 9999`

## 🧪 部署器执行的关键远程命令（速查）

| 步骤 | 命令片段 |
|---|---|
| 识别系统 | `cat /etc/os-release`，命中 `debian\|ubuntu` 用 `apt`，命中 `centos\|rhel\|rocky\|alma` 用 `dnf`/`yum` |
| 装 Java | `apt-get install -y openjdk-8-jre-headless` 或 `yum install -y java-1.8.0-openjdk-headless` |
| 装 Nginx | `apt-get install -y nginx` 或 `yum install -y nginx` |
| 建站点目录 | `mkdir -p /www/wwwroot/<host>`，`mkdir -p /www/server/radar-java` |
| 改 `.user.ini` | `echo 'open_basedir=/www/wwwroot/<host>/:/tmp/' > /www/wwwroot/<host>/.user.ini` |
| Nginx 配置 | `/etc/nginx/conf.d/radar_<host>.conf`（RHEL）或 `/etc/nginx/sites-available/radar_<host>.conf`（Debian） |
| Java 常驻 | `/etc/systemd/system/radar-java.service` → `systemctl restart radar-java.service` |
| 防火墙 | `firewall-cmd --permanent --add-port=85/tcp` / `ufw allow 85/tcp` / `iptables -I INPUT -p tcp --dport 85 -j ACCEPT`，同时放行 `8888 / 9999` |

## 🧾 故障排查

**页面打不开但部署器显示"部署完成"**

1. 登录云厂商控制台检查"安全组"是否放行 `85 / 8888 / 9999`。
2. 在服务器本地 `curl http://127.0.0.1:85/`，能通则说明只是外层安全组没开。
3. 查看 Java 日志：`journalctl -u radar-java.service -n 100 --no-pager`。

**Java 启动失败**

- 端口被占用：`ss -lntp | grep -E ':8888|:9999'`
- JDK 版本问题：`java -version`，确认是 8+
- 内存不足：`free -m`，jar 建议至少 1 GB 内存

**SFTP 上传失败**

- 目标路径权限：`ls -ld /www/wwwroot/`
- 磁盘空间：`df -h`

**Nginx 启动失败**

- 端口冲突：`ss -lntp | grep -E ':85|:9999'`
- 配置语法：`nginx -t`

## 🔧 可定制项

- `server.js` 顶部：`PORT` / `HOST` / `PAYLOAD_DIR`
- `deployer.js` 中 `buildNginxConf()`：可自定义 Nginx 配置模板
- `deployer.js` 中 `buildSystemdUnit()`：可自定义 Java 启动参数（比如 `-Xmx512m`、其他 `--server.port=xxxx`）

## 📜 许可

MIT

## 版本选择说明

`网页源码` 目录需要同时包含两个版本目录：

```text
网页源码/
├── 纯净版/
│   ├── index.html
│   └── wz.jar
└── 卡密版/
    ├── index.html
    ├── wz.jar
    ├── auth_config.php
    ├── api/
    ├── admin/
    └── data/
```

部署页面里可选择“纯净版”“卡密版”或“运营版”：

- 纯净版：上传本地纯净版源码，不配置后台和数据库。
- 卡密版：上传本地卡密版源码，需要填写后台管理密码，部署器会自动安装 PHP-FPM、配置 Nginx 执行 PHP，并把该密码写入远程 `auth_config.php`。
- 运营版：目标服务器优先从 GitHub 拉取 `wzry-space`，失败后自动切换 Gitee；需要填写安装授权码、后台账号和数据库密码，也可以留空让远程脚本自动生成。

部署成功后访问 `/admin` 可查看成功记录；运营版还会读取目标服务器 `/root/wzry-space-install.env`，把自动生成的后台密码和数据库密码同步进后台记录页。
