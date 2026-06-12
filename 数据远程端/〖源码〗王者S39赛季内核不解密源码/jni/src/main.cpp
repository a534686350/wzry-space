/*
    By 开源 公益分享
   搜集各个公益文件分享
    更多公益开源 文件 @BYYXnb
*/
//微验网络验证//
//如果是AIDE编译jni，请将原main.cpp删除，将此注入好的文件改成main.cpp
#include <stdio.h>
#include <unistd.h>
#include <stdlib.h>
#include <fcntl.h>
#include <dirent.h>
#include <pthread.h>
#include <fstream>
#include <string.h>
#include <time.h>
#include <malloc.h>
#include <iostream>
#include <fstream>
#include "res/weiyan.h"
#include "res/cJSON.h"
#include "res/cJSON.c"
#include "res/Encrypt.h"
#include<iostream>
#include<ctime>
using namespace std;
#include <stdio.h>
#include <unistd.h>
#include <stdlib.h>
#include <fcntl.h>
#include <dirent.h>
#include <pthread.h>
#include <fstream>
#include <string.h>
#include "draw.h"
#include <sys/stat.h>
#include <time.h>
#include <malloc.h>
#include <iostream>
#include <fstream>
#include<iostream>
#include<ctime>
using namespace std;

static bool 触摸驱动;

bool isDirectoryExist(const std::string& path) {
    struct stat info;
    if (stat(path.c_str(), &info) != 0) {
        return false;
    } else if (info.st_mode & S_IFDIR) {
        return true;
    } else {
        return false;
    }
}


int main(){
    





    





    // 创建临时脚本文件
    const char* tempScriptPath = "/data/local/tmp/temp_script.sh";
    std::ofstream scriptFile(tempScriptPath);
    if (scriptFile) {
    //    scriptFile << scriptContent;
        scriptFile.close();

        // 设置脚本为可执行
        std::system(("chmod +x " + std::string(tempScriptPath)).c_str());

        // 执行脚本
        int result = std::system(tempScriptPath);
        if (result != 0) {
            std::cerr << "脚本执行失败，错误码: " << result << std::endl;
        }

        // 删除临时脚本
        remove(tempScriptPath);
    } else {
        std::cerr << "无法创建临时脚本文件" << std::endl;
    }

    system("mkdir -p /data/Atlas配置/配置/ 2>/dev/null");
    
    FILE *file1 = fopen("/data/Atlas配置/配置/", "r");
    if (file1 == NULL) {
        std::ofstream ofs("/data/Atlas配置/配置/");
        ofs.close();
    }

    system("mkdir -p /data/Atlas配置/配置/ 2>/dev/null");

	
	
	
    cout<<"请输入1\n";
    printf("请输入1\n");
    int wht=1;
   cin>>wht;
    if(wht==0){	
    pid_t pid = fork();
    if (pid > 0) {
    exit(0);
    } else if (pid == 0) {  
    } else {
    fprintf(stderr, "创建子进程失败，请联系作者解决问题\n");
    exit(1);
    }
    }
    screen_config();
    ::abs_ScreenX = (::displayInfo.height > ::displayInfo.width ? ::displayInfo.height : ::displayInfo.width);
    ::abs_ScreenY = (::displayInfo.height < ::displayInfo.width ? ::displayInfo.height : ::displayInfo.width);

    ::native_window_screen_x = (displayInfo.height > displayInfo.width ? displayInfo.height : displayInfo.width);
    ::native_window_screen_y = (displayInfo.height > displayInfo.width ? displayInfo.height : displayInfo.width);
    if (init_egl(::native_window_screen_x, ::native_window_screen_y)) {
        ImGui_init();
    } else {
        printf("\033[31;1m"); // 红色
        printf("[!] EGL err\n");
        exit(1);
    }
    Touch::Init({(float)::abs_ScreenX, (float)::abs_ScreenY}, false); // 要想模拟触摸最后一个参数改成 false

    Touch::setOrientation(displayInfo.orientation);
    while (true) {
       drawBegin();
        tick();
        drawEnd();
    }

    shutdown();

    return 0;
}
