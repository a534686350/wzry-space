# MG16 SH 使用说明

## 这是什么

`MG16_repacked.sh` / `MG16_modified_from_source.sh` / `MG16_restored_from_git.sh`
这几个文件都不是普通的业务脚本。

它们本质上是:

1. 前 43 行: 一个 `sh` 启动壳
2. 第 44 行开始: `gzip` 压缩过的 `arm64` 原生可执行文件

也就是说，这类文件是“自解压启动器”。

执行时流程是:

1. shell 先把第 44 行之后的二进制内容解压到临时目录
2. 给解压出来的文件加执行权限
3. 直接运行这个二进制

所以它虽然叫 `*.sh`，但真正跑起来的核心其实是一个 `arm64` ELF 可执行文件。

## 为什么看起来像要 root

这个项目从源码上看，不是普通用户权限程序，原因很明确:

1. 它会读游戏内存和模块基址
2. 它会访问触摸相关设备
3. 它会创建原生绘制层
4. 它目标进程是 `com.tencent.tmgp.sgame`

源码里的关键点:

- `jni/src/Android_draw/DrawPlayer.hpp`
  这里有大量游戏内存读取、模块偏移、技能/坐标/英雄数据读取逻辑
- `jni/src/Android_draw/draw.cpp`
  这里有触摸、绘制、悬浮显示逻辑
- `jni/src/main.cpp`
  这里会初始化 EGL、ImGui、Touch，然后一直循环绘制

这类程序在绝大多数手机上都需要 `root` 才能正常工作。

另外，部分 ROM 即使 root 了，也可能还需要:

- `SELinux Permissive`
- Magisk / KernelSU 环境
- 允许访问 `/dev/input`
- 允许读取目标进程相关内存

如果没有这些条件，常见结果就是:

- 程序直接闪退
- 画面不显示
- 能显示菜单但读不到数据
- 触摸模拟失效

## 这几个 SH 文件的区别

### `MG16_repacked.sh`

偏向“从原始提取结果重新封装出来的版本”。

### `MG16_restored_from_git.sh`

偏向“从还原内容生成的版本”。

### `MG16_modified_from_source.sh`

偏向“当前源码重新编译后，再重新封装出来的版本”。

如果你现在要自己改源码、自己编译、自己上手机，优先用:

`MG16_modified_from_source.sh`

## SH 外壳前 43 行到底在干什么

它做的事情很简单:

1. 创建临时目录，默认在 `/data/local/tmp/`
2. 用 `tail +44` 取出当前脚本第 44 行之后的内容
3. 用 `gzip -cd` 解压成临时可执行文件
4. `chmod 777` 给执行权限
5. 运行这个临时二进制
6. 稍后自动删掉临时目录

所以这段壳脚本本身一般不用大改。

如果你要改功能，真正应该改的是源码，然后重新编译出新的 `1.sh`，最后再重新封装成这种 `*.sh`。

## 从源码重新编译

源码目录:

`D:\hl\ALinRadar\数据远程端\〖源码〗王者S39赛季内核不解密源码`

编译目标在:

- `jni/Android.mk`
- `jni/Application.mk`

生成的模块名是:

`1.sh`

PowerShell 编译命令:

```powershell
& "C:\Users\Administrator\AppData\Local\Android\Sdk\ndk\25.2.9519653\ndk-build.cmd" `
  -C "D:\hl\ALinRadar\数据远程端\〖源码〗王者S39赛季内核不解密源码\jni" `
  NDK_OUT="D:\hl\ALinRadar\build\mg16_obj" `
  NDK_LIBS_OUT="D:\hl\ALinRadar\build\mg16_libs"
```

编译产物通常在:

`D:\hl\ALinRadar\build\mg16_libs\arm64-v8a\1.sh`

注意:

- 这个 `1.sh` 已经不是 shell 脚本了，而是 ELF 可执行文件
- 外层 `MG16_modified_from_source.sh` 才是“自解压启动壳 + ELF”的完整可执行包

## 手机上怎么执行

假设手机已经 root，并且你想直接运行现成的 `MG16_modified_from_source.sh`。

### 推送文件

```powershell
adb push "D:\hl\ALinRadar\数据远程端\MG16_modified_from_source.sh" /data/local/tmp/mg16.sh
```

### 给权限并执行

```powershell
adb shell su -c "chmod 755 /data/local/tmp/mg16.sh"
adb shell su -c "/data/local/tmp/mg16.sh"
```

如果想后台运行:

```powershell
adb shell su -c "nohup /data/local/tmp/mg16.sh >/data/local/tmp/mg16.log 2>&1 &"
```

看日志:

```powershell
adb shell su -c "tail -n 100 /data/local/tmp/mg16.log"
```

结束进程:

```powershell
adb shell su -c "pkill -f /data/local/tmp/mg16.sh"
```

## 更推荐的执行方式

如果你已经能拿到编译产物 `1.sh`，更推荐直接推送 ELF 本体测试，而不是每次都先套一层外壳。

例如:

```powershell
adb push "D:\hl\ALinRadar\build\mg16_libs\arm64-v8a\1.sh" /data/local/tmp/mg16_bin
adb shell su -c "chmod 755 /data/local/tmp/mg16_bin"
adb shell su -c "/data/local/tmp/mg16_bin"
```

这样调试更直观。

等确认本体没问题，再重新封装成 `MG16_modified_from_source.sh`。

## 什么时候必须重新封装 SH

只有下面两种情况才需要重新封装:

1. 你想保留“单文件一键执行”的形式
2. 对方手机上只方便运行一个 `sh` 文件

如果只是你自己调试，直接运行 `1.sh` 会更方便。

## 当前项目里最应该改的地方

### 服务器地址和端口

文件:

`jni/src/Android_draw/MGConfig.h`

### 发包格式

文件:

`jni/src/Android_draw/MGConfig.h`

### 游戏偏移和采集逻辑

文件:

`jni/src/Android_draw/DrawPlayer.hpp`

### 绘制和触摸行为

文件:

`jni/src/Android_draw/draw.cpp`

## 一个重要提醒

`jni/src/main.cpp` 里有一段:

`/data/local/tmp/temp_script.sh`

当前源码里这个脚本内容实际上没有写进去，基本是空壳逻辑。

所以真正决定程序行为的，不是那个 `temp_script.sh`，而是编译出来的原生二进制本体。

## 结论

这个项目确实基本可以按“root 手机执行”来理解。

最实用的流程是:

1. 改源码
2. 用 `ndk-build` 编出 `1.sh`
3. 先直接推送 `1.sh` 到手机测试
4. 没问题后再用外层 `MG16_modified_from_source.sh` 这种方式封装

