/*
    By 开源 公益分享
   搜集各个公益文件分享
    更多公益开源 文件 @BYYXnb
*/
#include <sys/fcntl.h>
#include <sys/ioctl.h>
#include <stdio.h>
#include <stdlib.h>
#include <dirent.h>
#include <sys/stat.h>
#include <string.h>
#include <ctype.h>
#include <fstream>
#include <sstream>
#include <string.h>
#include <time.h>
#include <ctype.h>
#include <map>
#include <vector>
#include <algorithm>
#include <unistd.h>
#include <chrono>
#include <sys/types.h>
#include <fcntl.h>
#include <linux/kdev_t.h>
#include <sys/sysmacros.h>
#include <iostream>
#include <ctime>
#include "obfuscate.h"
using namespace std;
float px,py;
class c_driver {
private:
char *ayf = AY_OBFUSCATE("-------------------------");
	int has_upper = 0;
	int has_lower = 0;
	int has_symbol = 0;
	int has_digit = 0;
	int fd;
	pid_t pid;

	typedef struct _COPY_MEMORY {
		pid_t pid;
		uintptr_t addr;
		void* buffer;
		size_t size;
	} COPY_MEMORY, *PCOPY_MEMORY;

	typedef struct _MODULE_BASE {
		pid_t pid;
		char* name;
		uintptr_t base;
	} MODULE_BASE, *PMODULE_BASE;

	enum OPERATIONS {
		OP_INIT_KEY = 0x800,
		OP_READ_MEM = 0x801,
		OP_WRITE_MEM = 0x802,
		OP_MODULE_BASE = 0x803,
	};
	int symbol_file(const char *filename) {
		//判断文件名是否含小写并且不含大写不含数字不含符号
		int length = strlen(filename);
		for (int i = 0; i < length; i++) {
			if (islower(filename[i])) {
				has_lower = 1;
			} else if (isupper(filename[i])) {
				has_upper = 1;
			} else if (ispunct(filename[i])) {
				has_symbol = 1;
			} else if (isdigit(filename[i])) {
				has_digit = 1;
			}
		}
		return has_lower && !has_upper && !has_symbol && !has_digit;
	}
	
	char *driver_path() {
    struct dirent *de;
    DIR *dr = opendir("/proc");
    char *device_path = NULL;

    if (dr == NULL) {
      printf("Could not open /proc directory");
      return NULL;
    }



    while ((de = readdir(dr)) != NULL) {
      if (strlen(de->d_name) != 6 || strcmp(de->d_name, "NVTSPI") == 0 || strcmp(de->d_name, "ccci_log") == 0 || strcmp(de->d_name, "aputag") == 0 || strcmp(de->d_name, "asound") == 0 || strcmp(de->d_name, "clkdbg") == 0 || strcmp(de->d_name, "crypto") == 0 || strcmp(de->d_name, "modules") == 0 || strcmp(de->d_name, "mounts") == 0 || strcmp(de->d_name, "pidmap") == 0 || strcmp(de->d_name, "phoenix") == 0 || strcmp(de->d_name, "uptime") == 0 || strcmp(de->d_name, "vmstat") == 0) {
        continue;
      }
      int is_valid = 1;
      for (int i = 0; i < 6; i++) {
        if (!isalnum(de->d_name[i])) {
          is_valid = 0;
          break;
        }
      }
        if (is_valid) {
            device_path = (char*)malloc(11 + strlen(de->d_name));
            sprintf(device_path, "/proc/%s", de->d_name);
            struct stat sb;
            if (stat(device_path, &sb) == 0 && S_ISREG(sb.st_mode)) {
                break;
            } else {
                free(device_path);
                device_path = NULL;
            }
        }
    }
    puts(device_path);
    closedir(dr);
    return device_path;
  }
  
