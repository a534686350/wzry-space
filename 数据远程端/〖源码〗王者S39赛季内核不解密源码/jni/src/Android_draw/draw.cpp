/*
    By 开源 公益分享
   搜集各个公益文件分享
    更多公益开源 文件 @BYYXnb
*/
#include "draw.h"
#include "2400.h"
#include "DrawPlayer.hpp"
#include "Font.h"
#include "Layout.hpp"
#include "PTlogo.h"
#include "SXlogo.h"
#include "ZYlogo.h"
#include <dirent.h>
#include <fcntl.h>
#include <linux/input.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
std::string loadTextFromFile(const std::string& filename) {
    std::string content;
    std::ifstream file(filename);
    if (file.is_open()) {
        content.assign((std::istreambuf_iterator<char>(file)), std::istreambuf_iterator<char>());
        file.close();
    }
    return content;
}

static bool 触摸驱动 = true;
static bool IsBall = true;

timer FPS限制;

int GetEventCount() {
    DIR *dir = opendir("/dev/input/");
    dirent *ptr = NULL;
    int count = 0;
    while ((ptr = readdir(dir)) != NULL) {
        if (strstr(ptr->d_name, "event"))
            count++;
    }
    return count ? count : -1;
}
void 一加() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 31;
    SmallMapY = 9;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 1527;
    zhsY = 977;
    屏幕x = 1;
    屏幕y= -88;
    读取();
}
void 王者() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 90;
    SmallMapY = 16;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 2000;
    zhsY = 1288;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 荣耀() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 62;
    SmallMapY = 12;
    SmallHPX = 8;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 2000;
    zhsY = 1288;
    屏幕x = 24;
    屏幕y= -192;
    读取();
}
void 神明() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 31;
    SmallMapY = 9;
    SmallHPX = 28;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 1527;
    zhsY = 977;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 无敌() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 90;
    SmallMapY = 16;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 2000;
    zhsY = 1288;
    屏幕x = 0;
    屏幕y= 0;
    jiange =-5;
    读取();
}
void 咳咳() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 159;
    SmallMapY = 19;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 2145;
    zhsY = 1228;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 欧凯() {
    touxiangdaxiao = -2;
    xiaodituxue = -2;
    jiange = 0;
    SmallMapX = 12;
    SmallMapY = 10;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 1493;
    zhsY = 850;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 欧派() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 159;
    SmallMapY = 19;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 2145;
    zhsY = 1228;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 一般() {
    touxiangdaxiao = 0;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = 73;
    SmallMapY = 9;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 1649;
    zhsY = 977;
    屏幕x = 1;
    屏幕y= -88;
    读取();
}
void 胡萝卜() {
    touxiangdaxiao = 0;
    xiaodituxue = -2;
    jiange = 0;
    SmallMapX = 45;
    SmallMapY = 10;
    SmallHPX = -15;
    SmallHPY = 25;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 1500;
    zhsY = 850;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 死了() {
    touxiangdaxiao = 0;
    xiaodituxue = 7;
    jiange = 0;
    SmallMapX = 73;
    SmallMapY = 16;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 0;
    zhsY = 0;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}
void 噢() {
    touxiangdaxiao = -67;
    xiaodituxue = 0;
    jiange = 0;
    SmallMapX = -140;
    SmallMapY = 19;
    SmallHPX = 0;
    SmallHPY = 0;
    jinenglanzX = 0;
    jinenglanzY = 0;
    zhsX = 2341;
    zhsY = 1450;
    屏幕x = 0;
    屏幕y= 0;
    读取();
}


 

void 实体1() {
    顶上技能 = true;
    方框 = true;
    方框技能 = true;
    
    // 当选择实体1时，确保实体2不显示
    顶上技能2 = false;
    方框2 = false;
    方框技能2 = false;
    技能3 = false;
}

void 实体2() {
    顶上技能2 = true;
    方框2 = true;
    方框技能2 = true;
    
    // 当选择实体2时，确保实体1不显示
    顶上技能 = false;
    方框 = false;
    方框技能 = false;
    技能3 = false;
}
void 实体3(){ 
顶上技能 = true;
方框= true;
技能3 = true;
顶上技能2 = false;
方框2 = false;
方框技能2 = false;  
方框技能 = false;
}
 
int 音量() {
    printf("\033[33;1m"); // 黄色
    printf("-------------------------\n");
    printf("\033[36;1m"); // 蓝色
    printf("[-] 音量+键开关悬浮窗\n");
    int EventCount = GetEventCount();
    if (EventCount < 0) {
        printf("\033[31;1m"); // 红色
        printf("[!] No found EventCount\n");
        exit(1);
    }
    int *fdArray = (int *) malloc(EventCount * sizeof(int));
    for (int i = 0; i < EventCount; i++) {
        char temp[128];
        sprintf(temp, "/dev/input/event%d", i);
        fdArray[i] = open(temp, O_RDWR | O_NONBLOCK);
    }
    input_event ev;
    // int count = 0; // 记录按下音量键的次数

    while (!stopFlag) {
        for (int i = 0; i < EventCount; i++) {
            memset(&ev, 0, sizeof(ev));
            read(fdArray[i], &ev, sizeof(ev));
            if (ev.type == EV_KEY && ev.code == KEY_VOLUMEUP && ev.value == 1) {
                // count++;
                printf("\033[36;1m"); // 蓝色
                if (MemuSwitch) {
                    // 这里放悬浮窗开启的函数
                    IsBall = false;
                    //MemuSwitch = false;
                    printf("[-] 悬浮窗已关闭\n\033[1A");
                } else if (!MemuSwitch) {

                    MemuSwitch = true;
                    IsBall = true;
                    printf("[-] 悬浮窗已开启\n\033[1A");
                    // 同上
                    // count = 0;
                }
            }
        }
        usleep(1000 * 200);
    }
    return 0;
}

bool permeate_record = false;
bool g_Initialized = false;
EGLDisplay display = EGL_NO_DISPLAY;
EGLConfig config;
EGLSurface surface = EGL_NO_SURFACE;
EGLContext context = EGL_NO_CONTEXT;
ANativeWindow *native_window;
int screen_x = 0, screen_y = 0;
int abs_ScreenX = 0, abs_ScreenY = 0;
int native_window_screen_x = 0, native_window_screen_y = 0;
android::ANativeWindowCreator::DisplayInfo displayInfo{0};
bool status = false;

int init_egl(int _screen_x, int _screen_y, bool log) {
    FILE *file = fopen("/data/潜心配置/配置/防截屏", "r");
    if (file != NULL) {
        fclose(file);
        status = true;
    } else {
        status = false;
    }

    native_window =
            android::ANativeWindowCreator::Create("念", _screen_x, _screen_y, status);
    ANativeWindow_acquire(native_window);
    // ANativeWindow_acquire(native_window);
    display = eglGetDisplay(EGL_DEFAULT_DISPLAY);
    if (display == EGL_NO_DISPLAY) {
        printf("eglGetDisplay error=%u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("eglGetDisplay ok\n");
    }
    if (eglInitialize(display, 0, 0) != EGL_TRUE) {
        printf("eglInitialize error=%u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("eglInitialize ok\n");
    }
    EGLint num_config = 0;
    const EGLint attribList[] = {EGL_SURFACE_TYPE,
                                 EGL_WINDOW_BIT,
                                 EGL_RENDERABLE_TYPE,
                                 EGL_OPENGL_ES2_BIT,
                                 EGL_BLUE_SIZE,
                                 5, //-->delete
                                 EGL_GREEN_SIZE,
                                 6, //-->delete
                                 EGL_RED_SIZE,
                                 5, //-->delete
                                 EGL_BUFFER_SIZE,
                                 32, //-->new field
                                 EGL_DEPTH_SIZE,
                                 16,
                                 EGL_STENCIL_SIZE,
                                 8,
                                 EGL_NONE};
    if (eglChooseConfig(display, attribList, nullptr, 0, &num_config) !=
        EGL_TRUE) {
        printf("eglChooseConfig  error=%u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("num_config=%d\n", num_config);
    }
    if (!eglChooseConfig(display, attribList, &config, 1, &num_config)) {
        printf("eglChooseConfig  error=%u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("eglChooseConfig ok\n");
    }
    EGLint egl_format;
    eglGetConfigAttrib(display, config, EGL_NATIVE_VISUAL_ID, &egl_format);
    ANativeWindow_setBuffersGeometry(native_window, 0, 0, egl_format);
    const EGLint attrib_list[] = {EGL_CONTEXT_CLIENT_VERSION, 3, EGL_NONE};
    context = eglCreateContext(display, config, EGL_NO_CONTEXT, attrib_list);
    if (context == EGL_NO_CONTEXT) {
        printf("eglCreateContext  error = %u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("eglCreateContext ok\n");
    }
    surface = eglCreateWindowSurface(display, config, native_window, nullptr);
    if (surface == EGL_NO_SURFACE) {
        printf("eglCreateWindowSurface  error = %u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("eglCreateWindowSurface ok\n");
    }
    if (!eglMakeCurrent(display, surface, surface, context)) {
        printf("eglMakeCurrent  error = %u\n", glGetError());
        return -1;
    }
    if (log) {
        printf("eglMakeCurrent ok\n");
    }
    return 1;
}


char *读取文件(char *path) {
    FILE *fp;
    if ((fp = fopen(path, "r")) == NULL) {
        return NULL;
    }
    fseek(fp, 0, SEEK_END);
    int filesize = ftell(fp);
    char *str;
    str = (char *) malloc(filesize);
    rewind(fp);
    fread(str, 1, filesize, fp);
    str[filesize] = '\0';
    fclose(fp);
    return str;
}

int 读取() {
    char *ndhdh = 读取文件("/data/潜心配置/配置/头像X");
    if (ndhdh) {
        SmallMapX = atof(ndhdh);
    }

    char *ndhdh2 = 读取文件("/data/潜心配置/配置/头像Y");
    if (ndhdh2) {
        SmallMapY = atof(ndhdh2);
    }

    char *ndhdh3 = 读取文件("/data/潜心配置/配置/实体X");
    if (ndhdh3) {
        SmallHPX = atof(ndhdh3);
    }

    char *ndhdh5 = 读取文件("/data/潜心配置/配置/实体Y");
    if (ndhdh5) {
        SmallHPY = atof(ndhdh5);
    }

    char *ndhdh6 = 读取文件("/data/潜心配置/配置/头像大小");
    if (ndhdh6) {
        touxiangdaxiao = atof(ndhdh6);
    }

    char *ndhdh7 = 读取文件("/data/潜心配置/配置/血量大小");
    if (ndhdh7) {
        xiaodituxue = atof(ndhdh7);
    }

    char *ndhdh8 = 读取文件("/data/潜心配置/配置/地图比例");
    if (ndhdh8) {
        jiange = atof(ndhdh8);
    }

    char *ndhdh9 = 读取文件("/data/潜心配置/配置/技能栏X");
    if (ndhdh9) {
        jinenglanzX = atof(ndhdh9);
    }

    char *ndhdh10 = 读取文件("/data/潜心配置/配置/技能栏Y");
    if (ndhdh10) {
        jinenglanzY = atof(ndhdh10);
    }

    char *ndhdh11 = 读取文件("/data/潜心配置/配置/上帝");
    if (ndhdh11) {
        shangdi = atof(ndhdh11);
    }

    char *ndhdh12 = 读取文件("/data/潜心配置/配置/视野X");
    if (ndhdh12) {
        屏幕x = atof(ndhdh12);
    }

    char *ndhdh13 = 读取文件("/data/潜心配置/配置/视野Y");
    if (ndhdh13) {
        屏幕y = atof(ndhdh13);
    }

    char *ndhdh14 = 读取文件("/data/潜心配置/配置/斩杀值");
    if (ndhdh14) {
        zhansha = atof(ndhdh14);
    }

    char *ndhdh16 = 读取文件("/data/潜心配置/配置/召唤师技能X");
    if (ndhdh16) {
        zhsX = atof(ndhdh16);
    }

    char *ndhdh17 = 读取文件("/data/潜心配置/配置/召唤师技能Y");
    if (ndhdh17) {
        zhsY = atof(ndhdh17);
    }

    char *ndhdh18 = 读取文件("/data/潜心配置/配置/FPS");
    if (ndhdh18) {
        FPS = atof(ndhdh18);
    }
  
    string LoadFile = "/data/潜心配置/配置/颜色配置";
    pdys = ReadDrawSet((总颜色 *) &颜色配置, LoadFile.c_str());

    return pdys;
}



#include <thread>
#include <atomic>
#include <chrono>
#include <iostream>

static int 录屏时长 = 9999; // 默认时间为900秒
static std::atomic<bool> isRecording(false); // 全局变量
static std::atomic<int> recordingTimeElapsed(0); // 录制经过的时间
static std::chrono::high_resolution_clock::time_point startTime; // 开始时间点

// 开始录制的函数
void startRecording(int duration) {
    isRecording.store(true);
    recordingTimeElapsed.store(0); // 开始时重置时间
    startTime = std::chrono::high_resolution_clock::now(); // 设置开始时间点

    // 非阻塞式开始录制
    std::string command = "screenrecord --time-limit " + std::to_string(duration) + " /sdcard/潜心录制.mp4";
    int result = system(command.c_str());

    if (result != 0) {
        std::cerr << "录制失败，请检查screenrecord命令是否可用。" << std::endl;
        isRecording.store(false);
    }
}

// 停止录制的函数
void stopRecording() {
    // 停止screenrecord进程
    system("pkill -l SIGINT screenrecord");
    isRecording.store(false);
}

#include <atomic>
#include <chrono>
#include <iostream>
#include <thread>

static std::atomic<int> screenshotCount(0); // 截屏次数计数器
static std::atomic<bool> isScreenshotting(false); // 全局变量，表示是否正在截屏

// 开始截屏的函数
void takeScreenshot() {
    isScreenshotting.store(true);
    // 生成文件名，使用计数器确保文件名唯一
    std::string filename = "/sdcard/潜心截屏_" + std::to_string(++screenshotCount) + ".png";

    // 执行screencap命令
    std::string command = "screencap -p " + filename;
    int result = system(command.c_str());

    if (result != 0) {
        std::cerr << "截屏失败，请检查screencap命令是否可用。" << std::endl;
        --screenshotCount; // 如果截屏失败，则计数器不增加
    } else {
        std::cout << "截屏成功，图片保存在: " << filename << std::endl;
    }
    isScreenshotting.store(false);
}

#include <arpa/inet.h>
#include <cstdlib>
#include <ctime>
#include <errno.h>
#include <fstream>
#include <iostream>
#include <iostream>
#include <iostream>
#include <netdb.h>
#include <netinet/in.h>
#include <signal.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <unistd.h>

using namespace std;
// 用于存储当前选择的选项







// 在你的主循环中调用 ShowComboAndCallFunction
void 写出文件(char *aaa, char *bbb) {

    std::ofstream ofs;

    ofs.open(aaa, std::ios::out);

    ofs << bbb;

    ofs.close();
}

std::thread 基址(DrawInit);
 
 
 
        


 // 异步启动游戏
void async_start_game() {
    system("am start -n com.tencent.tmgp.sgame/.SGameActivity >/dev/null 2>&1");
}
 
void ImGui_init() {
    if (g_Initialized) {
        return;
    }
    IMGUI_CHECKVERSION();
    ImGui::CreateContext();
    ImGuiIO &io = ImGui::GetIO();
    io.IniFilename = NULL;
    /*switch (Color[0])
    {
        case 0:
           // ImGui::StyleColorsRed();
                   ImGui::StyleColorsDark;
        break;
        case 1:
            ImGui::StyleColorsBlue();
        break;
        case 2:
            ImGui::StyleColorsOrange();
        break;
        }
  */
    获取头像2();
    获取图标();
    获取其他图标();

    PT = ImAgeHeadFile1(PTlogo, sizeof(PTlogo));
    ZY = ImAgeHeadFile1(ZYlogo, sizeof(ZYlogo));
    SX = ImAgeHeadFile1(SXlogo, sizeof(SXlogo));
    int pdys = 读取();
    pid = getPID("com.tencent.tmgp.sgame");
    yxpx = abs_ScreenX;
    yxpy = abs_ScreenY;
    // gotpid();
    ImGui_ImplAndroid_Init(native_window);
    ImGui_ImplOpenGL3_Init("#version 300 es");

    ImFontConfig font_cfg;
    font_cfg.SizePixels = 20.0f;
    io.Fonts->AddFontFromMemoryTTF((void *) font_v, font_v_size, 31.0f, NULL,
                                   io.Fonts->GetGlyphRangesChineseFull());
    io.Fonts->AddFontDefault(&font_cfg);

    ImGui::GetStyle().ScaleAllSizes(3.0f);
    g_Initialized = true;
}

int huitu = 0;

 void tick() {

    FPS限制.AotuFPS_init();
    float 帧率 = 1000.0f / FPS限制.AotuFPS();
    //FPS限制.setAffinity();
    //printf("帧率 %.2f\n", 帧率);
    ImGui::GetStyle().FrameRounding = 15.0f; // 设置控件边框的圆角为10像素
    ImGui::GetStyle().GrabRounding = 15.0f; // 设置控件抓取的圆角为10像素
    ImGui::SetNextWindowBgAlpha(1.0f); // 设置窗口的背景透明度为 0.5（50% 不透明）

    ImGuiIO &io = ImGui::GetIO();

    static ImVec4 clear_color = ImVec4(0, 0, 0, 0);
    ImGuiStyle &Style = ImGui::GetStyle();

    static float ANIM_SPEED = 0.35f;
    static float Velua = IsBall ? 0.0f : 1.0f;
Velua = ImClamp(Velua + (io.DeltaTime / ANIM_SPEED) * (IsBall ? 1.0f : -1.0f), 0.0f, 1.0f);
// 增加窗口大小的比例因子
const float sizeIncreaseFactor = 1.13f; // 增加10%

// 根据Velua的值和增加因子来设置窗口大小
ImGui::SetWindowSize("念", {1000 * Velua * sizeIncreaseFactor, 800 * Velua * sizeIncreaseFactor});

// 更新条件判断，因为窗口大小已经增加
if ((1000 * Velua * sizeIncreaseFactor) <= 1000 && (800 * Velua * sizeIncreaseFactor) <= 800) {
    // 窗口大小在限制范围内，可以继续使用
} else if ((1000 * Velua * sizeIncreaseFactor) >= 100 && (800 * Velua * sizeIncreaseFactor) >= 100) {
    // 窗口大小仍然符合最小尺寸要求
}

// 更新MemuSwitch的条件判断，因为窗口大小已经增加
if ((1000 * Velua * sizeIncreaseFactor) <= 100 && !IsBall) {
    BallSwitch = true;MemuSwitch = false;
}
 	if (BallSwitch)
    	{
    		Style.WindowRounding = 80.0f;
    		if (ImGui::Begin("Ball", &BallSwitch,  ImGuiWindowFlags_NoBackground | ImGuiWindowFlags_NoTitleBar | ImGuiWindowFlags_NoResize | ImGuiWindowFlags_NoScrollbar))
        	{
				auto Pos = ImGui::GetWindowPos();
    			Window = ImGui::GetCurrentWindow();
    			DrawPT({Pos.x + 50, Pos.y + 50}, 80);
    			if (ImGui::IsItemActive()) {
    				if (!IsDown) {
    					IsDown = true;
    					ImagePos = ImGui::GetWindowPos();
    				}
    			} else if (IsDown) {
    				IsDown = false;
    				if(ImagePos.x == ImGui::GetWindowPos().x && ImagePos.y == ImGui::GetWindowPos().y) {
    					IsBall = true;
    					MemuSwitch = true;
    					BallSwitch = false;
    			    	ImGui::SetWindowPos("念", Pos, ImGuiCond_Always);
    				}
    			}
    		}
    		ImGui::End();
    	}
    if (MemuSwitch) {
        Style.WindowRounding = 10.0f;
        if (ImGui::Begin("念", &MemuSwitch,
                         ImGuiWindowFlags_NoResize | ImGuiWindowFlags_NoTitleBar |
                         ImGuiWindowFlags_NoCollapse |
                         ImGuiWindowFlags_NoScrollbar)) {
 
 
 
 
 
 
// 阴影偏移量
ImVec2 shadow_offset = ImVec2(2.0f, 2.0f);

// 阴影颜色和透明度
ImVec4 shadow_color = ImVec4(0.0f, 0.0f, 0.0f, 0.5f);

// 边框颜色
ImVec4 border_color = ImVec4(1.0f, 0.0f, 0.0f, 1.0f); // 红色边框

// 描边颜色
ImVec4 stroke_color = ImVec4(0.0f, 0.0f, 1.0f, 1.0f); // 蓝色描边

// 边框线宽
float border_thickness = 2.0f;

// 描边宽度
float stroke_thickness = 1.0f;

// 保存原始光标位置
ImVec2 original_cursor_pos = ImGui::GetCursorPos();
// 保存文本尺寸
ImVec2 text_size = ImGui::CalcTextSize("                                                           BY解密");

 
// 绘制多层阴影以模拟模糊效果
for (int i = 0; i < 5; ++i) {
    // 设置阴影颜色
    ImVec4 color = shadow_color;
    color.w *= (1.0f - (float)i / 5.0f); // 逐渐减少透明度
    ImGui::PushStyleColor(ImGuiCol_Text, color);
    
    // 绘制阴影文本
    ImGui::SetCursorPos(ImVec2(original_cursor_pos.x + shadow_offset.x * i, original_cursor_pos.y + shadow_offset.y * i));
    ImGui::Text("                                                           BY解密  ");
    
    // 恢复文本颜色
    ImGui::PopStyleColor();
}

// 恢复原始光标位置
ImGui::SetCursorPos(original_cursor_pos);

// 绘制描边文本
for (int i = -stroke_thickness; i <= stroke_thickness; i++) {
    for (int j = -stroke_thickness; j <= stroke_thickness; j++) {
        if (i != 0 || j != 0) {
            ImGui::PushStyleColor(ImGuiCol_Text, stroke_color);
            ImGui::SetCursorPos(ImVec2(original_cursor_pos.x + i, original_cursor_pos.y + j));
            ImGui::Text("                                                           BY解密  ");
            ImGui::PopStyleColor();
        }
    }
}

// 绘制删除线

ImVec2 start_pos = ImVec2(original_cursor_pos.x, original_cursor_pos.y + text_size.y * 0.5f);
ImVec2 end_pos = ImVec2(start_pos.x + text_size.x, start_pos.y);
ImGui::GetWindowDrawList()->AddLine(start_pos, end_pos, ImGui::GetColorU32(ImGuiCol_Text), 1.0f);

// 绘制正常文本
ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(1.0f, 1.0f, 1.0f, 1.0f)); // 设置文本颜色为白色
ImGui::SetCursorPos(original_cursor_pos);
ImGui::Text("                                                           BY解密  ");
ImGui::PopStyleColor(); 
 ImGui::Separator();
 
 
 

 
            auto Pos = ImGui::GetWindowPos();
            Window = ImGui::GetCurrentWindow();
            ImGui::PushStyleVar(ImGuiStyleVar_WindowPadding, {25, 25});
            if (ImGui::BeginChild("##主菜单", ImVec2(-1, 80), false,
                                  ImGuiWindowFlags_NoScrollbar)) {
                if (ImGui::Button("绘制", ImVec2(170, 75))) {
                    MenuTab = 1;
                }
                ImGui::SameLine();
                if (ImGui::Button("设置", ImVec2(170, 75))) {
                    MenuTab = 2;
                }
                ImGui::SameLine();
                if (ImGui::Button("触摸", ImVec2(170, 75))) {
                    MenuTab = 3;
                }
                ImGui::SameLine();
                if (ImGui::Button("基址", ImVec2(170, 75))) {
                    MenuTab = 4;
                }
                ImGui::SameLine();
                if (ImGui::Button("缩小", ImVec2(170, 75))) {
                    IsBall = false;
                }
            }
    
         ImGui::EndChild();
            if (ImGui::BeginChild("##右菜单", ImVec2(-1, -1), false,
                                  ImGuiWindowFlags_NoScrollbar)) {
                if (ImGui::BeginChild("##右菜单", ImVec2(1000, 700), false)) {
                    switch (MenuTab) {
                        case 1: {
                            if (pid == 0 || pid == -1) {
                                ImGui::Text("PID: 游戏未运行");
                            } else {
                                ImGui::Text("PID: %d", pid);
                            }
                            ImGui::SameLine();
                            if (status) {
                                if (ButtonTextColored(Style.Colors[ImGuiCol_ButtonActive],
                                                      "防截屏 开")) {
                                    remove("/data/潜心配置/配置/防截屏");
                                    status = false;
                                }
                            } else {
                                if (ButtonTextColored(Style.Colors[ImGuiCol_ButtonActive],
                                                      "防截屏 关")) {
                                    system("touch /data/潜心配置/配置/防截屏 >/dev/null 2>&1");
                                    status = true;
                                }
                            }
                            ImGui::Text("分辨率: %dx%d", yxpx, yxpy);
                            ImGui::SameLine();
                            ImGui::Text("帧率 %.1f FPS", ImGui::GetIO().Framerate);
                            // SetTheme("切换主题");
                      // 开启绘制或关闭绘制的按钮
                       


// ImGui的按钮点击事件
if (huitu == 0) {
    if (ImGui::Button("开启绘制", ImVec2(120,50))) {
        huitu = 1;
        血量 = 1;
        read_config();
    }
} else {
    if (pid == 0 || pid == -1) {
        血量 = 0;
        read_config();
        if (ImGui::Button("启动游戏", ImVec2(120,50))) {
            std::thread game_thread(async_start_game);
            game_thread.detach(); // 分离线程，让其独立运行
            huitu = 0;
        }
    } else {
        if (ImGui::Button("关闭绘制", ImVec2(120,50))) {
            pid = 0;
           
            huitu = 0;
            血量 = 0;
            地图 = 0;
            射线 = 0;
            方框 = 0;
            野怪 = 0;
            兵线 = 0;
            方框技能 = 0;
            顶上技能 = 0;
            视野方框 = 0;
            自身 = 0;
            长方血量 =0;
            实体兵线 = 0;
            // 其他变量重置...
        }
    }
}



ImGui::SameLine();

                                // 录制按钮
                                if (!isRecording.load()) {
                                    if (ImGui::Button("辅助录制", ImVec2(120, 50))) {
                                        std::thread([]() {
                                            startRecording(录屏时长);
                                            // 等待录制完成
                                            while (isRecording.load() && recordingTimeElapsed.load() < 录屏时长) {
                                                std::this_thread::sleep_for(std::chrono::seconds(1));
                                                recordingTimeElapsed.fetch_add(1);
                                            }
                                        }).detach();
                                    }
                                } else {
                                    if (ImGui::Button("停止录制", ImVec2(120, 50))) {
                                        stopRecording();
                                    }
                                }
                                
                                ImGui::SameLine();
                                    if (ImGui::Button("保存配置", {120, 50})) {
                                                                            string SaveFile;
                                                                            SaveFile = "/data/潜心配置/配置/颜色配置";
                                                                            pdys = SaveDrawSet((总颜色 *) &颜色配置,
                                                                                               SaveFile.c_str());
                                                                            printf("\033[36;1m"); // 蓝色
                                                                            printf("[-] 配置已保存\n");
                                                                        }
                                // 显示截屏次数
                                
                                ImGui::SameLine();
                                
                                // 退出按钮
                                if (ImGui::Button("退出", ImVec2(120,50))) {
                             
                                    stopFlag = true;
                                	write_config();
                                    基址.join();
                                   Touch::Close();
                                    exit(0);
                                }
                                
                                 	ImGui::SameLine();    	
                                
                                		   if (ImGui::Button("辅助截屏", ImVec2(120, 50))) {
                                    if (!isScreenshotting.load()) {
                                        // 如果不在截屏，则开始截屏
                                        takeScreenshot();
                                    } else {
                                        std::cout << "正在截屏中，请稍候..." << std::endl;
                                    }
                                }
                                
                                
                                
                                 
                                 
                                 
                                 
                                 
                                 
                                int currentChoice = -1; // 初始化为-1，表示没有选中任何选项
                                
                                ImGui::SetNextWindowSize(ImVec2(100.0f, 0.0f)); // 设置组合框的宽度为100像素，高度自动计算
                                if (ImGui::BeginCombo("一键配置选择自己的分辨率", "当前选择: ")) {
                                    if (ImGui::Selectable("2367x1080")) currentChoice = 0;
                                    if (ImGui::Selectable("3200x1440")) currentChoice = 1;
                                    if (ImGui::Selectable("2712x1220")) currentChoice = 2;
                                    if (ImGui::Selectable("2400x1080")) currentChoice = 3;
                                    if (ImGui::Selectable("2560x1600")) currentChoice = 4;
                                    if (ImGui::Selectable("3192x1368")) currentChoice = 5;
                                    if (ImGui::Selectable("2340x1080")) currentChoice = 6;
                                    if (ImGui::Selectable("2800x1800")) currentChoice = 7;
                                    if (ImGui::Selectable("2460x1080")) currentChoice = 8;
                                        if (ImGui::Selectable("小米14专属")) currentChoice = 9;
                                          if (ImGui::Selectable("3168x1440")) currentChoice = 10;
                                    if (ImGui::Selectable("2800x1968")) currentChoice = 11;
                                    ImGui::EndCombo();
                                }
                                
                                // 根据选择调用相应的函数
                                if (currentChoice != -1) {
                                    switch (currentChoice) {
                                        case 0:
                                            一加();
                                            break;
                                        case 1:
                                            王者();
                                            break;
                                        case 2:
                                            荣耀();
                                            break;
                                        case 3:
                                            神明();
                                            break;
                                        case 4:
                                            无敌();
                                            break;
                                        case 5:
                                            咳咳();
                                            break;
                                        case 6:
                                            欧凯();
                                            break;
                                        case 7:
                                            一般();
                                            break;
                                        case 8:
                                            欧派();
                                            break;
                                        case 9:
                                            胡萝卜();
                                            break;
                                                    case 10:
                                            死了();
                                            break;
                                                    case 111:
                                            噢();
                                            break;
                                    }
                                    currentChoice = -1; // 重置选择，使其不保持状态
                                }
                                
                                                           
                     
 

 

 
 
 
int qianxin = 0; // 初始化为0，表示默认选中实体1
const char* current_entity_name = "BY绘图"; // 初始化为默认选中的实体名称

ImGui::PushItemWidth(168.0f); // 设置组合框的宽度为168像素
if (ImGui::BeginCombo("BY", current_entity_name)) { // 使用current_entity_name作为默认提示文本
    if (ImGui::Selectable("BY绘图")) {
        qianxin = 0;
        current_entity_name = "BY绘图"; // 更新当前选中的实体名称
        实体1(); // 用户选择实体1
    }
    if (ImGui::Selectable("简洁绘图")) {
        qianxin = 1;
        current_entity_name = "简洁绘图"; // 更新当前选中的实体名称
        实体2(); // 用户选择实体2
    }
    if (ImGui::Selectable("git绘图")) {
        qianxin = 2;
        current_entity_name = "git绘图"; // 更新当前选中的实体名称
        实体3(); // 用户选择实体3
    }
    ImGui::EndCombo();
}
ImGui::PopItemWidth(); // 确保组合框之后的项不受影响

ImGui::SameLine(); // 如果需要在组合框旁边放置其他元素，请确保它在EndCombo之后
 
 
 
                                 ImGui::SameLine();
    
                                    if (ImGui::Checkbox("血量圆弧长条", &长方血量)) {
                                        // 如果选择了第一个选项，确保第三个选项关闭
                                        if (长方血量) {
                                            血量 = true;
                                        }
                                    }
                                ImGui::SameLine();

                                   
                                // 注意：实际上截屏是瞬间完成的，所以不需要像录制视频那样等待和显示时间
                                ImGui::Separator();
                                ImGui::Separator();
                                if (pid > 0) {
                                    // 字体大小调整
                                    ImGui::SliderFloat("字体大小", &特定字体大小, 0.5f, 2.0f);
                                    ImGui::SetWindowFontScale(特定字体大小); // 设置字体大小
                                                                
                                                                 
                                                          
                                // 视觉设置
                                ImGui::PushStyleColor(ImGuiCol_ChildBg, ImVec4(0.2f, 0.8f, 0.2f, 0.2f));
                                ImGui::BeginGroup();
                                ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(1.0f, 1.0f, 1.0f, 1.0f)); // 设置文本颜色为白色
                                ImGui::PushFont(ImGui::GetIO().Fonts->Fonts[0]); // 假设第一个字体是加粗的
                                ImGui::BulletText("•"); // 显示白色加粗的点
                                ImGui::PopFont();
                                ImGui::PopStyleColor();
                                ImGui::Checkbox("头显", &头像常显);
                                ImGui::SameLine();
                                ImGui::Checkbox("小技", &小技能计时);
                                ImGui::SameLine();
                                ImGui::Checkbox("暗化", &暗化);
                                ImGui::EndGroup();
                                ImGui::PopStyleColor();
                                ImGui::Separator();
                                
                                // 地图和辅助
                                ImGui::PushStyleColor(ImGuiCol_ChildBg, ImVec4(0.8f, 0.2f, 0.2f, 0.2f));
                                ImGui::BeginGroup();
                                ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(1.0f, 1.0f, 1.0f, 1.0f)); // 设置文本颜色为白色
                                ImGui::PushFont(ImGui::GetIO().Fonts->Fonts[0]); // 假设第一个字体是加粗的
                                ImGui::BulletText("•"); // 显示白色加粗的点
                                ImGui::PopFont();
                                ImGui::PopStyleColor();
                                ImGui::Checkbox("头像", &地图);
                                ImGui::SameLine();
                                ImGui::Checkbox("方框", &方框);
                                ImGui::SameLine();
                                ImGui::Checkbox("框技", &方框技能);
                                ImGui::EndGroup();
                                ImGui::PopStyleColor();
                                ImGui::Separator();
                                
                                // 技能和游戏
                                ImGui::PushStyleColor(ImGuiCol_ChildBg, ImVec4(0.2f, 0.2f, 0.8f, 0.2f));
                                ImGui::BeginGroup();
                                ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(1.0f, 1.0f, 1.0f, 1.0f)); // 设置文本颜色为白色
                                ImGui::PushFont(ImGui::GetIO().Fonts->Fonts[0]); // 假设第一个字体是加粗的
                                ImGui::BulletText("•"); // 显示白色加粗的点
                                ImGui::PopFont();
                                ImGui::PopStyleColor();
                                ImGui::Checkbox("顶技", &顶上技能);
                                ImGui::SameLine();
                                ImGui::Checkbox("兵线", &兵线);
                                ImGui::SameLine();
                                ImGui::Checkbox("野怪", &野怪);
                                ImGui::EndGroup();
                                ImGui::PopStyleColor();
                                ImGui::Separator();
                                
                                // 其他设置
                                ImGui::PushStyleColor(ImGuiCol_ChildBg, ImVec4(0.8f, 0.8f, 0.2f, 0.2f));
                                ImGui::BeginGroup();
                                ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(1.0f, 1.0f, 1.0f, 1.0f)); // 设置文本颜色为白色
                                ImGui::PushFont(ImGui::GetIO().Fonts->Fonts[0]); // 假设第一个字体是加粗的
                                ImGui::BulletText("•"); // 显示白色加粗的点
                                ImGui::PopFont();
                                ImGui::PopStyleColor();
                                ImGui::Checkbox("射线", &射线);
                                ImGui::SameLine();
                                ImGui::Checkbox("敌视", &视野方框);
                                ImGui::SameLine();
                                ImGui::Checkbox("自视", &自身);
                                ImGui::EndGroup();
                                ImGui::Separator();
                                
                                // 暴露设置
                                ImGui::PushStyleColor(ImGuiCol_ChildBg, ImVec4(0.2f, 0.2f, 0.8f, 0.2f));
                                ImGui::BeginGroup();
                                ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(1.0f, 1.0f, 1.0f, 1.0f)); // 设置文本颜色为白色
                                ImGui::PushFont(ImGui::GetIO().Fonts->Fonts[0]); // 假设第一个字体是加粗的
                                ImGui::BulletText("•"); // 显示白色加粗的点
                                ImGui::PopFont();
                                ImGui::PopStyleColor();
                                ImGui::Checkbox("兵线实体", &实体兵线);
                                ImGui::SameLine();
                                ImGui::Checkbox("技能不绘", &方框技能不绘);
                                ImGui::SameLine();
                                 
                                ImGui::Checkbox("暴露不绘", &暴露视野不绘);
                                ImGui::EndGroup();
                                ImGui::PopStyleColor();
                                ImGui::Separator();
                                
                                // 界面透明度设置
                                ImGui::BeginGroup();
                                ImGui::SliderFloat("界面透明度", &透明度, 0.1f, 1.0f);
                                ImGui::GetStyle().Alpha = 透明度;
                                ImGui::EndGroup(); // 结束组
                                
                                // 恢复默认字体大小
                                ImGui::SetWindowFontScale(1.0f);
                                
                                // 确保在显示完所有内容后调用PopStyleColor
                                ImGui::PopStyleColor();
                                ImGui::Separator();
                                 
                                 
                                 
                                    
                                   
                                   
                                    
                                                            } else {
                                                            // 绘制文本
                                ImGui::Separator();//横线分割
                                std::string fileContent = loadTextFromFile("/storage/emulated/0/Android/BY公告.txt");
                                ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(0.0f, 0.5f, 0.0f, 1.0f));
                                ImGui::TextWrapped("公告内容:%s", fileContent.c_str()); 
                                
                                ImGui::PopStyleColor();
                                                                ImGui::Text("爱意随风起");
                                ImGui::Text("风止意难平");
                                ImGui::Separator();
                                ImGui::Text("创作者：BY");
                               ImGui::Text("BYBY解密牛逼");

                                DrawZY({Pos.x + 850, Pos.y + 670}, 200);
                            }

                        }
                            break;
                        case 2: {
                            ImGui::BeginChild("##设置a", ImVec2(170, -1), false
                            );
                            {
                                if (ImGui::Button("绘制设置", ImVec2(-1, 75))) {
                                    MenuTab2 = 1;
                                }
                                if (ImGui::Button("显示设置", ImVec2(-1, 75))) {
                                    MenuTab2 = 2;
                                }
                                if (ImGui::Button("颜色设置", ImVec2(-1, 75))) {
                                    MenuTab2 = 3;
                                }
                            }
                            ImGui::EndChild();
                            ImGui::SameLine();
                            ImGui::BeginChild("##设置a-content", ImVec2(-1, -1), false
                            );
                            {
                                switch (MenuTab2) {
                                    case 1: {
                                        if (yxpx == 240 && yxpy == 108) {
                                            ImGui::Text("分辨率: %dx%d", yxpx, yxpy);
                                            ImGui::SameLine();
                                            ImGui::Text("支持一键配置");
                                            if (ImGui::Button("一键配置", ImVec2(315, 50))) {
                                               
                                                touxiangdaxiao = 0;
                                                touxiangdaxiao = 0;
                                                xiaodituxue = 0;
                                                jiange = 0;
                                                SmallMapX = 0;
                                                SmallMapY = 0;
                                                SmallHPX = 0;
                                                SmallHPY = 0;
                                                jinenglanzX = 0;
                                                jinenglanzY = 0;
                                                读取();
                                            }

                                        } else if (yxpx == 156 && yxpy == 140) {
                                            ImGui::Text("分辨率: %dx%d", yxpx, yxpy);
                                            ImGui::SameLine();
                                            ImGui::Text("支持一键配置");
                                            if (ImGui::Button("一键配置", ImVec2(315, 50))) {
                                                
                                                touxiangdaxiao = 0;
                                                touxiangdaxiao = 0;
                                                xiaodituxue = 0;
                                                jiange = 0;
                                                SmallMapX = 0;
                                                SmallMapY = 0;
                                                SmallHPX = 0;
                                                SmallHPY = 0;
                                                jinenglanzX = 0;
                                                jinenglanzY = 0;
                                                读取();
                                            }

                                        } else if (yxpx == 272 && yxpy == 120) {
                                            ImGui::Text("分辨率: %dx%d", yxpx, yxpy);
                                            ImGui::SameLine();
                                            ImGui::Text("支持一键配置");
                                            ImGui::SameLine();
                                            if (ImGui::Button("一键配置", ImVec2(315, 50))) {
                                               
                                                touxiangdaxiao = 0;
                                                touxiangdaxiao = 0;
                                                xiaodituxue = 0;
                                                jiange = 0;
                                                SmallMapX = 0;
                                                SmallMapY = 0;
                                                SmallHPX = 0;
                                                SmallHPY = 0;
                                                jinenglanzX = 0;
                                                jinenglanzY = 0;
                                                读取();
                                            }

                                        } else {
                                            ImGui::Text("分辨率: %dx%d", yxpx, yxpy);
                                            ImGui::SameLine();
                                            ImGui::Text("未适配一键配置");
                                        }
 if (touxiangdaxiao != 0 || touxiangdaxiao != 0 ||
                                            xiaodituxue != 0 || jiange != 0 || SmallMapX != 0 ||
                                            SmallMapY != 0 || SmallHPX != 0 || SmallHPY != 0 ||
                                            jinenglanzX != 0 || jinenglanzY != 0 || 屏幕x != 0 ||
                                            屏幕y != 0 || zhansha != 0.0f || chengjie != 0) {
                                            
        
                                            if (ImGui::Button("重置配置", ImVec2(240, 50))) {
                                                system(
                                                        "rm -f "
                                                        "/data/潜心配置/配置/"
                                                        "{头像大小,血量大小,地图比例,头像X,头像Y,实体X,实体Y,"
                                                        "技能栏X,技能栏Y,视野X,视野Y,斩杀值，惩戒值，召唤师技能X,"
                                                        "召唤师技能Y,上帝,FPS} >/dev/null 2>&1");
                                                printf("\033[36;1m"); // 蓝色
                                                printf("[-] 配置已重置\n");
                                                touxiangdaxiao = 0;
                                                touxiangdaxiao = 0;
                                                xiaodituxue = 0;
                                                jiange = 0;
                                                SmallMapX = 0;
                                                SmallMapY = 0;
                                                SmallHPX = 0;
                                                SmallHPY = 0;
                                                jinenglanzX = 0;
                                                jinenglanzY = 0;
                                                屏幕x = 0;
                                                屏幕y = 0;
                                                zhansha = 0.0f;
                                                chengjie = 0;
                                                读取();
                                            }
                                            ImGui::SameLine();
                                        }
                                        ImGui::Checkbox("辅助框", &辅助方框);
                                        
                                    }
                                        ImGui::Separator();

                                        if (ImGui::SliderInt("头像X", &SmallMapX, -200, 200, "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", SmallMapX);
                                            写出文件("/data/潜心配置/配置/头像X", urlls);
                                        }

                                        if (ImGui::SliderInt("头像Y", &SmallMapY, -200, 200, "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", SmallMapY);
                                            写出文件("/data/潜心配置/配置/头像Y", urlls);
                                        }

                                        if (ImGui::SliderInt("头像大小", &touxiangdaxiao, -300, 300,
                                                             "%.0f", 2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", touxiangdaxiao);
                                            写出文件("/data/潜心配置/配置/头像大小", urlls);
                                        }

                                        if (ImGui::SliderInt("血量大小", &xiaodituxue, -80, 80,
                                                             "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", xiaodituxue);
                                            写出文件("/data/潜心配置/配置/血量大小", urlls);
                                        }

                                        if (ImGui::SliderInt("地图比例", &jiange, -100, 100, "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", jiange);
                                            写出文件("/data/潜心配置/配置/地图比例", urlls);
                                        }

                                        if (ImGui::SliderInt("实体X", &SmallHPX, -300, 300, "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", SmallHPX);
                                            写出文件("/data/潜心配置/配置/实体X", urlls);
                                        }

                                        if (ImGui::SliderInt("实体Y", &SmallHPY, -300, 300, "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", SmallHPY);
                                            写出文件("/data/潜心配置/配置/实体Y", urlls);
                                        }

                                        if (ImGui::SliderInt("技能栏X", &jinenglanzX, -2000, 2000,
                                                             "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", jinenglanzX);
                                            写出文件("/data/潜心配置/配置/技能栏X", urlls);
                                        }

                                        if (ImGui::SliderInt("技能栏Y", &jinenglanzY, -500, 500,
                                                             "%.0f",
                                                             2)) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", jinenglanzY);
                                            写出文件("/data/潜心配置/配置/技能栏Y", urlls);
                                            DrawZY({Pos.x + 850, Pos.y + 670}, 200);
                                        }
                                         
                                      if (ImGui::SliderInt("自身视野X", &屏幕x, -100, 100, "%.0f")) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", 屏幕x);
                                           写出文件("/data/潜心配置/配置/自身视野X", urlls);
                                           }
                                           if (ImGui::SliderInt("自身视野Y", &屏幕y, -200, 200, "%.0f")) {
                                            char urlls[2560];
                                            sprintf(urlls, "%d", 屏幕y);
                                           写出文件("/data/潜心配置/配置/自身视野Y", urlls);
                               
   
                                           }
                                           
                                           
                                           
                                            
                                    
                                        break;


                                    case 2: {

                                        if (ImGui::SliderInt("限制功耗", &FPS, 10.0f, 144.0f,
                                                             "%0.2f")) {
                                            char urlls[2560];
                                            float fps = FPS * 2.3f;
                                            sprintf(urlls, "%d", FPS);
                                            写出文件("/data/潜心配置/配置/FPS", urlls);

                                            FPS限制.SetFps(fps);
                                        }


                                        ImGui::Checkbox("10v10", &十人);


                                    }
                                        break;


                                    case 3: {
                                        ImGui::ColorEdit3("血量颜色", (float *) &颜色配置.血量颜色);
                                        ImGui::ColorEdit3("无视野血量",
                                                          (float *) &颜色配置.无血量颜色);
                                        ImGui::ColorEdit3("射线颜色", (float *) &颜色配置.射线颜色);
                                        ImGui::ColorEdit3("方框颜色", (float *) &颜色配置.方框颜色);
                                        ImGui::Separator();
                                        if (ImGui::Button("保存配置", {315, 65})) {
                                            string SaveFile;
                                            SaveFile = "/data/潜心配置/配置/颜色配置";
                                            pdys = SaveDrawSet((总颜色 *) &颜色配置,
                                                               SaveFile.c_str());
                                            printf("\033[36;1m"); // 蓝色
                                            printf("[-] 配置已保存\n");
                                        }
                                        if (pdys == 1) {
                                            if (ImGui::Button("重置颜色配置", {315, 65})) {
                                                remove("/data/潜心配置/配置/颜色配置");
                                                pdys = 0;
                                                string LoadFile = "/data/潜心配置/配置/颜色配置";
                                                pdys = ReadDrawSet((总颜色 *) &颜色配置,
                                                                   LoadFile.c_str());
                                                printf("\033[36;1m"); // 蓝色
                                                printf("[-] 配置已重置\n");
                                            }
                                        }

                                    }
                                        break;
                                }

                            }
                            ImGui::EndChild();
                        }
                            break;
                                      case 3: {
         
            if (触摸驱动) {
              绘制触摸 = true;
              ImGui::Text("召唤师: %s ", 召唤师.c_str());
              ImGui::SameLine();
              ImGui::Text("技能: %s", 召唤师技能.c_str());
              // ImGui::Separator();*/
              ImGui::Checkbox("自动斩杀", &自动斩杀);
              if (自动斩杀) {
                自动惩戒 = false;
                自动净化 = false;
              }
              ImGui::SameLine();
              ImGui::Checkbox("自动惩戒", &自动惩戒);
              if (自动惩戒) {
                自动斩杀 = false;
                自动净化 = false;
              }
              ImGui::SameLine();
              ImGui::Checkbox("自动净化", &自动净化);
              if (自动净化) {
                自动斩杀 = false;
                自动惩戒 = false;
              }
              
              ImGui::ItemSize(ImVec2(0, 1));
              ImGui::Separator();
              if (自动惩戒) {

                ImGui::Checkbox("附近有人时惩戒", &智能不惩);
                ImGui::Separator();
              }
              if (自动斩杀) {

                ImGui::Checkbox("自动计算斩杀线", &智能斩杀);
                ImGui::Separator();
              }
              
              ImGui::ItemSize(ImVec2(0, 1));
              if (!智能斩杀) {
                if (ImGui::SliderFloat("斩杀百分比", &zhansha, 10.0f, 20.0f,
                                       "%.1f", 2)) {
                  char urlls[2560];
                  sprintf(urlls, "%.1f", zhansha);
                  写出文件("/data/潜心配置/配置/斩杀值", urlls);
                }
              }
              if (自动净化) {
              ImGui::Checkbox("附近有人时解控", &自动净化);
              ImGui::Separator();
              ImGui::Columns(2, "CheckboxColumns", false); // 创建两列
              ImGui::RadioButton("净化", &jk, 0);
              ImGui::RadioButton("二技能", &jk, 2);
              ImGui::NextColumn();
              ImGui::RadioButton("一技能", &jk, 1);
              ImGui::RadioButton("三技能", &jk, 3);
                ImGui::Separator();
             
              ImGui::Columns(1); // 重置列回单列
              ImGui::Separator();
              }
              /*if(ImGui::SliderInt("惩戒值", &chengjie, 0, 5000, "%.0f", 2)) {
                  char urlls[2560];
                                          sprintf(urlls, "%d", chengjie);
                                          写出文件("/data/潜心配置/配置/惩戒值",urlls);
                                  }*/
              if (ImGui::SliderInt("技能位置X", &zhsX, 0, yxpx, "%.0f", 2)) {
                char urlls[2560];
                sprintf(urlls, "%d", zhsX);
                写出文件("/data/潜心配置/配置/召唤师技能X", urlls);
              }
              if (ImGui::SliderInt("技能位置Y", &zhsY, 0, yxpy, "%.0f", 2)) {
                char urlls[2560];
                sprintf(urlls, "%d", zhsY);
                写出文件("/data/潜心配置/配置/召唤师技能Y", urlls);
              }
            }

          } break;
                        case 4: {
                            ImGui::BeginChild("##基质a", ImVec2(170, -1), false
                            );
                            if (ImGui::Button("上帝视角", ImVec2(-1, 75))) {
                                MenuTab4 = 1;
                            }
                            if (ImGui::Button("其他功能", ImVec2(-1, 75))) {
                                MenuTab4 = 2;
                            }
                            ImGui::EndChild();
                            ImGui::SameLine();
                            ImGui::BeginChild("##内容a-content", ImVec2(-1, -1), false
                            );
                            switch (MenuTab4) {
                                case 1: {
                                    long sdaddr = ReadValue(ReadValue(lil2cpp_base + 0xE078) + 0xB8)+0x25C;
                                    float syz = ReadFloat(sdaddr);

                                    ImGui::Text("视角值: %.2f", syz);

                                    ImGui::Separator();
                                    // ImGui::ItemSize(ImVec2(0, 1));
                                    if (ImGui::SliderFloat("上帝视角", &shangdi, 0.0f, 2.5f, "%.2f",
                                                           2)) {
                                        char urlls[2560];
                                        sprintf(urlls, "%.2f", shangdi);
                                        写出文件("/data/潜心配置/配置/上帝", urlls);
                                    }
                                    // ImGui::ItemSize(ImVec2(0, 1));
                                    ImGui::Separator();

                                    if (pid > 0) {
                                        // ImGui::ItemSize(ImVec2(0, 1));
                                        if (!近景上帝) {
                                            if (ImGui::Button("开启上帝", {-1, 70})) {
                                                WriteFloat(sdaddr, shangdi);
                                                近景上帝 = true;
                                            }
                                        } else {
                                            if (ImGui::Button("恢复视角", {-1, 70})) {
                                                WriteFloat(sdaddr, 0.0f);
                                                近景上帝 = false;
                                            }
                                        }

                                    } else {
                                        // ImGui::SetCursorPos({0, 180});
                                        DrawPT({Pos.x + 850, Pos.y + 680}, 180);
                                        ImGui::Text("你是不灭的星光");
                                        ImGui::Text("日复一日的美梦");
                                        ImGui::Separator();
                                        
                                        ImGui::Text("创作者：BY");
                                        ImGui::Text("LOADING...");
                                        DrawZY({Pos.x + 850, Pos.y + 670}, 200);
                                    }
                                }
                                    break;

                                case 2: {
 
              ImGui::Separator();
              
              if(ImGui::CollapsingHeader("使用说明"))
              {
              ImGui::Separator();
              ImGui::Text("没什么想说的，注意演戏，少吃举报");   
              ImGui::Separator();
              ImGui::Text("内核已是无敌路 不演后果你自负");    	
            
              ImGui::Separator();
            
              ImGui::TextColored(ImGui::GetStyle().Colors[ImGuiCol_Text],"作者: BY\n技术支持 :BY");
              ImGui::Separator();
              DrawZY({Pos.x + 850, Pos.y + 670}, 200);
              }
	    
                                    /* long addr_FDK = ReadValue(libtersafe_base + 0x5A5208) + 0x10;
                                     int 掉框值 = ReadDword(addr_FDK);
                                     int 状态码 = ReadDword(libtersafe_base + 0x544000);
                                     //ImGui::Text("[念] 防掉框");
                                     ImGui::Text("此页功能不保证100%安全，自行判断是否开启");
                                     ImGui::Separator();
                                     ImGui::Text("对局状态码[%d]", 状态码);
                                     if (掉框值 == 16386) {
                                       ImGui::Text("防掉框已加载[%d]", 掉框值);
                                     } else {
                                       //DrawInit();
                                       ImGui::Text("防掉框未加载[%d]", 掉框值);
                                     }
                                     ImGui::Separator();
                                     if (掉框值 == 16384) {
                                       if (ImGui::Button("加载防掉框", {-1, 70})) {
                                         WriteDword(addr_FDK, 16386);
                                       }
                                     }

                                     ImGui::Text("请等待进入选区界面");
                                     ImGui::Text("若已进入选区界面，请重新点击初始化绘制");

                                   } break;
                                   }*/
                                }
                                    break;
                            }

                            ImGui::EndChild();
                        }
                    }
                }
                ImGui::EndChild();
                // ImGui::PopStyleVar(1);
            }
            ImGui::End();
        }
        ImGui::End();
    }

    if (IsWin) {
        IsWin = false;
        IsLoGin = false;
        BallSwitch = false;
        ImGui::SetWindowSize("Ball", {100.0f, 100.0f});
    }
    /*****绘制帧率*****/
    // char fkhs[1000];
    // sprintf(fkhs,"%.1f FPS",ImGui::GetIO().Framerate);
    //  ImGui::GetForegroundDrawList()->AddText(ImVec2(300,
    //  1000),ImColor(225,255,255),fkhs);
    // ImGui::GetForegroundDrawList()->AddText(ImVec2(90,
    // 1000),ImColor(225,255,255),"TS内部解密 状态稳定");
    /*****结束*****/
    if (huitu == 1) {
        DrawPlayer(); // 调用绘图  直接写在这边的话有点影响观看了
        
    }
}

