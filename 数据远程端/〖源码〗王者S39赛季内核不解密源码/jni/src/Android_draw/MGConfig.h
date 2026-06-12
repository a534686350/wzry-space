#pragma once

// MG share/data endpoint. Change these values before rebuilding.
#define MG_SHARE_HOST "192.140.166.49"
#define MG_SHARE_PORT 8888

// Keep this true for the data remote/share build.
#define MG_SHARE_ENABLED true

// Packet format:
// gameData + device_id + [==][==] + heroes + --- + creeps + --- + soldiers
#define MG_PACKET_PREFIX "gameData"
#define MG_PACKET_ROOM_SEPARATOR "[==][==]"
#define MG_PACKET_SECTION_SEPARATOR "---"

// Socket behavior.
#define MG_SEND_RETRY_ON_FAIL true
