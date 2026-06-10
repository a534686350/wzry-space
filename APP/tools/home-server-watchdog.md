# 8888 Java 后端自动重启说明

这个守护脚本用于 Ubuntu/宝塔服务器：

- 把 `home-server-0.0.1-SNAPSHOT.jar` 注册成 `systemd` 服务
- 每 30 秒检测一次本机 `127.0.0.1:8888`
- 如果 8888 连不上，自动重启 Java 后端
- 日志写入 `logs/home-server-watchdog.log`

## 安装

上传项目到服务器后，在宝塔终端或 SSH 执行：

```bash
cd /www/wwwroot/wzry
bash tools/install-home-server-watchdog.sh /www/wwwroot/wzry
```

如果提示找不到 `java`，先安装 Java：

```bash
apt update
apt install -y openjdk-17-jre
```

然后重新执行安装命令。

## 查看状态

```bash
systemctl status wzry-home-server.service
systemctl status wzry-home-watchdog.timer
tail -f /www/wwwroot/wzry/logs/home-server-watchdog.log
```

## 手动重启

```bash
systemctl restart wzry-home-server.service
```

## 停止守护

```bash
systemctl disable --now wzry-home-watchdog.timer
systemctl disable --now wzry-home-server.service
```

注意：如果你之前已经用宝塔或命令行手动启动过这个 jar，建议先停掉旧进程，再安装这个服务，避免两个 Java 后端抢占 8888 端口。
