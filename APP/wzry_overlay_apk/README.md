# 共享视野悬浮窗 APK

这是一个干净的 Android 悬浮窗客户端工程。

## 功能

- 输入网站地址，例如 `http://101.200.36.103`
- 输入房间号，例如 `000508`
- 启动悬浮窗后，在悬浮 WebView 中打开网站雷达页
- 自动把房间号填入网页并连接
- 顶部栏可拖动悬浮窗，右侧按钮关闭

## 打包

用 Android Studio 打开本目录：

```text
wzry_overlay_apk
```

然后执行：

```bash
./gradlew assembleDebug
```

生成文件一般在：

```text
app/build/outputs/apk/debug/app-debug.apk
```

## 手机权限

首次启动需要授权：

- 悬浮窗权限
- 通知权限，Android 13+
- 网络权限

## 服务端要求

网站页面能打开，并且你的 `home-server-0.0.1-SNAPSHOT.jar` 在服务器运行，`8888` 端口已放行：

```bash
java -jar home-server-0.0.1-SNAPSHOT.jar
```

WebSocket 地址应能访问：

```text
ws://服务器IP:8888/ws
```
