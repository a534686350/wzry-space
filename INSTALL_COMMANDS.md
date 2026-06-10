# SSH 一键命令

下面命令直接复制到云服务器 SSH 窗口执行即可。请先使用 `root` 登录，或执行 `sudo -i` 切换到 root。

## 智能一键搭建

```bash
SRC=github; (curl -fsSL --connect-timeout 8 --max-time 25 https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh || { SRC=gitee; curl -fsSL --connect-timeout 8 --max-time 25 https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh; }) && bash /tmp/wzry-install.sh --source "$SRC"
```

这条命令会先尝试 GitHub；GitHub 网络不好时自动切换到 Gitee。

## 智能远程更新源码

```bash
SRC=github; (curl -fsSL --connect-timeout 8 --max-time 25 https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh || { SRC=gitee; curl -fsSL --connect-timeout 8 --max-time 25 https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh; }) && bash /tmp/wzry-update.sh --source "$SRC"
```

## 单独使用 Gitee

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh
```

## 单独使用 GitHub

```bash
curl -fsSL https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh --source github
```

安装过程中会提示输入授权码。授权码明文不要提交到公开仓库。