   char *find_driver_path() {
    // 打开目录
        const char *dev_path = "/dev";
        DIR *dir = opendir(dev_path);
        if (dir == NULL){
            printf("无法打开/dev目录\n");
            return NULL;
        }

        char *files[] = { "wanbai", "CheckMe", "Ckanri", "lanran","video188"};
        struct dirent *entry;
        char *file_path = NULL;
        while ((entry = readdir(dir)) != NULL) {
            // 跳过当前目录和上级目录
            if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
                continue;
            }

            size_t path_length = strlen(dev_path) + strlen(entry->d_name) + 2;
            file_path = (char *)malloc(path_length);
            snprintf(file_path, path_length, "%s/%s", dev_path, entry->d_name);
            for (int i = 0; i < 5; i++) {
                if (strcmp(entry->d_name, files[i]) == 0) {
                    //printf("驱动文件：%s\n", file_path);
                    closedir(dir);
                    return file_path;
                }
            }

            // 获取文件stat结构
            struct stat file_info;
            if (stat(file_path, &file_info) < 0) {
                free(file_path);
                file_path = NULL;
                continue;
            }

            // 跳过gpio接口
            if (strstr(entry->d_name, "gpiochip") != NULL) {
                free(file_path);
                file_path = NULL;
                continue;
            }

            // 检查是否为驱动文件
            if ((S_ISCHR(file_info.st_mode) || S_ISBLK(file_info.st_mode))
                && strchr(entry->d_name, '_') == NULL && strchr(entry->d_name, '-') == NULL && strchr(entry->d_name, ':') == NULL) {
                // 过滤标准输入输出
                if (strcmp(entry->d_name, "stdin") == 0 || strcmp(entry->d_name, "stdout") == 0
                    || strcmp(entry->d_name, "stderr") == 0) {
                    free(file_path);
                    file_path = NULL;
                    continue;
                }
                
                size_t file_name_length = strlen(entry->d_name);
                time_t current_time;
                time(&current_time);
                int current_year = localtime(&current_time)->tm_year + 1900;
                int file_year = localtime(&file_info.st_ctime)->tm_year + 1900;
                //跳过1980年前的文件
                if (file_year <= 1980) {
                    free(file_path);
                    file_path = NULL;
                    continue;
                }
                
                time_t atime = file_info.st_atime;
                time_t ctime = file_info.st_ctime;
                // 检查最近访问时间和修改时间是否一致并且文件名是否是symbol文件
                if ((atime == ctime)/* && symbol_file(entry->d_name)*/) {
                    //检查mode权限类型是否为S_IFREG(普通文件)和大小还有gid和uid是否为0(root)并且文件名称长度在7位或7位以下
                    if ((file_info.st_mode & S_IFMT) == 8192 && file_info.st_size == 0
                        && file_info.st_gid == 0 && file_info.st_uid == 0 && file_name_length <= 9) {
                       // printf("驱动文件：%s\n", file_path);
                       printf("dev节点 连接成功");
                        closedir(dir);
                        return file_path;
                    }
                }
            }
            free(file_path);
            file_path = NULL;
        }
        closedir(dir);
        return NULL;
    }
 
  
  
  
public:
  // 构造函数：初始化驱动
  c_driver() {
 int isll=1;
    printf("选择你刷入的驱动(输入1是RT驱动 | 输入2是QX驱动)\n");
    scanf("%d", &isll); 
    char *device_name;
    if(isll==1)
  device_name= find_driver_path();
  else
  device_name= driver_path();
    if (!device_name) {
      fprintf(stderr, "未找到驱动文件\n");
      exit(EXIT_FAILURE);
    }

    fd = open(device_name, O_RDWR);
    free(device_name);

    if (fd == -1) {
      perror("[-] 链接驱动失败");
     exit(EXIT_FAILURE);
    }
  }
	~c_driver() {
		//wont be called
		if (fd > 0)
			close(fd);
	}
                   	
	void initialize(pid_t pid) {
		this->pid = pid;
	}
	
	
        
	
	bool init_key(char* key) {
		char buf[0x100];
		strcpy(buf,key);
		if (ioctl(fd, OP_INIT_KEY, buf) != 0) {
			return false;
		}
		return true;
	}

	bool read(uintptr_t addr, void *buffer, size_t size) {
	addr=addr&0xFFFFFFFFFFFF;
		COPY_MEMORY cm;

		cm.pid = this->pid;
		cm.addr = addr;
		cm.buffer = buffer;
		cm.size = size;

		if (ioctl(fd, OP_READ_MEM, &cm) != 0) {
			return false;
		}
		return true;
	}

	bool write(uintptr_t addr, void *buffer, size_t size) {
		COPY_MEMORY cm;

		cm.pid = this->pid;
		cm.addr = addr;
		cm.buffer = buffer;
		cm.size = size;

		if (ioctl(fd, OP_WRITE_MEM, &cm) != 0) {
			return false;
		}
		return true;
	}

	template <typename T>
	T read(uintptr_t addr) {
		T res;
		if (this->read(addr, &res, sizeof(T)))
			return res;
		return {};
	}

	template <typename T>
	bool write(uintptr_t addr,T value) {
		return this->write(addr, &value, sizeof(T));
	}

	uintptr_t getModuleBase(char*module_name)
{
	char*phgsr;
	char jjjj_N[64];
	long startaddr = 0;
	char path[256],line[1024];
	bool bssOF = false,LastIsSo = false;
	strcpy(jjjj_N,module_name);
	phgsr = strtok(jjjj_N,":");
	module_name = phgsr;
	phgsr = strtok(NULL,":");
	if(phgsr)
	{
		if(strcmp(phgsr,"bss")==0)
		{
			bssOF = true;
		}
	}
	sprintf(path,"/proc/%d/maps",pid);
	FILE*p = fopen(path,"r");
	if(p)
	{
		while(fgets(line,sizeof(line),p))
		{
			if(LastIsSo)
			{
				if(strstr(line,"[anon:.bss]")!=NULL)
				{
					sscanf(line,"%lx-%*lx",&startaddr);
					break;
				}
				else
				{
					LastIsSo = false;
				}
			}
			if(strstr(line,module_name)!=NULL)
			{
				if(!bssOF)
				{
					sscanf(line,"%lx-%*lx",&startaddr);
					break;
				}
				else
				{
					LastIsSo = true;
				}
			}
		}
		fclose(p);
	}
	return startaddr;
}


	
};
static c_driver *driver = new c_driver();
//读写
typedef char PACKAGENAME;	// 包名
pid_t pid;	// 进程ID
char *getDirectory()
{
	static char buf[128];
	int rslt = readlink("/proc/self/exe", buf, sizeof(buf) - 1);
	if (rslt < 0 || (rslt >= sizeof(buf) - 1))
	{
		return NULL;
	}
	buf[rslt] = '\0';
	for (int i = rslt; i >= 0; i--)
	{
		if (buf[i] == '/')
		{
			buf[i] = '\0';
			break;
		}
	}
	return buf;
}

