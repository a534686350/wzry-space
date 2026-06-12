/*
    By 开源 公益分享
   搜集各个公益文件分享
    更多公益开源 文件 @BYYXnb
*/
#include <stdio.h> 
#include <stdlib.h> 
#include <string.h> 
#include <sys/mman.h> 
#include <fcntl.h>


int Key() {
    char filename[256];
    sprintf(filename, "/proc/%d/maps", pid);
    FILE* handle = fopen(filename, "r");
    if (!handle) {
        perror("fopen");
        return -1;
    }

    char line[1024];
    while (fgets(line, sizeof(line), handle)) {
        if (strstr(line, "[anon:libc_malloc]")) {
            unsigned long start, end;
            if (sscanf(line, "%lx-%lx", &start, &end) == 2) {
                size_t size = end - start;
                void* addr = mmap(NULL, size, PROT_READ, MAP_PRIVATE, pid, start);
                if (addr == MAP_FAILED) {
                    perror("mmap");
                    continue; // 跳过这个区域，尝试下一个
                }

                unsigned int* p = (unsigned int*)addr;
                for (size_t i = 0; i < size / sizeof(unsigned int); ++i) {
                    if (ReadDword(p + i) == 256) {
                        //printf("Found 256 at address: %p\n", (void*)(p + i));
                    
                
                        // 检查0x50字节后的地址
                        void* next_addr = (char*)(p + i) + 0x50;
                        if (next_addr < (char*)addr + size) {
                            if (ReadDword(next_addr) == 256) {
                                printf("Also found 256 at address: %p\n", next_addr);
                            }
                        }
                    }
                }
                if (munmap(addr, size) == -1) {
                    perror("munmap");
                }
            }
        }
    }

    fclose(handle);
    return 0;
}
/*
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <fcntl.h>
#include <unistd.h>

#define ReadDword(addr) (*((unsigned int*)(addr)))

int main() {
    pid_t pid = ...; // 进程ID
    char filename[256];
    sprintf(filename, "/proc/%d/maps", pid);
    FILE* handle = fopen(filename, "r");
    if (!handle) {
        perror("fopen");
        return -1;
    }

    char line[1024];
    while (fgets(line, sizeof(line), handle)) {
        if (strstr(line, "[anon:libc_malloc]")) {
            unsigned long start, end;
            if (sscanf(line, "%lx-%lx", &start, &end) == 2) {
                size_t size = end - start;
                void* addr = mmap(NULL, size, PROT_READ, MAP_PRIVATE, pid, start);
                if (addr == MAP_FAILED) {
                    perror("mmap");
                    continue; // 跳过这个区域，尝试下一个
                }

                unsigned int* p = (unsigned int*)addr;
                for (size_t i = 0; i < size / sizeof(unsigned int); ++i) {
                    if (ReadDword(p + i) == 256) {
                        printf("Found 256 at address: %p\n", (void*)(p + i));

                        // 检查0x50字节后的地址
                        void* next_addr = (char*)(p + i) + 0x50;
                        if (next_addr < (char*)addr + size) {
                            if (ReadDword(next_addr) == 256) {
                                printf("Also found 256 at address: %p\n", next_addr);
                            }
                        }
                    }
                }

                if (munmap(addr, size) == -1) {
                    perror("munmap");
                }
            }
        }
    }

    fclose(handle);
    return 0;
}
*/