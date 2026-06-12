// MG16 share-side core recovered from MG16_unpacked.bin and the matching
// DrawPlayer.hpp source fragment.
//
// This is not the full overlay/UI program. It is the standalone data-sharing
// core: connect to the room server, collect a frame, pack the legacy payload,
// and send "gameData<room>[==][==]<heroes>---<monsters>---<minions>".

#include <arpa/inet.h>
#include <netinet/in.h>
#include <sys/socket.h>
#include <unistd.h>

#include <cerrno>
#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <string>
#include <thread>
#include <vector>

struct HeroPacket {
    int heroId{};
    int hp{};
    int maxHp{};
    int mapX{};
    int mapY{};
    int camp{};
    int skill1Cd{};
    int skill2Cd{};
    int skill3Cd{};
    int summonerCd{};
    int summonerSkillId{};
    int vision{};
    int controlState{};
};

struct MonsterPacket {
    int type{};       // old source used 0 here
    int respawnCd{};
    int monsterId{};
    int mapX{};
    int mapY{};
    int hp{};
    int maxHp{};
    int smiteDamage{};
};

struct MinionPacket {
    int mapX{};
    int mapY{};
    int tag{};        // old source appended temp here
};

struct GameFrame {
    std::vector<HeroPacket> heroes;
    std::vector<MonsterPacket> monsters;
    std::vector<MinionPacket> minions;
};

static std::string makeRoomId() {
    std::srand(static_cast<unsigned>(std::time(nullptr)));
    char id[16]{};
    std::snprintf(id, sizeof(id), "%06d", std::rand() % 1000 + 1);
    return id;
}

static int connectRoomServer(const std::string& host, int port) {
    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) {
        std::perror("socket");
        return -1;
    }

    sockaddr_in addr{};
    addr.sin_family = AF_INET;
    addr.sin_port = htons(static_cast<uint16_t>(port));
    addr.sin_addr.s_addr = inet_addr(host.c_str());

    if (connect(fd, reinterpret_cast<sockaddr*>(&addr), sizeof(addr)) < 0) {
        std::perror("connect");
        close(fd);
        return -1;
    }
    return fd;
}

static std::string joinHeroes(const std::vector<HeroPacket>& heroes) {
    std::string out;
    for (const auto& h : heroes) {
        out += std::to_string(h.heroId) + "," +
               std::to_string(h.hp) + "," +
               std::to_string(h.maxHp) + "," +
               std::to_string(h.mapX) + "," +
               std::to_string(h.mapY) + "," +
               std::to_string(h.camp) + "," +
               std::to_string(h.skill1Cd) + "," +
               std::to_string(h.skill2Cd) + "," +
               std::to_string(h.skill3Cd) + "," +
               std::to_string(h.summonerCd) + "," +
               std::to_string(h.summonerSkillId) + "," +
               std::to_string(h.vision) + "," +
               std::to_string(h.controlState) + "==";
    }
    return out;
}

static std::string joinMonsters(const std::vector<MonsterPacket>& monsters) {
    std::string out;
    for (const auto& m : monsters) {
        out += std::to_string(m.type) + "," +
               std::to_string(m.respawnCd) + "," +
               std::to_string(m.monsterId) + "," +
               std::to_string(m.mapX) + "," +
               std::to_string(m.mapY) + "," +
               std::to_string(m.hp) + "," +
               std::to_string(m.maxHp) + "," +
               std::to_string(m.smiteDamage) + "==";
    }
    return out;
}

static std::string joinMinions(const std::vector<MinionPacket>& minions) {
    std::string out;
    for (const auto& s : minions) {
        out += std::to_string(s.mapX) + "," +
               std::to_string(s.mapY) + "," +
               std::to_string(s.tag) + "==";
    }
    return out;
}

static std::string packGameData(const std::string& roomId, const GameFrame& frame) {
    return "gameData" + roomId +
           "[==][==]" + joinHeroes(frame.heroes) +
           "---" + joinMonsters(frame.monsters) +
           "---" + joinMinions(frame.minions);
}

// Reverse-mapped memory offsets from MG16/DrawPlayer.hpp.
// libGameCore.so:bss + 0x191E7C: in-game check
// libil2cpp.so:bss + 0x43F600: matrix chain
// libGameCore.so:bss + 0x2540: hero list root
// libGameCore.so:bss + 0x1DA0: monster list root
// libGameCore.so:bss + 0x161910: minion list root
// libil2cpp.so:bss + 0x55EEA8: smite damage chain
static GameFrame readFrameRecoveredStub() {
    // Fill this with the existing driver-backed reads from:
    // D:\hl\ALinRadar\数据远程端\〖源码〗王者S39赛季内核不解密源码\jni\src\Android_draw\DrawPlayer.hpp
    //
    // The original binary's data path is not symbolic, but the above offsets
    // and packet fields match the stripped ELF string/call references.
    return {};
}

int main(int argc, char** argv) {
    const std::string host = argc > 1 ? argv[1] : "103.91.210.141";
    const int port = argc > 2 ? std::atoi(argv[2]) : 55555;
    const std::string roomId = argc > 3 ? argv[3] : makeRoomId();

    int fd = connectRoomServer(host, port);
    if (fd < 0) return 1;

    std::printf("[MG16 recovered] connected %s:%d room=%s\n",
                host.c_str(), port, roomId.c_str());

    while (true) {
        GameFrame frame = readFrameRecoveredStub();
        std::string packet = packGameData(roomId, frame);
        send(fd, packet.data(), packet.size(), 0);
        std::this_thread::sleep_for(std::chrono::milliseconds(20));
    }
}