void screen_config() {
    ::displayInfo = android::ANativeWindowCreator::GetDisplayInfo();
    ::screen_x = displayInfo.width;
    ::screen_y = displayInfo.height;
}

void drawBegin() {
    ImGui_ImplOpenGL3_NewFrame();
    ImGui_ImplAndroid_NewFrame(native_window_screen_x, native_window_screen_y);
    ImGui::NewFrame();

    screen_config();

    static uint32_t orientation = -1;
    if (orientation != displayInfo.orientation) {
        orientation = displayInfo.orientation;
        Touch::setOrientation(displayInfo.orientation);
        屏幕方向 = displayInfo.orientation;
        // cout << " width:" << displayInfo.width << " height:" <<
        // displayInfo.height << " orientation:" << displayInfo.orientation << endl;
    }
}

void drawEnd() {
    ImGuiIO &io = ImGui::GetIO();
    glViewport(0.0f, 0.0f, (int) io.DisplaySize.x, (int) io.DisplaySize.y);
    glClearColor(0.0f, 0.0f, 0.0f, 0.0f);
    glClear(GL_COLOR_BUFFER_BIT); // GL_DEPTH_BUFFER_BIT
    glFlush();
    if (display == EGL_NO_DISPLAY) {
        return;
    }
    ImGui::Render();
    ImGui_ImplOpenGL3_RenderDrawData(ImGui::GetDrawData());
    eglSwapBuffers(display, surface);
}

void shutdown() {
    if (!g_Initialized) {
        return;
    }
    ImGui_ImplOpenGL3_Shutdown();
    ImGui_ImplAndroid_Shutdown();
    ImGui::DestroyContext();
    if (display != EGL_NO_DISPLAY) {
        eglMakeCurrent(display, EGL_NO_SURFACE, EGL_NO_SURFACE, EGL_NO_CONTEXT);
        if (context != EGL_NO_CONTEXT) {
            eglDestroyContext(display, context);
        }
        if (surface != EGL_NO_SURFACE) {
            eglDestroySurface(display, surface);
        }
        eglTerminate(display);
    }
    display = EGL_NO_DISPLAY;
    context = EGL_NO_CONTEXT;
    surface = EGL_NO_SURFACE;
    ANativeWindow_release(native_window);
}
