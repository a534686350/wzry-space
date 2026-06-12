# MG16 recovered source modify guide

## Current editable source

Closest editable source tree:

`D:\hl\ALinRadar\数据远程端\〖源码〗王者S39赛季内核不解密源码`

This tree is not proven byte-identical to `MG无解密第十六版.sh`, but it matches the important share/data behavior found in the unpacked binary:

- packet prefix: `gameData`
- packet separator: `[==][==]`
- section separator: `---`
- final send path: `DrawPlayer.hpp` builds hero/creep/minion data and sends it through `socket_fd`

## Runtime flow

1. `jni/src/main.cpp`
   - initializes screen, EGL, ImGui, touch
   - enters the main loop:
     - `drawBegin()`
     - `tick()`
     - `drawEnd()`

2. `jni/src/Android_draw/draw.cpp`
   - menu button toggles `huitu = 1`
   - when enabled, `tick()` calls `DrawPlayer()`

3. `jni/src/Android_draw/DrawPlayer.hpp`
   - `DrawInit()` resolves module bases:
     - `libGameCore.so:bss`
     - `libil2cpp.so:bss`
     - `libtersafe.so`
   - `DrawPlayer()` reads matrix/game objects, draws ESP, and builds shared packet data.

4. `jni/src/Android_draw/include.h`
   - owns the TCP socket.
   - `createSocket()` connects to the share/data server.
   - `mgSendShareData()` sends data and retries once after disconnect.

## What to modify

### Server IP/port

Edit:

`jni/src/Android_draw/MGConfig.h`

```cpp
#define MG_SHARE_HOST "192.140.166.49"
#define MG_SHARE_PORT 8888
```

### Packet format

Edit:

`jni/src/Android_draw/MGConfig.h`

```cpp
#define MG_PACKET_PREFIX "gameData"
#define MG_PACKET_ROOM_SEPARATOR "[==][==]"
#define MG_PACKET_SECTION_SEPARATOR "---"
```

Final packet is built in:

`jni/src/Android_draw/DrawPlayer.hpp`

```cpp
MG_PACKET_PREFIX + device_id
MG_PACKET_ROOM_SEPARATOR + character
MG_PACKET_SECTION_SEPARATOR + creeps
MG_PACKET_SECTION_SEPARATOR + soldier
```

### Offsets

Main offsets are in:

`jni/src/Android_draw/DrawPlayer.hpp`

Known important lines/patterns:

- matrix: `lil2cpp_base + 0x43F600`
- in-game check: `libGame_base + 0x191E7C`
- heroes: `libGame_base + 0x2540`
- monsters: `libGame_base + 0x1DA0`
- smite: `lil2cpp_base + 0x55EEA8`
- minions: `libGame_base + 0x161910`

### Share send point

Edit:

`jni/src/Android_draw/DrawPlayer.hpp`

Search:

```cpp
mgSendShareData(gameData, strlen(gameData));
```

## Fixes restored during reverse work

- Added `jni/src/Android_draw/MGConfig.h` to centralize host/port/packet constants.
- Changed old hardcoded `121.37.1.106:9999` to `MG_SHARE_HOST:MG_SHARE_PORT`.
- Added `mgSendShareData()` with one reconnect retry.
- Fixed `lil2cpp_bss` initialization so `DrawInit()` can pass.
- Added a guard so `DrawPlayer()` calls `DrawInit()` before reading game memory.

## Build command

From PowerShell:

```powershell
& "C:\Users\Administrator\AppData\Local\Android\Sdk\ndk\25.2.9519653\ndk-build.cmd" `
  -C "D:\hl\ALinRadar\数据远程端\〖源码〗王者S39赛季内核不解密源码\jni" `
  NDK_OUT="D:\hl\ALinRadar\build\mg16_obj" `
  NDK_LIBS_OUT="D:\hl\ALinRadar\build\mg16_libs"
```

Expected output executable:

`D:\hl\ALinRadar\build\mg16_libs\arm64-v8a\1.sh`

The custom `NDK_OUT` and `NDK_LIBS_OUT` paths avoid Windows/NDK encoding problems caused by Chinese characters in the source path.

## Repacked script

Current repacked script generated from the rebuilt ELF:

`D:\hl\ALinRadar\数据远程端\MG16_modified_from_source.sh`

Validation:

- shell header: 43 lines
- gzip payload starts at line 44, matching `tail +44`
- unpacked payload SHA256 equals rebuilt `1.sh`
