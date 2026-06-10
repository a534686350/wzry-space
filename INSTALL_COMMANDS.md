# SSH 一键命令

下面命令直接复制到云服务器 SSH 窗口执行即可。请先使用 `root` 登录，或执行 `sudo -i` 切换到 root。

## Gitee 一键搭建

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh
```

## GitHub 一键搭建

```bash
curl -fsSL https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-install.sh -o /tmp/wzry-install.sh && bash /tmp/wzry-install.sh --source github
```

## Gitee 远程更新源码

```bash
curl -fsSL https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh && bash /tmp/wzry-update.sh
```

## GitHub 远程更新源码

```bash
curl -fsSL https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-update.sh -o /tmp/wzry-update.sh && bash /tmp/wzry-update.sh --source github
```

安装过程中会提示输入授权码。授权码明文不要提交到公开仓库。
