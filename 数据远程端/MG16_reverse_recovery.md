# MG16 Reverse Recovery Notes

## Recovery status

- Runnable packed script was recovered as `D:\hl\ALinRadar\数据远程端\MG16_repacked.sh`.
- ELF payload is `D:\hl\ALinRadar\数据远程端\MG16_unpacked.bin`.
- The payload is a stripped AArch64 ELF. It has no `.symtab`, so exact local function names cannot be recovered from the binary alone.
- The closest matching full native source tree is:
  `D:\hl\ALinRadar\数据远程端\〖源码〗王者S39赛季内核不解密源码`
- The key sharing logic matches the binary:
  `jni\src\Android_draw\DrawPlayer.hpp:2756`

## Reverse evidence

- `gameData` string is at ELF offset/address `0x0bdc5b`.
- The only code reference found for it is around `0x6e8934`.
- The packet is assembled and sent in the block ending near `0x6e8af0`, which calls `sendto`.
- Socket/connect failure strings are referenced around `0x6ba4f8` and `0x6ba560`.
- Dynamic imports include `socket`, `connect`, `sendto`, `pthread_create`, `ioctl`, `dlopen`, `popen`, EGL/GLES/Android surface APIs. This confirms MG16 is a full native overlay plus sharing client, not just a tiny sender.

## Confirmed packet format

The source and binary both point to this legacy format:

```text
gameData<roomId>[==][==]<character>---<creeps>---<soldier>
```

The source line is:

```cpp
gameDataStr =
    "gameData" + 设备id +
    "[==][==]" + character +
    "---" + creeps +
    "---" + soldier;
send(socket_fd, gameData, strlen(gameData), 0);
```

This is equivalent to the server-side room/payload idea:

```text
gameData[roomId][==][==]payload
```

where the room id is directly appended after `gameData`, not separated by its own `[==]` in this source generation.

## Data collection flow

1. Process/module setup
   - Target process: `com.tencent.tmgp.sgame`
   - Resolve `libGameCore.so:bss`
   - Resolve `libil2cpp.so:bss`

2. Game state check
   - `libGameCore.so:bss + 0x191E7C`
   - If zero, skip frame / not in game.

3. Matrix and camp
   - `libil2cpp.so:bss + 0x43F600`
   - Chain: `+0xB8 -> +0x0 -> +0x10 -> +0x128`
   - Matrix first float decides camp/orientation.

4. Heroes
   - Root: `libGameCore.so:bss + 0x2540`
   - Loop 10/20 entries depending mode.
   - Collect hero id, camp, HP/max HP, map coordinate, skill cooldowns, summoner skill/id, vision/control state.
   - Append entries into `character`, separated by `==`.

5. Monsters
   - Root: `libGameCore.so:bss + 0x1DA0`
   - Chain: `+0x3B0 -> +0x88 -> +0x120`
   - Smite damage: `libil2cpp.so:bss + 0x55EEA8 -> +0xB8 -> +0x0 -> +0x20 -> +0x28 -> +0x1F8`
   - Append entries into `creeps`, separated by `==`.

6. Minions
   - Root: `libGameCore.so:bss + 0x161910`
   - Chain: `+0x138 -> +0x108`
   - Loop up to 50 entries.
   - Append entries into `soldier`, separated by `==`.

7. Send
   - Socket is created in `include.h:createSocket()`.
   - Older/source config shows hardcoded host/port; your MG16 build likely changed these at pack time.
   - `MG16_share_core_recovered.cpp` keeps host/port as arguments so it can match either `103.91.210.141:55555`, `192.140.166.49:85`, or a `8888` bridge setup.

## What is still not exact

- Exact original comments, UI layout code, and obfuscation/build wrapper cannot be reconstructed from the stripped ELF byte-for-byte.
- The full native source tree above is the nearest full source base and contains the real sharing code path.
- `MG16_share_core_recovered.cpp` is the extracted sharing core, not the whole overlay program.