int getPID(char* PackageName)
{
	FILE* fp;
    char cmd[0x100] = "pidof ";
    strcat(cmd, PackageName);
    fp = popen(cmd,"r");
    fscanf(fp,"%d", &pid);
    pclose(fp);
	if (pid > 0)
	{
		driver->initialize(pid);
	}
    return pid;
}

bool PidExamIne()
{
	char path[128];
	sprintf(path, "/proc/%d",pid);
	if (access(path,F_OK) != 0)
	{
		printf("\033[31;1m");
		puts("[!] 获取进程PID失败!");
		exit(1);
	}
	return true;
}

uintptr_t getModuleBase(char* module_name)
{
	uintptr_t base=0;
	base = driver->getModuleBase(module_name);
	return base;
}


long ReadValue(long addr)
{
	long he=0;
	if (addr < 0xFFFFFFFF){
		driver->read(addr, &he, 4);
	}else{
		driver->read(addr, &he, 8);
	}
	he=he&0xFFFFFFFFFFFF;
	return he;
}

long ReadDword(long addr)
{
	long he=0;
	driver->read(addr, &he, 4);
	return he;
}

float ReadFloat(long addr)
{
	float he=0;
	driver->read(addr, &he, 4);
	return he;
}

int WriteDword(long int addr, int value)
{
	driver->write(addr, &value, 4);
	return 0;
}

int WriteFloat(long int addr, float value)
{
	driver->write(addr, &value, 4);
	return 0;
}
// 获取基址
unsigned long get_module_base(int pid, const char *module_name)
{
	FILE *fp;
	unsigned long addr = 0;
	char *pch;
	char filename[64];
	char line[1024];
	//char *xa = "r-xp";
	snprintf(filename, sizeof(filename), "/proc/%d/maps", pid);
	fp = fopen(filename, "r");
	if (fp != NULL)
	{
		while (fgets(line, sizeof(line), fp))
		{
			if (strstr(line, module_name) && strstr(line, "r-xp"))
			{
				pch = strtok(line, "-");
				addr = strtoul(pch, NULL, 16);
				if (addr == 0x8000)
					addr = 0;
				break;
			}
		}
		fclose(fp);
	}
	return addr;
}

// 获取BSS模块地址
long get_module_bss(int pid, const char *szModule)
{
    FILE *fp;
    int cnt = 0;
    long start;
    char tmp[256];
    fp = NULL;
    char line[1024];
    char name[128];
    sprintf(name, "/proc/%d/maps", pid);
    fp = fopen(name, "r");
    while (!feof(fp))
    {
        fgets(tmp, 256, fp);
        if (cnt == 1)
        {
            if (strstr(tmp, "[anon:.bss]") != NULL)
            {
                sscanf(tmp, "%lx-%*lx", &start);
                break;
            }
            else
            {
                cnt = 0;
            }
        }
        if (strstr(tmp, szModule) != NULL)
        {
            cnt = 1;
        }
    }
    fclose(fp);
    return start;
}

struct Vector2A {
    float X;
    float Y;

    Vector2A() {
        this->X = 0;
        this->Y = 0;
    }

    Vector2A(float x, float y) {
        this->X = x;
        this->Y = y;
    }
};


struct Vector3A {
    float X;
    float Y;
    float Z;

    Vector3A() {
        this->X = 0;
        this->Y = 0;
        this->Z = 0;
    }

    Vector3A(float x, float y, float z) {
        this->X = x;
        this->Y = y;
        this->Z = z;
    }

};

struct Vector3{
    //这边重定义一个xzy形式的坐标(因为u3d是xzy);
    float X;
    float Z;
    float Y;

    Vector3() {
        this->X = 0;
        this->Z = 0;
        this->Y = 0;
    }

    Vector3(float x, float z, float y) {
        this->X = x;
        this->Z = z;
        this->Y = y;
    }
};