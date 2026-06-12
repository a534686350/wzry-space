#include "IsCharacter.h"
#include "include.h"
#include <iomanip>
#include <ctime>
#define BYTE4 0x00000004
#define BYTE8 0x00000008
#define BYTE16 0x00000010
#define BYTE10 0x000000A
#define BYTE64 0x00000040
#define BYTE1024 0x00000400
/*
uintptr_t unity;
uintptr_t unitybss;
uintptr_t GameCore;
uintptr_t GameCorebss;
uintptr_t il2cpp;
uintptr_t il2cppbss;
uintptr_t zuobiao;
*/
static uint64_t lil2cpp_base = 0;
static uint64_t lil2cpp_bss = 0;
static uint64_t libGame_base = 0;
static uint64_t libunity_base = 0;
static uint64_t libtersafe_base = 0;
static uint64_t duan_addr = 0;
 static uint64_t rc_dxl_id = 0;  //初始化顶部技能存储血量颜色判断int 数据;
 static uint64_t rc_xxl_id = 0;  //初始化小地图存储血量颜色判断
float offset_y = 25.0f; // 向下移动的距离
float thickness = 3.0f; // 长方形条的厚度（高度保持不变，宽度减半）




uint64_t MatrixAddress = 0; // 初始化矩阵地址
float isGames;              // 储存矩阵的值
int foeComp;                // 敌方阵营
int rientation = 0;         // 对局方向
int AroundNumbers = 0;      // 人头数量
int 视野 = 0;
int 自身视野 = 0;
long coordinatex, coordinatey;
long rotatingdraw = 0;
float 距离敌人 = 0;
int 惩戒伤害 = 0;
bool 召唤师技能判断 = false;
uintptr_t 对象坐标;
long zuo[5];
int biao[5];
long zuobiao;
int j = 0;
int 坐标X = 0;


int dr;
int dr_x[5];
int dr_y[5];


// ImVec2 自身坐标;
// ImTextureID FloatBall;
/*
void DrawLogo(ImVec2 center, float size)
{
        ImGui::SetCursorPos({0, 180});
        ImDrawList *draw_list = ImGui::GetWindowDrawList();
        draw_list->AddImage(FloatBall,{center.x - size / 2, center.y - size /
2},{center.x + size / 2, center.y + size / 2});

}
*/
/*
pid_t get_name_pid(char* name) {
    FILE* fp;
    pid_t pid;
    char cmd[0x100] = "pidof ";

    strcat(cmd, name);
    fp = popen(cmd,"r");
    fscanf(fp,"%d", &pid);
    pclose(fp);
    return pid;
}
*/
struct Coord {
  float X; // X轴
  float Y; // Y轴
  float W;
  float H;
};
struct DynamicData {
  struct Coord coord; // 获取xy坐标
};

struct HeroTemp {
  struct Coord coord; // 获取xy坐标
  int Hp;             // 当前血量
  int MaxHp;          // 最大血量
  int Id;             // 英雄id
  int Space;          // 大招cd
  int Space2;          // 2技能cd
  int Space1;          // 1技能cd
  int Spaceid;			//大招ID
  int Space2id;			//2技能ID
  int Space1id;			//1技能ID
  int Skill;          // 召唤师技能cd
  int Space3;
  int camp;           // 阵营id
  int HC;             // 回城
  
   int TB; 
   int TB1;  
    int TB2;  
     int TB3; 
      int TB4;                       // 图标             // 图标
  int confound;       // 坐标混淆
};
/**
 * 获取野怪xy和刷新时间
 */
struct Pve {
  uint64_t X; // X坐标地址
  uint64_t Y; // Y坐标地址
  uint64_t id;
  uint64_t cd; // 刷新时间
  uint64_t cc;
  uint64_t maxcd;
  uint64_t hp;
  uint64_t maxhp;
};
/**
 * 获取野怪xy和刷新时间
 */

struct PveTemp {
  struct Coord coord; // 获取xy坐标
  int hp;
  int maxhp;
  int id;
  int cd; // 刷新时间
  int maxcd;
};

/**
     * 获取dataTable.CeTemp[cont].coord.Xy
 */
struct Pvc {
  uint64_t X; // X坐标地址
  uint64_t Y; // Y坐标地址
};
/**
 * 单个兵线
 */
struct CeTemp {
  struct Coord coord; // 获取xy坐标
  int cd;
};

typedef struct {
  float Matrix[BYTE16];                   // 相机矩阵
  struct HeroTemp heroTemp[BYTE16];       // 储存单个英雄
  struct DynamicData DynamicData[BYTE16]; // 储存单个英雄大地图
  struct Pve pve[BYTE1024];               // 储存野怪
  struct PveTemp pveTemp[BYTE1024];       // 储存野怪
  struct CeTemp CeTemp[BYTE1024];         // 储存兵线
  struct Pvc pvc[BYTE1024];               // 储存兵线
  int xbsl;                               // 小兵数量
  
} DataTable;

DataTable dataTable; // 游戏数据表

const static ImColor Paint_chired = ImColor(219, 77, 110);   // 赤红
const static ImColor Paint_purple = ImColor(255, 0, 255);    // 紫色
const static ImColor Paint_red = ImColor(255, 0, 0);         // 红色
const static ImColor Paint_white = ImColor(255, 255, 255);   // 白色
const static ImColor Paint_lightblue = ImColor(0, 255, 255); // 浅蓝色
const static ImColor Paint_yellow = ImColor(255, 255, 0);    // 黄色
const static ImColor Paint_green = ImColor(0, 255, 0);       // 绿色
const static ImColor Paint_pink = ImColor(255, 192, 203); // 一个常见的粉色
const static ImColor Paint_orange = ImColor(255, 165, 0);      // 橙色
const static ImColor Paint_blue = ImColor(0, 0, 255);          // 蓝色
const static ImColor Paint_cyan = ImColor(0, 255, 255);        // 青色
const static ImColor Paint_magenta = ImColor(255, 0, 255);     // 品红色
const static ImColor Paint_black = ImColor(0, 0, 0);           // 黑色
const static ImColor Paint_gray = ImColor(128, 128, 128);      // 灰色
const static ImColor Paint_lightgray = ImColor(200, 200, 200); // 浅灰色
const static ImColor Paint_brown = ImColor(165, 42, 42);       // 褐色
const static ImColor Paint_peachpuff = ImColor(255, 218, 185); // 桃色
const static ImColor Paint_gold = ImColor(255, 215, 0);        // 金色
const static ImColor Paint_silver = ImColor(192, 192, 192);    // 银色
const static ImColor Paint_maroon = ImColor(128, 0, 0);        // 栗色
const static ImColor Paint_navy = ImColor(0, 0, 128);          // 海军蓝
const static ImColor Paint_teal = ImColor(0, 128, 128);        // 青绿色
const static ImColor Paint_lime = ImColor(0, 255, 0);          // 酸橙色
const static ImColor Paint_olive = ImColor(128, 128, 0);       // 橄榄绿
 
double Wwra = 0; // 转小地图算法

struct Coord 自身_coord = {0}; /*实际地图坐标*/
struct Coord map_coord = {0}; /*实际地图坐标*/
struct Coord map_buff = {0};  /*实际地图坐标*/
struct Coord Pvc_coord = {0}; /*实际地图坐标*/
double 计算距离(ImVec2 thisXY, ImVec2 xy) {
  double distance = sqrt(pow(xy.x - thisXY.x, 2) + pow(xy.y - thisXY.y, 2));
  return distance;
}


ImVec2 自身坐标;
ImVec2 自身距离;
ImVec2 目标坐标;
ImVec2 野怪坐标;
ImVec2 实体野怪;
ImVec2 自身实体视野;
ImVec2 野怪坐标缓存;

ImTextureID tubiaoid;
ImTextureID tubiaoid2;
ImTextureID tubiaoid3;
ImTextureID tubiaoid4;

long coorpage = 0;
long offset = 0;

long cooroffest = 0;

/*void gotpid() {
  int pid = getPID("com.tencent.tmgp.sgame");
  printf("\033[36;1m"); // 蓝色
  if (pid == 0 || pid == -1) {
    puts("[-] PID: 游戏未运行");
  } else {
    printf("[-] PID: %d\n", pid);
  }

  yxpx = abs_ScreenX;
  yxpy = abs_ScreenY;
}*/
 Coord CalMatrixMem(struct Coord coor, const float Matrix[]) {
    struct Coord points = {0};
    float XM = coor.X / 1000.0f;
    float ZM = coor.Y / 1000.0f;
    if(Matrix[11]&&Matrix[15]&&Matrix[0]&&Matrix[12]&&Matrix[9]&&Matrix[13]&&Matrix[1]&&Matrix[5] &&Matrix[9]&&Matrix[13]){
    float radio = (float)fabs(ZM * Matrix[11] + Matrix[15]);
    points.X = 0.0f, points.Y = 0.0f, points.W = 0.0f, points.H = 0.0f;
    if (radio > 0) {
        points.X = yxpx/2 + (XM * Matrix[0] + Matrix[12]) / radio * yxpx/2;
        points.Y = yxpy/2 - (ZM * Matrix[9] + Matrix[13]) / radio * yxpy/2;
        points.W = yxpy/2 - (XM * Matrix[1] + 4.0f * Matrix[5] + ZM * Matrix[9] + Matrix[13]) / radio * yxpy/2;
        points.H = ((points.Y - points.W) / 2.0f);
    }
    return points;
    }
}

void DrawInit() {
bool 基址 = false;
  while(!基址 && !stopFlag) {
  int pid = getPID("com.tencent.tmgp.sgame");
  printf("\033[36;1m"); // 蓝色
  if (pid != 0 && pid != -1) {
  libGame_base = getModuleBase( "libGameCore.so:bss");
  lil2cpp_base = getModuleBase("libil2cpp.so:bss");
  lil2cpp_bss = lil2cpp_base;
  //libunity_base = getModuleBase( "libunity.so");
  libtersafe_base = getModuleBase( "libtersafe.so");
  if (libGame_base != 0 && lil2cpp_base != 0 && lil2cpp_bss != 0 && libtersafe_base != 0) {
    printf("[-] PID: %d\n", pid);
  	基址 = true;
  	return;
  }
  }
  sleep(1);
  }
  
  // yxpx = abs_ScreenX;
  // yxpy = abs_ScreenY;

  /*
  if (fenblxx == 0) {
      int x1 = screen_x < screen_y ? screen_x : screen_y;
      int y1 = screen_x < screen_y ? screen_y : screen_x;
      if (yxpx < yxpy) {
          yxpx=x1;
          yxpy=y1;
      } else {
          yxpy=x1;
          yxpx=y1;
      }
  }
  */
  //printf("libGame_base  %p\n", libGame_base);
  //printf("lil2cpp_base  %p\n", lil2cpp_base);
}



ImVec2 Lerp(const ImVec2& a, const ImVec2& b, float t) {
    return ImVec2(a.x + (b.x - a.x) * t, a.y + (b.y - a.y) * t);
}

int linearSearch(long arr[], int n, int x) {
    for (int i = 0; i < n; i++) {
        if (arr[i] == x) {
            return 1; // 找到了
        }
    }
    return 0; // 没找到
}
int isApproximate(int num1, int num2, int error) {
    return fabs(num1 - num2) <= error;
}

// 判断数值是否接近数组中的任意一个数
int isApproximateInArray(int num, int array[], int error) {
    for (int i = 0; i < 5; i++) {
        if (isApproximate(num, array[i], error)) {
            return 1; // 找到接近的数
        }
    }
    return 0; // 未找到接近的数
}

std::pair<float, float> smoothPosition(int i, float currentPosX, float currentPosY, 
                                       std::vector<float>& prevPosX, std::vector<float>& prevPosY) {
    float targetPosX = currentPosX;
    float targetPosY = currentPosY;

    if (i < prevPosX.size() && i < prevPosY.size()) {
        targetPosX = prevPosX[i] + (currentPosX - prevPosX[i]) * 0.15f;
        targetPosY = prevPosY[i] + (currentPosY - prevPosY[i]) * 0.15f;
    }

    if (i >= prevPosX.size()) {
        prevPosX.resize(i + 1);
    }
    if (i >= prevPosY.size()) {
        prevPosY.resize(i + 1);
    }

    prevPosX[i] = targetPosX;
    prevPosY[i] = targetPosY;

    return std::make_pair(targetPosX, targetPosY);
}

void 解密算法() {
         				//zuobiao = zuobiao + 0x18;
         				//libGameCore.so + 0x3B91020 -> + 0x28 -> + 0x250 -> + 0x1BE
int a = 0;
zuobiao = ReadValue(ReadValue(ReadValue(libGame_base + 0x3B91020) + 0x28) + 0x250);
unsigned long start = zuobiao & (~(PAGE_SIZE - 1)) - PAGE_SIZE;
for (int i = 0; i < 4096; i++) {
long p = start + 0x4 * i;
if (driver->read<int>(p) == 256) {
int j = driver->read<int>(p - 0x8);
if (j == 0 || j == 200) {
int zuoX = driver->read<int>(p - 0xC);
if (linearSearch(zuo, 5, zuoX) == 0) {
	zuo[a] = p - 0xC;
	printf("ID[%d] = %p\n", a, zuo[a]);
	a++;
	if (a == 5) {
		a = 0;
	}

}
}
}
}
}
/**
 * 大地图转小地图坐标
 * @param foe
 * @return
 */
 
const static float TXdx =yxpy*0.020834f; //(头像)圈大小(22.5)半径
const static float TXdx1 = yxpy * 0.020534f; //(头像)圈大小(22.5)半径
const static float TXdx2 = yxpy * 0.015534f;
const static float soldiers_dx = yxpy * (double)1000.001851851851851851; //(兵)大小(2.0f)半径
const static float CDjs_X = yxpx * 0.337250f; //绘制cd起
const static float CDjs_Y = yxpy * 0.006481f; //绘制cd起点Y(7)
const static float intervalcdX = yxpx * 0.025200f; //60(cd左右间隔)
const static float intervalcdX1 = intervalcdX+150.0f;
const static float intervalcdX2 = intervalcdX+150.0f;
const static float intervalcdX3 = intervalcdX+150.0f;
const static float intervalcdX4 = intervalcdX+150.0f;
const static float intervalcdY = yxpy * 0.032407f+上下间隔; //35(cd上下间隔)
const static float intervalcdY1 = intervalcdY+20.0f;
const static float skills_dx = yxpy * (double)0.006481481481481481;
const static float skills_txt_dx = skills_dx * 4.0f;    //技能CD文字大小
const static float skills_txt_dx1 = skills_dx * 6.0f;
const static float CDjs_X1= yxpx * 0.30325f; //绘制cd起
const static float CDjs_Y1 = yxpy * 0.047777f; //绘制cd起点Y(7)
const static float intervalcdXX = yxpx * 0.027200f; //60(cd左右间隔)
const static float intervalcdYY = yxpy * 0.052407f; //35(cd上下间隔)
const static float skills_dx1 = yxpy * (double)0.006481481481481481;
const static float skills_txt_dx2 = skills_dx * 4.0f;    //技能CD文字大小
const static float 小地图误差 = yxpy * 0.012685185185185185;//--(小地图上下偏移)(13.7)
const static int 小地图误差X = yxpx * 0.05625;//--(小地图上下
 
void DrawTopskill(ImDrawList* Draw,ImTextureID handId,int id,long 蓝,long 红,int opop,float hp,int Space3,int Space2,int Space1,int Skill,int TB4)
{
int intvalue1 = TB4;
const static float CDjs_X1= yxpx * 0.30325f; //绘制cd起
const static float CDjs_Y1 = yxpy * 0.047777f; //绘制cd起点Y(7)
const static float intervalcdXX = yxpx * 0.027200f; //60(cd左右间隔)
const static float intervalcdYY = yxpy * 0.052407f; //35(cd上下间隔)
const static float skills_dx1 = yxpy * (double)0.006481481481481481;
const static float skills_txt_dx2 = skills_dx * 4.0f;    //技能CD文字大小
const static float TXdx =yxpy*0.020834f; //(头像)圈大小(22.5)半径
const static int 小地图误差X = yxpx * 0.05625;//--(小地图上下
float Theoffset_X = CDjs_X1 +小地图误差X-25 +jinenglanzX;
float Theoffset_Y = CDjs_Y1+jinenglanzY;

float CDdrawXY[9][2] = {
{(float)(Theoffset_X +  intervalcdX2 * opop-60), (float)(Theoffset_Y + intervalcdY * 0.5)},//大招
                  //大招左右                   //头像上下
{(float)(Theoffset_X +  intervalcdX1 * opop-55), (float)(Theoffset_Y + (TXdx) + intervalcdY1 * 1.5)},            //   血量框框                        //大招上下
{(float)(Theoffset_X +  intervalcdX3 * opop+20), (float)(Theoffset_Y + TXdx + intervalcdY1 * 0.3)},//召唤
{(float)(Theoffset_X +  intervalcdX4 * opop-10), (float)(Theoffset_Y + TXdx + intervalcdY1 *1.5)},//一
{(float)(Theoffset_X +  intervalcdX4 * opop+40), (float)(Theoffset_Y + TXdx + intervalcdY1 *1.5)},//
{(float)(Theoffset_X +  intervalcdX4 * opop-120), (float)(Theoffset_Y + TXdx + intervalcdY1 *1.0)},//
{(float)(Theoffset_X +  intervalcdX4 * opop-20), (float)(Theoffset_Y + TXdx + intervalcdY1 *2.85)},//6

};

//头像1/0 01 框框10 01 40 41 大招00 11 一技能30 31 二技能40 41 召唤20 21

//等于数组第二行第1个元素
CDdrawXY[1][0] = CDdrawXY[1][0] + (TXdx/14.0f); // x 二次改变偏移
CDdrawXY[0][1] = CDdrawXY[0][1] + (TXdx) + (TXdx/5.0f);
CDdrawXY[1][1] = CDdrawXY[1][1] + (TXdx) + (TXdx/5.0f);//绘制头像
//CDdrawXY[2][1] = CDdrawXY[2][1] + (TXdx) + (TXdx/5.0f);
CDdrawXY[3][1] = CDdrawXY[3][1] + (TXdx) + (TXdx/5.0f);
CDdrawXY[4][1] = CDdrawXY[4][1] + (TXdx) + (TXdx/5.0f);

Draw->AddImage(handId!= NULL ?  handId: 0, {CDdrawXY[1][0] - TXdx, CDdrawXY[0][1] - TXdx}, {CDdrawXY[1][0] + TXdx, CDdrawXY[0][1] + TXdx});//顶上头像

//顶上方框左右

ImVec2 rect_min = ImVec2(CDdrawXY[1][0] - 35 - dsfkzy, CDdrawXY[0][1] - 30 - dsfkzy); // 调整左上角坐标使方框缩小
ImVec2 rect_max = ImVec2(CDdrawXY[4][0] + 10 + dsfkzy, CDdrawXY[4][1] + 25 + dsfkzy); // 调整右下角坐标使方框缩小

ImU32 filled_color = ImGui::ColorConvertFloat4ToU32(ImVec4(1.0f, 1.0f, 1.0f, 0.2f)); // 透明白色填充颜色

ImU32 border_color = ImGui::ColorConvertFloat4ToU32(ImVec4(1.0f, 1.0f, 1.0f, 0.2f)); // 透明白色边框颜色
float rounding = 10.0f;
Draw->AddRect(rect_min, rect_max, border_color, rounding); // 绘制边框
Draw->AddRectFilled(rect_min, rect_max, filled_color, rounding); // 绘制填


if (蓝 == 257) {rc_dxl_id = id;} 
else
if (红 == 257) {rc_dxl_id = id;}
if (id == rc_dxl_id) 
{
Draw->AddCircleArc(ImVec2(CDdrawXY[1][0], CDdrawXY[0][1]),TXdx*0.98,ImVec2(0, 360), Paint_white,  0, 5); //白底背景
Draw->AddCircleArc(ImVec2(CDdrawXY[1][0], CDdrawXY[0][1]),TXdx*0.98, ImVec2(0, hp), Paint_red, 0, 5); //绿色圈边
} 
else 
{
Draw->AddCircleArc(ImVec2(CDdrawXY[1][0], CDdrawXY[0][1]),TXdx*0.98,ImVec2(0, 360), Paint_white,  0, 5); //白底背景
Draw->AddCircleArc(ImVec2(CDdrawXY[1][0], CDdrawXY[0][1]), TXdx*0.98, ImVec2(0, hp), Paint_green, 0, 5); //红色圈边
}

rc_dxl_id = 0;


//顶上绘制大招
if (Space3 > 0) {
int intvalue =Space3;
string test = std::to_string(intvalue);
ImVec2 textPosition = ImVec2(CDdrawXY[0][0] - (TXdx/10) - ImGui::CalcTextSize(test.c_str()).x / 2, (CDdrawXY[1][1] - (TXdx/2.4)));
Draw->AddText(NULL, skills_txt_dx, textPosition, Paint_red, test.c_str());
} else {


ImVec2 circleCenter(CDdrawXY[0][0] - (TXdx/15) - ImGui::CalcTextSize("大").x / 3, CDdrawXY[1][1] - (TXdx/5.4));
float radius = 15.0f; // 圆点的半径
Draw->AddCircleFilled(circleCenter, radius, Paint_red, 12); // 12表示圆的线段数，可以根据需要调整

}
if (Space2 > 0) {
int intvalue =Space2;
string test = std::to_string(intvalue);
ImVec2 textPosition = ImVec2(CDdrawXY[3][0] - (TXdx/10) - ImGui::CalcTextSize(test.c_str()).x / 2, (CDdrawXY[3][1] - (TXdx/2.4)));
Draw->AddText(NULL, skills_txt_dx, textPosition, Paint_white, test.c_str());
} else {
ImVec2 rectMin(CDdrawXY[3][0] - (TXdx/10) - ImGui::CalcTextSize("二").x / 3, CDdrawXY[3][1] - (TXdx/5.4));
Draw->AddCircleFilled(rectMin, 15.0f, Paint_lightblue);
}
if (Space1 > 0) {
int intvalue =Space1;
string test = std::to_string(intvalue);
ImVec2 textPosition = ImVec2(CDdrawXY[4][0] - (TXdx/10) - ImGui::CalcTextSize(test.c_str()).x / 2, (CDdrawXY[4][1] - (TXdx/2.4)));
Draw->AddText(NULL, skills_txt_dx, textPosition, Paint_white, test.c_str());
} else {

ImVec2 rectMin(CDdrawXY[4][0] - (TXdx/10) - ImGui::CalcTextSize("一").x / 3, CDdrawXY[4][1] - (TXdx/5.4));

Draw->AddCircleFilled(rectMin, 15.0f, Paint_green);
}


if (Skill > 0) {
ImTextureID tubiaoid;
if(TB4!=0){
if(TB4==801162){
	tubiaoid = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1- 800000]
                          .textureId);

}else{
tubiaoid2 = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1 - 80000]
                          .textureId);
}
}else{
tubiaoid2 = reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
}

ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

//绘制召唤师技能图片
Draw->AddImage(tubiaoid != NULL ?  tubiaoid: tubiaoid2, {(float)CDdrawXY[2][0] - TXdx, CDdrawXY[2][1] - TXdx}, {(float)CDdrawXY[2][0] + TXdx, CDdrawXY[2][1] + TXdx});


//绘制黑色背景图
ImVec2 circleCenter = {(CDdrawXY[2][0]), (CDdrawXY[2][1])};
float circleRadius = TXdx;
ImU32 black = IM_COL32(0, 0, 0, 100);
Draw->AddCircleFilled(circleCenter, circleRadius, black);


int intvalue = Skill;
string test = std::to_string(intvalue);
Draw->AddText(NULL, skills_txt_dx, ImVec2(CDdrawXY[2][0]-20, (CDdrawXY[2][1]-10)), Paint_white,test.c_str());//绘制文字
} else {
ImTextureID tubiaoid;
if(TB4!=0){
if(TB4==801162){
tubiaoid = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1- 800000]
                          .textureId);
}else{
tubiaoid2 = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1 - 80000]
                          .textureId);
}
}else{
tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
}
ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

//绘制召唤师技能图片
Draw->AddImage(tubiaoid!= NULL ?  tubiaoid: tubiaoid2, {(float)CDdrawXY[2][0] - TXdx, CDdrawXY[2][1] - TXdx}, {(float)CDdrawXY[2][0] + TXdx, CDdrawXY[2][1] + TXdx});
}
} 



void DrawBox(ImDrawList* Draw,int id,ImTextureID handId,float hp,float hpb,float maxhp,long 蓝,long 红)
{
//const static float TXdx =yxpy*0.020834f; //(头像)圈大小(22.5)半径
if (蓝 == 257) 
    {rc_xxl_id = id;}
     else
    if (红 == 257) 
    {rc_xxl_id = id;}
    





if (id == rc_xxl_id) 
{
ImVec2 rectMin((int)map_coord.X - (map_coord.H*0.4), (int)map_coord.Y - (map_coord.H*1.1f));
ImVec2 rectMax((int)map_coord.X + (map_coord.H*0.4), (int)map_coord.Y + (map_coord.H*0.1f));
暴露视野不绘 ? void(0) : Draw->AddRect(rectMin, rectMax, 颜色配置.方框颜色, 0.0f, 0, 2.5f);






float max_hp_percentage = 1.0f;
float current_hp_percentage = (float)hpb / (float)maxhp;
float bar_width = map_coord.H * 0.15f;  //血条宽度
float bar_height = map_coord.H * 1.25f;  //血条长度
float corner_radius = 5.0f;


// 绘制最大血量条填充
暴露视野不绘 ? void(0) : Draw->AddRectFilled(
    ImVec2((int)map_coord.X-20 - bar_width / 2 + corner_radius - (-map_coord.H*0.65f), (int)map_coord.Y + corner_radius - (map_coord.H*1.1f)),
    ImVec2((int)map_coord.X-20 + bar_width / 2 - corner_radius - (-map_coord.H*0.65f), (int)map_coord.Y + bar_height - corner_radius - (map_coord.H*1.2f)),
    Paint_white,
    corner_radius
);

// 计算当前血量条的填充高度和起始位置
float current_hp_fill_height = bar_height * current_hp_percentage;
float fill_correction_factor = 1.1f;  // 初始修正因子为1.35

// 根据敌人剩余血量百分比动态调整修正因子的值
if (current_hp_percentage <= 0.85f) {
    fill_correction_factor = 1.05f;
}

float current_hp_fill_start_y = (int)map_coord.Y + bar_height - current_hp_fill_height - (map_coord.H * fill_correction_factor);

// 绘制当前血量条填充
暴露视野不绘 ? void(0) : Draw->AddRectFilled(
    ImVec2((int)map_coord.X-20 - bar_width / 2 + corner_radius - (-map_coord.H * 0.65f), current_hp_fill_start_y),
    ImVec2((int)map_coord.X-20 + bar_width / 2 - corner_radius - (-map_coord.H * 0.65f), (int)map_coord.Y + bar_height - (map_coord.H * 1.2f)),
    Paint_green,
    corner_radius
);
}
else
{

ImVec2 rectMin((int)map_coord.X - (map_coord.H*0.4), (int)map_coord.Y - (map_coord.H*1.1f));
ImVec2 rectMax((int)map_coord.X + (map_coord.H*0.4), (int)map_coord.Y + (map_coord.H*0.1f));
Draw->AddRect(rectMin, rectMax, 颜色配置.方框颜色, 0.0f, 0, 2.5f);






float max_hp_percentage = 1.0f;
float current_hp_percentage = (float)hpb / (float)maxhp;
float bar_width = map_coord.H * 0.15f;  //血条宽度
float bar_height = map_coord.H * 1.25f;  //血条长度
float corner_radius = 5.0f;


// 绘制最大血量条填充
Draw->AddRectFilled(
    ImVec2((int)map_coord.X-20 - bar_width / 2 + corner_radius - (-map_coord.H*0.65f), (int)map_coord.Y + corner_radius - (map_coord.H*1.1f)),
    ImVec2((int)map_coord.X-20 + bar_width / 2 - corner_radius - (-map_coord.H*0.65f), (int)map_coord.Y + bar_height - corner_radius - (map_coord.H*1.2f)),
    Paint_white,
    corner_radius
);

// 计算当前血量条的填充高度和起始位置
float current_hp_fill_height = bar_height * current_hp_percentage;
float fill_correction_factor = 1.1f;  // 初始修正因子为1.35

// 根据敌人剩余血量百分比动态调整修正因子的值
if (current_hp_percentage <= 0.85f) {
    fill_correction_factor = 1.05f;
}

float current_hp_fill_start_y = (int)map_coord.Y + bar_height - current_hp_fill_height - (map_coord.H * fill_correction_factor);

// 绘制当前血量条填充
Draw->AddRectFilled(
    ImVec2((int)map_coord.X-20 - bar_width / 2 + corner_radius - (-map_coord.H * 0.65f), current_hp_fill_start_y),
    ImVec2((int)map_coord.X-20 + bar_width / 2 - corner_radius - (-map_coord.H * 0.65f), (int)map_coord.Y + bar_height - (map_coord.H * 1.2f)),
    Paint_green,
    corner_radius
);

}
rc_xxl_id = 0;

}

const char* Drawcall(int TB4) {
    const char* 召唤师文字 = "";

    if (TB4 == 80102) {
        召唤师文字 = "【治疗】";
    } else if (TB4 == 80103) {
        召唤师文字 = "【晕眩】";
    } else if (TB4 == 80104 || TB4 == 80116 || TB4 == 53391 || TB4 == 53392 || TB4 == 53393) {
        召唤师文字 = "【惩戒】";
    } else if (TB4 == 80105) {
        召唤师文字 = "【干扰】";
    } else if (TB4 == 80107) {
        召唤师文字 = "【净化】";
    } else if (TB4 == 80108) {
        召唤师文字 = "【斩杀】";
    } else if (TB4 == 80109) {
        召唤师文字 = "【疾跑】";
    } else if (TB4 == 80110) {
        召唤师文字 = "【狂暴】";
    } else if (TB4 == 80115) {
        召唤师文字 = "【闪现】";
    } else if (TB4 == 80121) {
        召唤师文字 = "【弱化】";
    }

    if (TB4 == 0) {
        召唤师文字 = "【无召唤】";
    }

    return 召唤师文字; 
}
void Drawskill(ImDrawList* Draw,int id,int Space4,int Space3,int Space2,int Space1,int Skill,int TB4,float TXdx)
{
//const static float TXdx =yxpy*0.020834f; //(头像)圈大小(22.5)半径

if(id==125 || id==153 || id==176 || id==179 || id==182 || id==191 || id==225 || id==507 || id==509)
{
if (Space4 > 0) 
{     
int intvalue = Space4;
string test = std::to_string(intvalue);
Draw->AddText(NULL, skills_txt_dx1, ImVec2((float)map_coord.X+ TXdx*2, (float)map_coord.Y+TXdx*0.8), Paint_lightblue,test.c_str());
} 
else 
{
ImVec2 center((float)map_coord.X + TXdx*2, (float)map_coord.Y+TXdx*1.2); // 圆心坐标
float radius = 10.0f; // 圆的半径
Draw->AddCircleFilled(center, radius, Paint_green);
}

}else
{
if (Space3 > 0) 
{                
int intvalue = Space3;
string test = std::to_string(intvalue);
Draw->AddText(NULL, skills_txt_dx1, ImVec2((float)map_coord.X+ TXdx*2, (float)map_coord.Y+TXdx*0.7), Paint_gold,test.c_str());
} 
else 
{
ImVec2 center((float)map_coord.X + TXdx*2, (float)map_coord.Y+TXdx*1.2); // 圆心坐标
float radius = 10.0f; // 圆的半径
Draw->AddCircleFilled(center, radius, Paint_red);
}
}
if (Space2 > 0) 
{
int intvalue = Space2;
string test = std::to_string(intvalue);
Draw->AddText(NULL, skills_txt_dx1, ImVec2((float)map_coord.X+ TXdx*0, (float)map_coord.Y+TXdx*0.7), Paint_peachpuff,test.c_str());
}
else 
{
ImVec2 center((float)map_coord.X + TXdx*0, (float)map_coord.Y+TXdx*1.2); // 圆心坐标
float radius = 10.0f; // 圆的半径
Draw->AddCircleFilled(center, radius, Paint_lightblue);
}
if (Space1 > 0) {               
int intvalue = Space1;
string test = std::to_string(intvalue);
Draw->AddText(NULL, skills_txt_dx1, ImVec2((float)map_coord.X- TXdx*2, (float)map_coord.Y+TXdx*0.7), Paint_lightblue,test.c_str());
} else {
ImVec2 center((float)map_coord.X - TXdx*2, (float)map_coord.Y+TXdx*1.2); // 圆心坐标
float radius = 10.0f; // 圆的半径
Draw->AddCircleFilled(center, radius, Paint_green);
}

if (Skill > 0) {

int intvalue =Skill;
string test = std::to_string(intvalue);
Draw->AddText(NULL, skills_txt_dx1, ImVec2((float)map_coord.X-10, (float)map_coord.Y-TXdx*7.2), Paint_lightblue,test.c_str());
} else {

const char* result = Drawcall(TB4);
//Draw->AddText(NULL, skills_txt_dx1, textPosition, Paint_lightblue,result);
Draw->AddText(NULL,skills_txt_dx1, ImVec2((int)map_coord.X-TXdx*2, (int)map_coord.Y-TXdx*7.2), Paint_lightblue,result);

}


rc_xxl_id = 0;

}
void Drawentity(ImDrawList* Draw,int id,ImTextureID handId,float hp,float TXdx,long 蓝,long 红)
{
if (蓝 == 257) 
    {rc_xxl_id = id;}
     else
    if (红 == 257) 
    {rc_xxl_id = id;}
    


if (id == rc_xxl_id) 
{
DrawIo[31] ? void(0) : Draw->AddImage(handId!= NULL ?  handId: 0,{(int)map_coord.X - TXdx, (int)map_coord.Y-TXdx}, {(int)map_coord.X + TXdx, (int)map_coord.Y+TXdx});
DrawIo[31] ? void(0) : Draw->AddCircleArc({(int)map_coord.X-TXdx*0, (int)map_coord.Y+TXdx*0}, TXdx*0.98, ImVec2(0, 360), Paint_white, 0, 4.0f);
DrawIo[31] ? void(0) : Draw->AddCircleArc({(int)map_coord.X-TXdx*0, (int)map_coord.Y-TXdx*0}, TXdx*0.98, ImVec2(0, hp), Paint_green, 0, 4.0f);
}
else
{
Draw->AddImage(handId!= NULL ?  handId: 0,{(int)map_coord.X - TXdx, (int)map_coord.Y-TXdx}, {(int)map_coord.X + TXdx, (int)map_coord.Y+TXdx});
Draw->AddCircleArc({(int)map_coord.X-TXdx*0, (int)map_coord.Y+TXdx*0}, TXdx*0.98, ImVec2(0, 360), Paint_white, 0, 4.0f);
Draw->AddCircleArc({(int)map_coord.X-TXdx*0, (int)map_coord.Y-TXdx*0}, TXdx*0.98, ImVec2(0, hp), Paint_green, 0, 4.0f);
}
}

void DrawPlayer() {
  if (libGame_base == 0 || lil2cpp_base == 0 || libtersafe_base == 0) {
    DrawInit();
  }
  if (libGame_base == 0 || lil2cpp_base == 0 || libtersafe_base == 0) {
    return;
  }

  const static float TXdx = yxpy * 0.017534f; //(头像)圈大小(22.5)半径
  const static float soldiers_dx =
      yxpy * (double)0.001851851851851851;           //(兵)大小(2.0f)半径
  const static float CDjs_X = yxpx * 0.337250f;      // 绘制cd起
  const static float CDjs_Y = yxpy * 0.006481f;      // 绘制cd起点Y(7)
  const static float intervalcdX = yxpx * 0.025200f; // 60(cd左右间隔)
  const static float intervalcdY = yxpy * 0.032407f; // 35(cd上下间隔)


std::string 设备id = imei;
    std::string gameDataStr = "";
    std::string character = "";// "不能动";
    std::string creeps = "";
    std::string soldier = "";



//惩戒依赖
//ImVec2 dr_r[5];

  const static float skills_dx = yxpy * (double)0.006481481481481481;
  const static float skills_txt_dx = skills_dx * 5.0f;   // 技能CD文字大小
  Wwra = yxpy / 10.9f * (1.574074075 + (jiange * 0.01)); // 间隔


  
long temp = ReadValue(lil2cpp_base +  0x43F600); // 8F547A8
  //矩阵数据cb

  MatrixAddress =
      ReadValue(ReadValue(ReadValue(temp + 0xb8) + 0x0) + 0x10) + 0x128;
  isGames = ReadFloat(MatrixAddress);
  // 判断敌方阵营id
  /*  ?  :   三目运算符  如果大于0就返回2 否则返回1 */
  foeComp = isGames > 0 ? 2 : 1;
  // LOGE("foeComp:%d",foeComp);
  // 判断阵营，获取算法，判断方向
 
  if (ReadDword(libGame_base + 0x191E7C) != 0) { // 对局判断
  //开局数据已结束：:
/*兵线: 0x18CE40
野怪: 0x345E8
矩阵: 0xBB29B0
开局: 0x1E934
技能线: 0xC004A8
无bug上帝: 0xE050
坐标:0x34C68
王者s37抢先服数据
3143936863qq*/


/*
[zm]0xC98440[zm]
[kj]0x20DBC[kj]开局
[jz]0xC45DD0[jz]矩阵
[zb]0x383A0[zb]坐标
[bx]0x195968[bx]兵线
[yg]0x37D10[yg]野怪
[sd]0xE750[sd]
[nt]0x254F180[nt]

*/

  //int tem = ReadDword(libGame_base + 0x1DEC2D4);
 // printf("对局判断 %d \n",tem);
    rientation = foeComp == 1 ? -1 : 1;

    for (int i = 0; i < 16; i++) {
      dataTable.Matrix[i] = ReadFloat(MatrixAddress + i * 4);
    }

    // ImGui::GetBackgroundDrawList()->AddImage(createTexture1("/storage/emulated/0/105.png").textureId,
    // ImVec2(100-25, 100-25), ImVec2(100+25, 100+25));
//long temp12 = ReadDword(libGame_base + 0x1E02AA0);
    long temp1 = ReadValue(libGame_base + 0x2540);
    //坐标数据//
       //printf("temp1 %d \n",temp12);
    long bingxiang8 = ReadValue(ReadValue(temp1 + 0x48) + 0xD8);
    long bingxiang1 = temp1 + 0x120;
    /*
      int
      自身ID=driver->read<int>(ReadValue(ReadValue(ReadValue(ReadValue(lil2cpp_base+0x8c470D8)
      +0xA0)+0x40)+0x50)+0x138);

      int
      测试ID=driver->read<int>(ReadValue(ReadValue(ReadValue(ReadValue(lil2cpp_base+0x264B0)
      +0xA0)+0x40)+0x170)+0x138);//libil2cpp.so:bss[1] + 0x264B0 -> + 0xA0 -> +
      0x40 -> + 0x170 -> + 0x138

            printf("ID : %d 测试: %d\n", 自身ID, 测试ID);
            */
if (十人) {
	AroundNumbers = 20;
} else {
    AroundNumbers = 10;
}
	int dr = 0;
    int opop = 0;
    int 控制状态 = 0;
    for (int i = 0; i < AroundNumbers; i++) {
      // int ndh = i * 0x18;

      //     long bingxiang6 =ReadValue( ReadValue(ReadValue(bingxiang1) +0x60
      //     )+ndh);
	  
	      // 存储平滑前的坐标
    std::vector<float> prevPosX(10, 0.0f);
    std::vector<float> prevPosY(10, 0.0f);
	  
	  
      long bingxiang6 = ReadValue(ReadValue(bingxiang1 + i * 0x18) + 0x68);

      int pand = 1;

      if (pand > 0) {
        int zhengxing = driver->read<int>(bingxiang6 + 0x3C);

        dataTable.heroTemp[i].Skill =
            driver->read<int>(
                ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0x150) +
                          0xf8) +
                0x3c) /
            8192000;

        dataTable.heroTemp[i].TB = driver->read<int>(
            ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0x150) + 0xC8) +
            0x10);
            
        /*dataTable.heroTemp[i].TB = driver->read<int>(
            ReadValue(ReadValue(ReadValue(bingxiang6 + 0x148) + 0x150) + 0x110) +
            0x500);*/

        dataTable.heroTemp[i].Id = ReadValue(bingxiang6 + 0x30);

        //
        dataTable.DynamicData[i].coord.X =
            (dataTable.heroTemp[i].coord.X * rientation * Wwra / 50000.0f +
             Wwra);
        dataTable.DynamicData[i].coord.Y =
            (dataTable.heroTemp[i].coord.Y * rientation * Wwra / 50000.0f * -1 +
             Wwra);

        float pos_x = dataTable.DynamicData[i].coord.X + SmallMapX + 93;
        float pos_y = dataTable.DynamicData[i].coord.Y + SmallMapY + 6;

        map_coord = CalMatrixMem(dataTable.heroTemp[i].coord, dataTable.Matrix);
        map_coord.X = map_coord.X + SmallHPX;
        map_coord.Y = map_coord.Y + SmallHPY;

        if (zhengxing != foeComp) {
          float 坐标x = (float)driver->read<int>(ReadValue(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x248) + 0x10)) +0x10) + 0x0); // 坐标输出的坐
          float 坐标y = (float)driver->read<int>(ReadValue(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x248) + 0x10)) +0x10) + 0x8); // 坐标输出的坐
          
          坐标X = (int)坐标x;

          dataTable.heroTemp[i].coord.X = (int)坐标x;
          dataTable.heroTemp[i].coord.Y = (int)坐标y;

          float 自身判断 = 计算距离(
              ImVec2(yxpx / 2, yxpy / 2),
              ImVec2(map_coord.X, map_coord.Y + (-map_coord.H * 1.0f)));
          // printf("自身判断 :%.0f\n", 自身判断);

          if (自身判断 <= 100 || 召唤师ID == dataTable.heroTemp[i].Id) {
            
            召唤师技能ID = dataTable.heroTemp[i].TB;
            召唤师技能 = 获取召唤师技能(dataTable.heroTemp[i].TB);
            召唤师技能CD = dataTable.heroTemp[i].Skill;
            召唤师ID = dataTable.heroTemp[i].Id;
            //printf("%d", dataTable.heroTemp[i].Id);
            召唤师 = PlayerCharacter(dataTable.heroTemp[i].Id);
            自身坐标 = ImVec2(dataTable.heroTemp[i].coord.X, dataTable.heroTemp[i].coord.Y);
            
            if(dataTable.heroTemp[i].Id==133)
            {
            召唤师技能CD = dataTable.heroTemp[i].Space;
            }
            //控制状态 = ReadDword(bingxiang6 - 0xB4);
            //沉默1 眩晕2 //击飞3 //冰冻4  正常0
            控制状态=driver->read<int>(driver->read<uint64_t>(driver->read<uint64_t>(driver->read<uint64_t>(driver->read<uint64_t>(driver->read<uint64_t>(libGame_base + 0x2540)+ 0x48) + 0xD8) + 0x108) + 0x110) +0x258);
            
            if (dataTable.heroTemp[i].TB == 80108 ||
                dataTable.heroTemp[i].TB == 80104 ||
                dataTable.heroTemp[i].TB == 80116 ||
                dataTable.heroTemp[i].TB == 80107 ||
                dataTable.heroTemp[i].TB == 801162) {
              
              
//printf("X %d\tY %d\n", (int)map_coord.X, (int)map_coord.Y);
              if(召唤师技能CD == 0) {
              	召唤师技能判断 = true;
            } else {
            	召唤师技能判断 = false;
            }
            }
          }
          
          
        }

        

        if (zhengxing == foeComp) {
          // //////头像id↓代码
dataTable.heroTemp[i].Hp =
            driver->read<int>(ReadValue(bingxiang6 + 0x168) + 0x98); // 当前血量

        dataTable.heroTemp[i].MaxHp =
            driver->read<int>(ReadValue(bingxiang6 + 0x168) + 0xa0);
            
        dataTable.heroTemp[i].HC = driver->read<int>(
            ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0x168) +
                      0x168) +
            0x20);
         long 蓝方敌人视野判断 =  driver->read<int>(ReadValue(ReadValue(bingxiang6 + 0x260)+0x68)+0x38);
          long 红方敌人视野判断 =  driver->read<int>(ReadValue(ReadValue(bingxiang6 + 0x260)+0x68)+0x18);
          if (zhengxing == 2) {
            视野 = driver->read<int>(
                ReadValue(ReadValue(bingxiang6 + 0x260) + 0x68) + 0x18);
            自身视野 = driver->read<int>(
                driver->read<uint64_t>(
                    driver->read<uint64_t>(bingxiang8 + 0x260) + 0x68) +
                0x38);
          } else {
            视野 = driver->read<int>(
                ReadValue(ReadValue(bingxiang6 + 0x260) + 0x68) + 0x38);
            自身视野 = driver->read<int>(
                driver->read<uint64_t>(
                    driver->read<uint64_t>(bingxiang8 + 0x260) + 0x68) +
                0x18);
          }
          if (自身) {
              if (自身视野 == 257) {
                绘制字体描边(31.0f,
                    ImVec2(屏幕x + yxpx / 2 * 0.970833333333333333,
                           屏幕y + yxpy / 2 * 0.787037037037037037),
                    Paint_red, "[暴露]");
                    /*ImGui::GetForegroundDrawList()->AddImage(
                      reinterpret_cast<ImTextureID>(其他贴图.图标[2].textureId),
					  ImVec2(屏幕x + yxpx - 50 / 2, 屏幕y + yxpy / 2 * 0.78703 - 50 / 4),ImVec2(屏幕x + yxpx + 50 / 2, 屏幕y + yxpy / 2 * 0.78703 + 50 / 4));*/
					  /*ImGui::GetForegroundDrawList()->AddImage(
                      reinterpret_cast<ImTextureID>(其他贴图.图标[1].textureId),
                      {(int)map_buff.X - (map_buff.H * 0.2f),
                       (int)map_buff.Y - (map_buff.H * 0.2f) +
                           (-map_buff.H * 1.0f)},
                      {(int)map_buff.X + (map_buff.H * 0.2f),
                       (int)map_buff.Y + (map_buff.H * 0.2f) +
                           (-map_buff.H * 1.0f)});*/
              } else {
                绘制字体描边(31.0f,
                    ImVec2(屏幕x + yxpx / 2 * 0.970833333333333333,
                           屏幕y + yxpy / 2 * 0.787037037037037037),
                    Paint_green, "[安全]");
              }
            }
//对象坐标=getcoor(bingxiang6);
          //long zuobiao = ReadValue(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x240) + 0x10)) +0x10);
		              

        //unsigned long 人物坐标 = driver->ValueLmultiple(StructureHeader + 0x2B8, {0x80, 0x20, 0x48});
        /*if (driver->read<bool>(zuobiao+ 0x7) == 0) {
    	    unsigned long start = zuobiao & (~(PAGE_SIZE - 1)) + PAGE_SIZE;
         	int offest = 0;
          	for (int i = 0; i < 1024; i++) {
         		if (driver->read<int>(start + 0x4 * i)) {
         			offest = 0x4 * i + PAGE_SIZE;
          			break;
         		}
    		}  

            //获取offest得到偏移
        	zuobiao= zuobiao + offest;
            //人物坐标加上偏移得到真实人物坐标
         	for (int v = 0; v < 4; v++) {
         		
         		if (driver->read<bool>(zuobiao+ 0xD + v) == 1) {
         			ImGui::GetForegroundDrawList()->AddText(ImVec2(0.0375 * yxpx, 0.925925925925925925 * yxpy), Paint_red, "坐标已加密");=
          			zuobiao= zuobiao+ v;
                  	//人物坐标加上偏移    		    		
         			break;
         		}  
            }
        }*/
        zuobiao = ReadValue(ReadValue(ReadValue(bingxiang6 + 0x248) + 0x10)) +0x10;
        if (ReadDword(zuobiao) != 0) {
        	zuobiao = ReadValue(zuobiao);//没加密就正常读 
        } else {
                
         			绘制字体描边(31.0f, ImVec2(0.0375 * yxpx, 0.925925925925925925 * yxpy), Paint_red, "坐标已加密");
         			zuobiao = ReadValue(zuobiao);
         			if (解密) {
         				if (j == 5) {
         					j = 0;
         				}
         				for (int i = 0; i < 5; i++) {
         					biao[i] = ReadDword(zuo[i]);
         					if (isApproximateInArray(坐标X, biao, 10)) {
        zuo[j] = zuo[i];
        break;
    }
         					
         				}
         				

         				zuobiao = zuo[j];
         				//printf("%lu\n%lu\n", zuobiao, zuo);
         				j = j + 1;
         			}
          			
            }
        
          /*if (视野 == 257) {
            float 坐标x = ReadFloat(bingxiang6 - 0x274) * 1000; // 坐标输出的坐
            float 坐标y = ReadFloat(bingxiang6 - 0x26C) * 1000; // 坐标输出的坐
            if (坐标x && 坐标y) {
              dataTable.heroTemp[i].coord.X = (float)坐标x;
              dataTable.heroTemp[i].coord.Y = (float)坐标y;
            } else {
            	dataTable.heroTemp[i].coord.X = (float)driver->read<int>(zuobiao + 0x0);
              	dataTable.heroTemp[i].coord.Y = (float)driver->read<int>(zuobiao + 0x8);
            }
          } else {
            float 坐标x = (float)driver->read<int>(zuobiao + 0x0);
            float 坐标y = (float)driver->read<int>(zuobiao + 0x8);
            if (坐标x && 坐标y) {
              dataTable.heroTemp[i].coord.X = (float)坐标x;
              dataTable.heroTemp[i].coord.Y = (float)坐标y;
            }
          }*/
            
            //ImVec2 旧坐标 = ImVec2((float)dataTable.heroTemp[i].coord.X, (float)dataTable.heroTemp[i].coord.Y);
            prevPosX[i] = (float)dataTable.heroTemp[i].coord.X;
            prevPosY[i] = (float)dataTable.heroTemp[i].coord.Y;
            
          	float 坐标x = (float)driver->read<int>(zuobiao + 0x0);
            float 坐标y = (float)driver->read<int>(zuobiao + 0x8);
            if (坐标x && 坐标y) {
              float currentPosX = (float)坐标x;
              float currentPosY = (float)坐标y;
			  
			  float 人物跨度X = currentPosX - prevPosX[i];
			  if (人物跨度X < 0) {
			  	人物跨度X = 人物跨度X * -1;
			  }
			  float 人物跨度Y = currentPosY - prevPosY[i];
			  if (人物跨度Y < 0) {
			  	人物跨度Y = 人物跨度Y * -1;
			  }
			  //printf("X %.0f\tY %.0f\n", 人物跨度X, 人物跨度Y);
			  if (人物跨度X > 1000 || 人物跨度Y > 1000) {
			  	dataTable.heroTemp[i].coord.X = currentPosX;
                dataTable.heroTemp[i].coord.Y = currentPosY;
			  } else {
			  
			  
			  std::pair<float, float> smoothedCoords = smoothPosition(i, currentPosX, currentPosY, prevPosX, prevPosY);
    		  
    		  
    		  dataTable.heroTemp[i].coord.X = smoothedCoords.first;
              dataTable.heroTemp[i].coord.Y = smoothedCoords.second;
            }
            } else {
            dataTable.heroTemp[i].coord.X = 9500;
            dataTable.heroTemp[i].coord.Y = 9500;
            
            }
            // 存储平滑前的坐标
    
        
          
        /*
        for (int i = 0; i <= steps; i++) {
        float t = (float)i / steps;
        ImVec2 smoothPos = Lerp(start, end, t); // 使用线性插值计算中间帧
        //DrawPoint(smoothPos); // 绘制中间帧
    }
          */
          
//printf("X %.0f  Y %.0f\n", dataTable.heroTemp[i].coord.X, dataTable.heroTemp[i].coord.Y);
          目标坐标 = ImVec2(dataTable.heroTemp[i].coord.X,
                            dataTable.heroTemp[i].coord.Y);
          距离敌人 = 计算距离(自身坐标, 目标坐标);
          
          
          
          //dr_x[dr] = ImVec2(dataTable.heroTemp[i].coord.X, dataTable.heroTemp[i].coord.Y);
          
          
          
          dr_x[dr] = dataTable.heroTemp[i].coord.X;
          dr_y[dr] = dataTable.heroTemp[i].coord.Y;
          dr++;
          

          /*
          dataTable.DynamicData[i].coord.X = (dataTable.heroTemp[i].coord.X *
          rientation * Wwra / 50000.0f + Wwra); dataTable.DynamicData[i].coord.Y
          = (dataTable.heroTemp[i].coord.Y * rientation * Wwra / 50000.0f * -1 +
          Wwra);

          float pos_x = dataTable.DynamicData[i].coord.X + SmallMapX+93;
          float pos_y = dataTable.DynamicData[i].coord.Y + SmallMapY+6;

          map_coord = CalMatrixMem(dataTable.heroTemp[i].coord,
          dataTable.Matrix); map_coord.X = map_coord.X + SmallHPX; map_coord.Y =
          map_coord.Y + SmallHPY;
          */
          




          float hp1 =
              dataTable.heroTemp[i].Hp * 100 / dataTable.heroTemp[i].MaxHp;

          float aa = hp1 * 3.6;
          //   ImColor 血量颜色;
          //     血量颜色 = ImColor(10,240,10,210);




              int 大招最大CD = driver->read<int>(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0x108) + 0xf8) + 0x3C) / 8192000;
        int Space3= driver->read<int>(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0x108) + 0xf8) + 0x3C) / 8192000;
          
    dataTable.heroTemp[i].TB4 = ReadValue(ReadValue(ReadValue
    (bingxiang6 + 0x150) + 0x150) + 0xe0) + 0x10;//召唤师技能
          if (ESPMenu.是否开启共享&&aa!=0) {
          
            //启用共享绘制传输变量
          int  zyz = driver->read<int>(bingxiang6 + 0x3C);  //阵营           
          int  herealx = (int)(dataTable.heroTemp[i].coord.X * rientation * 2400/2/11.2f*1.455 / 50000 + 2400/2/11.2f*1.455);
         int   herealy = (int)(dataTable.heroTemp[i].coord.Y * rientation * 2400/2/11.2f*1.455 / 50000 * -1 + 2400/2/11.2f*1.455);

            character += std::to_string(dataTable.heroTemp[i].Id)
                + "," + std::to_string(dataTable.heroTemp[i].Hp)
                + "," + std::to_string(dataTable.heroTemp[i].MaxHp)
                + "," + std::to_string(dataTable.heroTemp[i].Space3)
                + "," + std::to_string(dataTable.heroTemp[i].Skill)
                + "," + std::to_string(herealx - 20 + ESPMenu.小地图左右调整)//3技能时间
                + "," + std::to_string(herealy - 20 + ESPMenu.小地图上下调整)
                + "," + std::to_string(hp1)
                + "," + std::to_string(zhengxing)
                + "," + std::to_string(zyz)
                + "," + std::to_string(dataTable.heroTemp[i].HC)
               + "," + std::to_string(dataTable.heroTemp[i].TB4)
               + "," + std::to_string(大招最大CD)
                + "==";
                }



          ImTextureID handId;
          handId = reinterpret_cast<ImTextureID>(贴图1.头像[dataTable.heroTemp[i].Id].textureId);

 
          if (aa > 0 || dataTable.heroTemp[i].Id == 183) {
            if (血量) {
              if (视野 == 257) {
                if (头像常显) {
                  // 小头像血量
                  ImGui::GetForegroundDrawList()->AddCircleArc(
                      {pos_x, pos_y}, 21 + xiaodituxue, ImVec2(0, 360),
                      Paint_white, 0, 5.5f);
                  ImGui::GetForegroundDrawList()->AddCircleArc(
                      {pos_x, pos_y}, 21 + xiaodituxue, ImVec2(0, aa),
                      颜色配置.血量颜色, 0, 5.5f);
                }
              } else {
                // 小头像血量
                ImGui::GetForegroundDrawList()->AddCircleArc(
                    {pos_x, pos_y}, 21 + xiaodituxue, ImVec2(0, 360),
                    Paint_white, 0, 5.5f);
                ImGui::GetForegroundDrawList()->AddCircleArc(
                    {pos_x, pos_y}, 21 + xiaodituxue, ImVec2(0, aa),
                    颜色配置.无血量颜色, 0, 5.5f);
              }
            } 
// 长方形血条绘制
if (aa > 0 || dataTable.heroTemp[i].Id == 183) {
    // 假设血量是一个已经定义好的变量，表示是否显示血量
    if (长方血量) {
        // 使用白色绘制长方形条背景
        ImGui::GetForegroundDrawList()->AddRectFilled(
            ImVec2(pos_x - (21 + xiaodituxue), pos_y + offset_y - thickness), 
            ImVec2(pos_x + (21 + xiaodituxue), pos_y + offset_y + thickness),
            Paint_white // 长方形条背景的颜色
            
        );

        // 使用血量颜色绘制一个表示当前血量的长方形条部分
        ImGui::GetForegroundDrawList()->AddRectFilled(
            ImVec2(pos_x - (21 + xiaodituxue), pos_y + offset_y - thickness), 
            ImVec2(pos_x - (21 + xiaodituxue) + (42 + 2*xiaodituxue) * (aa / 360.0f), pos_y + offset_y + thickness),
            视野 == 257 ? 颜色配置.血量颜色 : 颜色配置.无血量颜色 // 当前血量的颜色
        );
    }
}
 
 


            rotatingdraw = rotatingdraw + 3;
            if (地图) {
              if (视野 == 257) {
                // 小头像
                if (头像常显) {
                  ImGui::GetForegroundDrawList()->AddImage(
                      handId != NULL ? handId : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                      {(int)pos_x - TXdx - touxiangdaxiao,
                       (int)pos_y - TXdx - touxiangdaxiao},
                      {(int)pos_x + TXdx + touxiangdaxiao,
                       (int)pos_y + TXdx + touxiangdaxiao});
                }
              } else {
              ImU32 darktx;
              if (暗化) {
              	darktx = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              darktx = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
                ImGui::GetForegroundDrawList()->AddImage(
                    handId != NULL ? handId : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                    {(int)pos_x - TXdx - touxiangdaxiao,
                     (int)pos_y - TXdx - touxiangdaxiao},
                    {(int)pos_x + TXdx + touxiangdaxiao,
                     (int)pos_y + TXdx + touxiangdaxiao}, ImVec2(0, 0), ImVec2(1, 1), darktx);
                     //ImU32 darkColor = IM_COL32(0, 0, 0, 128); // 128表示透明度，你可以根据需要调整



                if (dataTable.heroTemp[i].HC == 1) {
                  ImGui::GetForegroundDrawList()->AddCircleArc(
                      {pos_x, pos_y}, 21 + xiaodituxue,
                      ImVec2(0 + rotatingdraw, 20 + rotatingdraw),
                      Paint_lightblue, 9.5f, 5.5f);
                  ImGui::GetForegroundDrawList()->AddCircleArc(
                      {pos_x, pos_y}, 21 + xiaodituxue,
                      ImVec2(20 + rotatingdraw, 40 + rotatingdraw),
                      Paint_purple, 9.5f, 5.5f);
                  ImGui::GetForegroundDrawList()->AddCircleArc(
                      {pos_x, pos_y}, 21 + xiaodituxue,
                      ImVec2(40 + rotatingdraw, 60 + rotatingdraw),
                      颜色配置.血量颜色, 9.5f, 5.5f);
                }
              }
            }


            
            健康血量 = true;
if(((float)dataTable.heroTemp[i].Hp / (float)dataTable.heroTemp[i].MaxHp) <= zhanshaz) {健康血量 = false;}
           if (方框) {
                        
                       if (暴露视野不绘 && 视野 == 257)
                        {
                        }
                        else
                        {
              if (视野 == 257) {
                if (视野方框) {
                  float current_hp_percentage =
                      (float)dataTable.heroTemp[i].Hp /
                      (float)dataTable.heroTemp[i].MaxHp;
                  if (健康血量) {
                    // 绘制外部方框
                    ImGui::GetForegroundDrawList()->AddRect(
                        ImVec2((int)map_coord.X - (map_coord.H * 0.5f),
                               (int)map_coord.Y - (map_coord.H * 1.32f)),
                        ImVec2((int)map_coord.X + (map_coord.H * 0.5),
                               (int)map_coord.Y + (map_coord.H * 0.2f)),
                        颜色配置.方框颜色, 6.0f, 0, 2.0f); // 方框[ ]

                    //

                    // 在方框内部绘制斜线网格线

                    // 方框里头像
                    /*ImGui::GetForegroundDrawList()->AddImage(handId!= NULL ?
                       handId: 0, {(int)map_coord.X - (map_coord.H*0.2f),
                       (int)map_coord.Y - (map_coord.H*0.2f)+
                       (-map_coord.H*1.0f)}, {(int)map_coord.X +
                       (map_coord.H*0.2f), (int)map_coord.Y +
                       (map_coord.H*0.2f)+ (-map_coord.H*1.0f)});



                                 ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X,(int)
                       map_coord.Y+ (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ?
                       (map_coord.H*0.22f) : 1, ImVec2(0, 360), Paint_white,
                       0, 5.5f);//白底背景
                                 ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X,
                       (int)map_coord.Y+
                       (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ?
                       (map_coord.H*0.22f) : 1, ImVec2(0, aa),
                       颜色配置.血量颜色,  0, 5.5f);//红色血圈
                                 */
                  } else {
                    ImGui::GetForegroundDrawList()->AddRect(
                        ImVec2((int)map_coord.X - (map_coord.H * 0.5f),
                               (int)map_coord.Y - (map_coord.H * 1.32f)),
                        ImVec2((int)map_coord.X + (map_coord.H * 0.5),
                               (int)map_coord.Y + (map_coord.H * 0.2f)),
                        Paint_red, 6.0f, 0, 2.0f); // 方框[ ]

                    // 绘制内部方框，覆盖外部方框

                    // 在方框内部绘制斜线网格线

                    /*  ImGui::GetForegroundDrawList()->AddImage(handId!= NULL ?
                       handId: 0, {(int)map_coord.X - (map_coord.H*0.2f),
                       (int)map_coord.Y - (map_coord.H*0.2f)+
                       (-map_coord.H*1.0f)}, {(int)map_coord.X +
                       (map_coord.H*0.2f), (int)map_coord.Y +
                       (map_coord.H*0.2f)+ (-map_coord.H*1.0f)});



                                   ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X,(int)
                       map_coord.Y+ (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ?
                       (map_coord.H*0.22f) : 1, ImVec2(0, 360), Paint_white,
                       0, 5.5f);
                                   ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X,
                       (int)map_coord.Y+
                       (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ?
                       (map_coord.H*0.22f) : 1, ImVec2(0, aa),
                       颜色配置.血量颜色,  0, 5.5f);
                                   */
                  }
                }
              } else {
                // 百分比30方框判断
                float current_hp_per = (float)dataTable.heroTemp[i].Hp /
                                       (float)dataTable.heroTemp[i].MaxHp;
                if (健康血量) {
                // 绘制外部方框
ImGui::GetForegroundDrawList()->AddRect(ImVec2((int)map_coord.X - (map_coord.H*0.5f), (int)map_coord.Y - (map_coord.H*1.32f)), ImVec2((int)map_coord.X + (map_coord.H*0.5), (int)map_coord.Y + (map_coord.H*0.2f)), 颜色配置.方框颜色, 20.0f, 0, 2.0f); //方框[ ]

// 绘制内部方框，覆盖外部方框
ImGui::GetForegroundDrawList()->AddRectFilled(ImVec2((int)map_coord.X - (map_coord.H*0.5f) + 2.0f, (int)map_coord.Y - (map_coord.H*1.32f) + 2.0f), ImVec2((int)map_coord.X + (map_coord.H*0.5f) - 2.0f, (int)map_coord.Y + (map_coord.H*0.2f) - 2.0f), ImColor(255, 255, 255, 78));//方框内部填充

//在方框内部绘制斜线网格线


   
   //方框里头像
       ImGui::GetForegroundDrawList()->AddImage(handId!= NULL ?  handId: 0, {(int)map_coord.X - (map_coord.H*0.2f), (int)map_coord.Y - (map_coord.H*0.2f)+ (-map_coord.H*1.0f)}, {(int)map_coord.X + (map_coord.H*0.2f), (int)map_coord.Y + (map_coord.H*0.2f)+ (-map_coord.H*1.0f)});
         
       
       //方框里血量
                    ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X,(int) map_coord.Y+ (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ? (map_coord.H*0.22f) : 1, ImVec2(0, 360), Paint_white,  0, 5.5f);//白底背景
                    ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X, (int)map_coord.Y+ (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ? (map_coord.H*0.22f) : 1, ImVec2(0, aa), 颜色配置.血量颜色,  0, 5.5f);//红色血圈
                    
                    }else{
                    
                    
                    ImGui::GetForegroundDrawList()->AddRect(ImVec2((int)map_coord.X - (map_coord.H*0.5f), (int)map_coord.Y - (map_coord.H*1.32f)), ImVec2((int)map_coord.X + (map_coord.H*0.5), (int)map_coord.Y + (map_coord.H*0.2f)), Paint_red, 20.0f, 0, 2.0f); //方框[ ]

// 绘制内部方框，覆盖外部方框
ImGui::GetForegroundDrawList()->AddRectFilled(ImVec2((int)map_coord.X - (map_coord.H*0.5f) + 2.0f, (int)map_coord.Y - (map_coord.H*1.32f) + 2.0f), ImVec2((int)map_coord.X + (map_coord.H*0.5f) - 2.0f, (int)map_coord.Y + (map_coord.H*0.2f) - 2.0f), ImColor(255, 0, 0, 78));//方框内部填充

//在方框内部绘制斜线网格线


   
   //方框里头像
       ImGui::GetForegroundDrawList()->AddImage(handId!= NULL ?  handId: 0, {(int)map_coord.X - (map_coord.H*0.2f), (int)map_coord.Y - (map_coord.H*0.2f)+ (-map_coord.H*1.0f)}, {(int)map_coord.X + (map_coord.H*0.2f), (int)map_coord.Y + (map_coord.H*0.2f)+ (-map_coord.H*1.0f)});
         
       
       //方框里血量
                    ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X,(int) map_coord.Y+ (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ? (map_coord.H*0.22f) : 1, ImVec2(0, 360), Paint_white,  0, 5.5f);//白底背景
                    ImGui::GetForegroundDrawList()->AddCircleArc(ImVec2((int)map_coord.X, (int)map_coord.Y+ (-map_coord.H*1.0f)),(map_coord.H*0.22f)>0 ? (map_coord.H*0.22f) : 1, ImVec2(0, aa), 颜色配置.血量颜色,  0, 5.5f);//红色血圈
                    
                    }
                    
                    
                    
                    
                    
                                float max_hp_percentage = 0.0f;
								float current_hp_percentage = (float)dataTable.heroTemp[i].Hp / (float)dataTable.heroTemp[i].MaxHp;
								float bar_width = map_coord.H * 0.0f;  //血条宽度
								float bar_height = map_coord.H * 0.0f;  //血条长度
								float corner_radius = 0.0f;

							

								// 绘制最大血量条填充
								ImGui::GetForegroundDrawList()->AddRectFilled(
									ImVec2((int)map_coord.X-8 - bar_width / 2 + corner_radius - (-map_coord.H*0.65f), (int)map_coord.Y + corner_radius - (map_coord.H*1.35f)),
									ImVec2((int)map_coord.X-8 + bar_width / 2 - corner_radius - (-map_coord.H*0.65f), (int)map_coord.Y + bar_height - corner_radius - (map_coord.H*1.05f)),
									Paint_white,
									corner_radius
								);

								// 计算当前血量条的填充高度和起始位置
								float current_hp_fill_height = bar_height * current_hp_percentage;
								float fill_correction_factor = 1.32f;  // 初始修正因子为1.35

								// 根据敌人剩余血量百分比动态调整修正因子的值
								if (current_hp_percentage <= 0.85f) {
									fill_correction_factor = 1.05f;
								}

								float current_hp_fill_start_y = (int)map_coord.Y + bar_height - current_hp_fill_height - (map_coord.H * fill_correction_factor);

								// 绘制当前血量条填充
								ImGui::GetForegroundDrawList()->AddRectFilled(
									ImVec2((int)map_coord.X-8 - bar_width / 2 + corner_radius - (-map_coord.H * 0.65000000f), current_hp_fill_start_y),
									ImVec2((int)map_coord.X -8+ bar_width / 2 - corner_radius - (-map_coord.H * 0.65f), (int)map_coord.Y + bar_height - (map_coord.H * 1.05f)),
									颜色配置.血量颜色,
									corner_radius
								);
                    
                    
                    
                    
                    
                    
                    
    }
    }
    
    }
                                                  if (技能3) {

                      
                            //英雄一技能 
                            if (dataTable.heroTemp[i].Space1 > 0) {
                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB1!=0){

                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB1-0].textureId);
                            
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }
                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
 float circleRadius = map_coord.H * 0.16f;
ImVec2 circleCenter = ImVec2((float)map_coord.X + (map_coord.H * 0.95), (float)map_coord.Y - (map_coord.H * 1.2));
ImU32 circleColor = IM_COL32(0, 0, 0, 255); // 设置Alpha值为255以得到全黑
ImGui::GetForegroundDrawList()->AddCircleFilled(circleCenter, circleRadius, circleColor);

// 绘制矩形中心的数字1
ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 1.2) -15), // 文本位置
    IM_COL32(255, 255, 255, 255), // 白色
    "1" // 文本内容
);

ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 1.2) -17), // 文本位置
    Paint_pink, // 粉色
    "    no" // 文本内容
);

                     

                           
                            } else {
                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB1!=0){

                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB1-0].textureId);
                            
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }
                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            
                            
                            
                       float circleRadius = map_coord.H * 0.16f;
ImVec2 circleCenter = ImVec2((float)map_coord.X + (map_coord.H * 0.95), (float)map_coord.Y - (map_coord.H * 1.2));
ImU32 circleColor = IM_COL32(0, 0, 0, 255); // 设置Alpha值为255以得到全黑
ImGui::GetForegroundDrawList()->AddCircleFilled(circleCenter, circleRadius, circleColor);

// 绘制矩形中心的数字1
ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 1.2) -15), // 文本位置
    IM_COL32(255, 255, 255, 255), // 白色
    "1" // 文本内容
);

ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 1.2) -17), // 文本位置
    Paint_green, // 白色
    "    ok" // 文本内容
);



                            }
                            
                            


                              //英雄二技能 
                            if (dataTable.heroTemp[i].Space2 > 0) {

                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB2!=0){

                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB2-0].textureId);
                            
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }
                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
             
             
             // 绘制黑色背景
float circleRadius = map_coord.H * 0.16f;
ImVec2 circleCenter = ImVec2((float)map_coord.X + (map_coord.H * 0.95), (float)map_coord.Y - (map_coord.H * 0.85));
ImU32 circleColor = IM_COL32(0, 0, 0, 255);
ImGui::GetForegroundDrawList()->AddCircleFilled(circleCenter, circleRadius, circleColor);

// 绘制矩形中心的数字1
ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.85) -15), // 文本位置
    IM_COL32(255, 255, 255, 255), // 白色
    "2" // 文本内容
);

ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.85) -17), // 文本位置
    Paint_pink, // 绿色
    "    no" // 文本内容
);
             
             
             
             
             
                            int intvalue = dataTable.heroTemp[i].Space2;
        string test = std::to_string(intvalue);

                            ImGui::GetForegroundDrawList()->AddText(
                            NULL, (float)((double)(0.25) * map_coord.H), 
                            ImVec2((float)map_coord.X + (map_coord.H*0.6) - (-map_coord.H*0.25f), (float)map_coord.Y - (map_coord.H*0.07f) - (map_coord.H*0.93f)), Paint_white,
                            test.c_str()); //大招计时
                            } else {
                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB2!=0){

                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB2-0].textureId);
                            
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }

                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

                                         // 绘制黑色背景
float circleRadius = map_coord.H * 0.16f;
ImVec2 circleCenter = ImVec2((float)map_coord.X + (map_coord.H * 0.95), (float)map_coord.Y - (map_coord.H * 0.85));
ImU32 circleColor = IM_COL32(0, 0, 0, 255);
ImGui::GetForegroundDrawList()->AddCircleFilled(circleCenter, circleRadius, circleColor);

// 绘制矩形中心的数字1
ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.85) -15), // 文本位置
    IM_COL32(255, 255, 255, 255), // 白色
    "2" // 文本内容
);

ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.85) -17), // 文本位置
    Paint_green, // 绿色
    "    ok" // 文本内容
);
                            }
                            
                            
                            
                            //英雄大招 
                            if (dataTable.heroTemp[i].Space > 0) {

                            // 绘制大招
                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB3!=0){

                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB3-0].textureId);
                            
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }

                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

                         
                          // 绘制黑色背景
float circleRadius = map_coord.H * 0.16f;
ImVec2 circleCenter = ImVec2((float)map_coord.X + (map_coord.H * 0.95), (float)map_coord.Y - (map_coord.H * 0.5));
ImU32 circleColor = IM_COL32(0, 0, 0, 255);
ImGui::GetForegroundDrawList()->AddCircleFilled(circleCenter, circleRadius, circleColor);

// 绘制矩形中心的数字1
ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.5) -15), // 文本位置
    IM_COL32(255, 255, 255, 255), // 白色
    "3" // 文本内容
);

ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.5) -17), // 文本位置
    Paint_pink, // 绿色
    "    no" // 文本内容
);            
                         
                         

                            } else {
                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB3!=0){

                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB3-0].textureId);
                            
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }

                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

                                      // 绘制黑色背景
float circleRadius = map_coord.H * 0.16f;
ImVec2 circleCenter = ImVec2((float)map_coord.X + (map_coord.H * 0.95), (float)map_coord.Y - (map_coord.H * 0.5));
ImU32 circleColor = IM_COL32(0, 0, 0, 255);
ImGui::GetForegroundDrawList()->AddCircleFilled(circleCenter, circleRadius, circleColor);

// 绘制矩形中心的数字1
ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.5) -15), // 文本位置
    IM_COL32(255, 255, 255, 255), // 白色
    "3" // 文本内容
);

ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.5) -17), // 文本位置
    Paint_green, // 绿色
    "    ok" // 文本内容
);


                                                   
                            }
                            


                            //召唤师技能 
                            if (dataTable.heroTemp[i].Skill > 0) {

                            ImTextureID tubiaoid;
      if(dataTable.heroTemp[i].TB!=0){
                            if(dataTable.heroTemp[i].TB==53391 or dataTable.heroTemp[i].TB==53395 or dataTable.heroTemp[i].TB==53393){
                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB-0].textureId);
                            }else{
                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB-80000].textureId);
                            }
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }

                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

                            ImGui::GetForegroundDrawList()->AddImage(
                            tubiaoid!= NULL ?  tubiaoid: tubiaoid2, 
                            ImVec2((float)map_coord.X -(map_coord.H*0.16) + (map_coord.H*0.95), (float)map_coord.Y -(map_coord.H*0.16) - (map_coord.H*0.15)), 
                            ImVec2((float)map_coord.X +(map_coord.H*0.16) + (map_coord.H*0.95), (float)map_coord.Y +(map_coord.H*0.16) - (map_coord.H*0.15)));

ImGui::GetForegroundDrawList()->AddImage(
                            tubiaoid!= NULL ?  tubiaoid: tubiaoid2, 
                            ImVec2((float)map_coord.X -(map_coord.H*0.16) + (map_coord.H*0.95), (float)map_coord.Y -(map_coord.H*0.16) - (map_coord.H*0.15)), 
                            ImVec2((float)map_coord.X +(map_coord.H*0.16) + (map_coord.H*0.95), (float)map_coord.Y +(map_coord.H*0.16) - (map_coord.H*0.15)));


ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.15) -17), // 文本位置
    Paint_pink, // 绿色
    "    no" // 文本内容
);


                            } else {
                            // 召唤师cd完成
                            ImTextureID tubiaoid;
                            if(dataTable.heroTemp[i].TB!=0){
                            if(dataTable.heroTemp[i].TB==53391 or dataTable.heroTemp[i].TB==53395 or dataTable.heroTemp[i].TB==53393){
                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB4-0].textureId);
                            }else{
                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].TB-80000].textureId);
                            }
                            }else{
                            tubiaoid =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                            }

                            ImTextureID tubiaoid2 =reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);

ImGui::GetForegroundDrawList()->AddImage(
                            tubiaoid!= NULL ?  tubiaoid: tubiaoid2, 
                            ImVec2((float)map_coord.X -(map_coord.H*0.16) + (map_coord.H*0.95), (float)map_coord.Y -(map_coord.H*0.16) - (map_coord.H*0.15)), 
                            ImVec2((float)map_coord.X +(map_coord.H*0.16) + (map_coord.H*0.95), (float)map_coord.Y +(map_coord.H*0.16) - (map_coord.H*0.15)));


                            ImGui::GetForegroundDrawList()->AddText(
    ImVec2((float)map_coord.X + (map_coord.H * 0.95) - 5, (float)map_coord.Y - (map_coord.H * 0.15) -17), // 文本位置
    Paint_green, // 绿色
    "    ok" // 文本内容
);
                            
          // 绘制白色边框圆

                            
                            }

                            }////方框计时
    if(方框2)
    
    {
    
    Drawentity(ImGui::GetForegroundDrawList(),dataTable.heroTemp[i].Id,handId,aa,TXdx,蓝方敌人视野判断,红方敌人视野判断);
    DrawBox(ImGui::GetForegroundDrawList(),dataTable.heroTemp[i].Id,handId,aa,dataTable.heroTemp[i].Hp,dataTable.heroTemp[i].MaxHp,蓝方敌人视野判断,红方敌人视野判断);
    
    }
    
    
            // 80//10
            if (辅助方框) {
              ImGui::GetForegroundDrawList()->AddRect(
                  ImVec2(SmallMapX + 78, SmallMapY - 10),
                  ImVec2(SmallMapX + 2.68 * rientation * Wwra,
                         SmallMapY + 2.13 * rientation * Wwra),
                  颜色配置.方框颜色, 5, 0);
            }
//printf("距离  %.0f\n", 距离敌人);
            if (射线) {
                        if (暴露视野不绘 && 视野 == 257)
                        {
                        }
                        else
                        {
              if (视野 == 257) {
                if (视野方框) {
                  if (距离敌人 > 0 && 距离敌人 < 26000) {
                    ImGui::GetForegroundDrawList()->AddLine(
                        ImVec2(yxpx / 2, yxpy / 2),
                        ImVec2(map_coord.X,
                               map_coord.Y + (-map_coord.H * 1.0f)),
                        颜色配置.射线颜色, 1.5f);
                  }
                }
              } else {
                // 射线
                if (距离敌人 > 0 && 距离敌人 < 26000) {
                  ImGui::GetForegroundDrawList()->AddLine(
                      ImVec2(yxpx / 2, yxpy / 2),
                      ImVec2(map_coord.X, map_coord.Y + (-map_coord.H * 1.0f)),
                      颜色配置.射线颜色, 1.5f);
                }
              }
            }
}
			/*dataTable.heroTemp[i].Spaceid = dataTable.heroTemp[i].Id*100+30;
            dataTable.heroTemp[i].Space =
                driver->read<int>(
                    ReadValue(ReadValue(ReadValue(bingxiang6 + 0x148) + 0x108) +
                              0xa8) +
                    0x3C) /
                8192000;*/
			//1技能
            dataTable.heroTemp[i].Space1 = ReadDword(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0xD8) + 0xf8) + 0x3C) / 8192000;
            dataTable.heroTemp[i].Space1id =dataTable.heroTemp[i].Id*100+10;
            //2技能
            dataTable.heroTemp[i].Space2 = ReadDword(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0xF0) + 0xf8) + 0x3C) / 8192000;
            dataTable.heroTemp[i].Space2id =dataTable.heroTemp[i].Id*100+20;
            //大招
            dataTable.heroTemp[i].Space = ReadDword(ReadValue(ReadValue(ReadValue(bingxiang6 + 0x150) + 0x108) + 0xf8) + 0x3C) / 8192000;
            dataTable.heroTemp[i].Spaceid =dataTable.heroTemp[i].Id*100+30;

            // dataTable.heroTemp[i].Skill =
            // driver->read<int>(ReadValue(ReadValue(ReadValue(bingxiang6+0x148)+0x150)+0xa8)+0x3c)/8192000;
            当前血量 = (float)dataTable.heroTemp[i].Hp /
                       (float)dataTable.heroTemp[i].MaxHp;

            // dataTable.heroTemp[i].TB =
            // driver->read<int>(ReadValue(ReadValue(ReadValue(bingxiang6+0x148)
            // + 0x150) + 0x60) + 0x10);

            if (dataTable.heroTemp[i].TB != 80102 &&
                dataTable.heroTemp[i].TB != 80103 &&
                dataTable.heroTemp[i].TB != 80104 &&
                dataTable.heroTemp[i].TB != 80105 &&
                dataTable.heroTemp[i].TB != 80107 &&
                dataTable.heroTemp[i].TB != 80108 &&
                dataTable.heroTemp[i].TB != 80109 &&
                dataTable.heroTemp[i].TB != 80110 &&
                dataTable.heroTemp[i].TB != 80115 &&
                dataTable.heroTemp[i].TB != 80121 &&
                dataTable.heroTemp[i].TB != 80116 &&
                dataTable.heroTemp[i].TB != 801162) {
              dataTable.heroTemp[i].TB = 0;
            }
//大招
tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].Spaceid].textureId);
//2技能
tubiaoid3=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].Space2id].textureId);
//1技能
tubiaoid4=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].Space1id].textureId);
//字体
float text_zt = (float)((double)(0.266777647) * map_coord.H);



            // 方框里技能
            if (方框技能) {
            
                               if (方框技能不绘 && 视野 == 257)
                        {
                        }
                        else
                        {
                if (视野方框) {
                //大招
                  ImU32 SpaceColor;
              int intvalue = dataTable.heroTemp[i].Space;
              if (intvalue > 0) {
              	SpaceColor = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	SpaceColor = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                tubiaoid != NULL ? tubiaoid : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                ImVec2((float)map_coord.X + (map_coord.H * 0.16) +
                                   (map_coord.H * 0.3),
                               (float)map_coord.Y - (map_coord.H * 0.16)),
                        ImVec2((float)map_coord.X - (map_coord.H * 0.16) +
                                   (map_coord.H * 0.3),
                               (float)map_coord.Y + (map_coord.H * 0.16)), ImVec2(0, 0), ImVec2(1, 1), SpaceColor); // 顶上大招
            if (intvalue > 0) { // 绘制大招
              string test = std::to_string(intvalue);
              ImVec2 SpaceSize = ImGui::GetFont()->CalcTextSizeA(text_zt, FLT_MAX, 0, test.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                        NULL, text_zt,
                        ImVec2((float)map_coord.X + (map_coord.H * 0.3f) - SpaceSize.x * 0.5f,
    (float)map_coord.Y - (map_coord.H * 0.16f)),
                        Paint_green, // 方框召唤师字体
                        test.c_str());
            }
            
            //2技能
            ImU32 Space2Color;
              int intvalue2 = dataTable.heroTemp[i].Space2;
              if (intvalue2 > 0) {
              	Space2Color = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	Space2Color = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                tubiaoid3 != NULL ? tubiaoid3 : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                ImVec2((float)map_coord.X + (map_coord.H * 0.16f) +
                                   (map_coord.H * 0.3f),
                               (float)map_coord.Y - (map_coord.H * 0.16f * 3)),
                        ImVec2((float)map_coord.X - (map_coord.H * 0.16f) +
                                   (map_coord.H * 0.3f),
                               (float)map_coord.Y + (map_coord.H * 0.16f * -1)), ImVec2(0, 0), ImVec2(1, 1), Space2Color); // 顶上大招
            if (intvalue2 > 0) { // 绘制大招
              string test2 = std::to_string(intvalue2);
              ImVec2 Space2Size = ImGui::GetFont()->CalcTextSizeA(text_zt, FLT_MAX, 0, test2.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                  NULL, text_zt,
ImVec2((float)map_coord.X + (map_coord.H * 0.3f) - Space2Size.x * 0.5f,
    map_coord.Y - (map_coord.H * 0.16f * 3)),
                        Paint_green, // 方框召唤师字体
                        test2.c_str());
            }
            
            //1技能
            ImU32 Space1Color;
              int intvalue3 = dataTable.heroTemp[i].Space1;
              if (intvalue3 > 0) {
              	Space1Color = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	Space1Color = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                tubiaoid4 != NULL ? tubiaoid4 : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                ImVec2((float)map_coord.X + (map_coord.H * 0.16f) +
                                   (map_coord.H * 0.3f),
                               (float)map_coord.Y - (map_coord.H * 0.16f * 5)),
                        ImVec2((float)map_coord.X - (map_coord.H * 0.16f) +
                                   (map_coord.H * 0.3f),
                               (float)map_coord.Y + (map_coord.H * 0.16f * -3)), ImVec2(0, 0), ImVec2(1, 1), Space1Color); // 顶上
            if (intvalue3 > 0) { // 绘制
              string test1 = std::to_string(intvalue3);
              ImVec2 Space1Size = ImGui::GetFont()->CalcTextSizeA(text_zt, FLT_MAX, 0, test1.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                  NULL, text_zt,
                        ImVec2((float)map_coord.X + (map_coord.H * 0.3f) - Space1Size.x * 0.5f,
    map_coord.Y - (map_coord.H * 0.16f * 5)),
                        Paint_green, // 方框字体
                        test1.c_str());
            }
            
            
            
                  ImU32 SkillColor;
              int intvalue1 = dataTable.heroTemp[i].TB;
              int intvalue1_ = dataTable.heroTemp[i].Skill;
                if (intvalue1 == 801162) {
                  tubiaoid2 = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1- 800000]
                          .textureId);

                } else if (intvalue1 >= 80102 && intvalue1 <= 80121) {
                  tubiaoid2 = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1 - 80000]
                          .textureId);
                } else {
                  tubiaoid2 = reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                  
                  
                }
              
              if (intvalue1_ > 0) {
              	SkillColor = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	SkillColor = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                        tubiaoid2,
                        ImVec2((float)map_coord.X - (map_coord.H * 0.16f) -
                                   (map_coord.H * 0.3),
                               (float)map_coord.Y - (map_coord.H * 0.16f)),
                        ImVec2((float)map_coord.X + (map_coord.H * 0.16f) -
                                   (map_coord.H * 0.3),
                               (float)map_coord.Y + (map_coord.H * 0.16f)), ImVec2(0, 0), ImVec2(1, 1), SkillColor); // 顶上召唤师技能
                   
            if (intvalue1_ > 0) { // 绘制召唤计时
              string test = std::to_string(intvalue1_);
              ImVec2 SkillSize = ImGui::GetFont()->CalcTextSizeA(text_zt, FLT_MAX, 0, test.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                        NULL, text_zt,
                        ImVec2((float)map_coord.X - (map_coord.H * 0.3f) - SkillSize.x * 0.5f,
                               (float)map_coord.Y - (map_coord.H * 0.16f)),
                        Paint_green, // 方框召唤师字体
                        test.c_str());
            }
                
              } 
              }
            }
            
            
            if(方框技能2)
            
            {
            
            Drawskill(ImGui::GetForegroundDrawList(),dataTable.heroTemp[i].Id,dataTable.heroTemp[i].Space,dataTable.heroTemp[i].Space,dataTable.heroTemp[i].Space2,dataTable.heroTemp[i].Space1,dataTable.heroTemp[i].Skill,dataTable.heroTemp[i].TB,TXdx);
            
            
            
            
            }
if (自动净化) {





              if (控制状态 != 0  &&
                  距离敌人 <= 12000) {
                  if(召唤师ID==133&&召唤师技能CD==0)
                  {
                  //触摸缓冲 = true;
                if (屏幕方向 == 3) {
                	Touch::Down(zhsY, yxpx - zhsX);
                } else if (屏幕方向 == 1) {
                	Touch::Down(yxpy - zhsY, zhsX);
                }
                //std::thread t([&]() {
                  usleep(1000 * 5);
                  Touch::Up();
                  
                  }
                  else if(召唤师技能判断)
                  {
                //触摸缓冲 = true;
                if (屏幕方向 == 3) {
                	Touch::Down(zhsY, yxpx - zhsX);
                } else if (屏幕方向 == 1) {
                	Touch::Down(yxpy - zhsY, zhsX);
                }
                //std::thread t([&]() {
                  usleep(1000 * 5);
                  Touch::Up();
                  }
                  // std::this_thread::sleep_for(1);
                  //usleep(1000 * 500);
                  //触摸缓冲 = false;
                //});
                // 启动线程
                //t.detach(); // 或者 t.detach();
              }
}





            if (自动斩杀) {
              float 当前血量 = (float)dataTable.heroTemp[i].Hp /
                               (float)dataTable.heroTemp[i].MaxHp;
              if (!智能斩杀) {
                if (当前血量 > 0 && 当前血量 < zhanshaz) {
                  血量判断 = true;
                } else {
                  血量判断 = false;
                }
              } else {
                float 智能斩杀 = (float)dataTable.heroTemp[i].Hp -
                                 ((float)dataTable.heroTemp[i].MaxHp -
                                  (float)dataTable.heroTemp[i].Hp) *
                                     0.15;
                if (智能斩杀 < 0) {
                  血量判断 = true;
                } else {
                  血量判断 = false;
                }
              }
              if (血量判断 && 召唤师技能ID == 80108 && 召唤师技能判断 &&
                  距离敌人 <= 5000 && 视野 == 257) {
             //   触摸缓冲 = true;
                if (屏幕方向 == 3) {
                	Touch::Down(zhsY, yxpx - zhsX);
                } else if (屏幕方向 == 1) {
                	Touch::Down(yxpy - zhsY, zhsX);
                }
                std::thread t([&]() {
                  usleep(1000 * 5);
                  Touch::Up();
                  // std::this_thread::sleep_for(1);
                  usleep(1000 * 200);
                 // 触摸缓冲 = false;
                });
                // 启动线程
                t.detach(); // 或者 t.detach();
              }
            }

          } // 血量大于0

          // TODO：顶上技能
          if (顶上技能) {
          
            float Theoffset_X = CDjs_X + jinenglanzX; // 113
            float Theoffset_Y = CDjs_Y + jinenglanzY; //-4
            float CDdrawXY[5][2] = {
                {(float)(Theoffset_X + intervalcdX * opop), (float)(Theoffset_Y + intervalcdY * 0)},
                {(float)(Theoffset_X + intervalcdX * opop), (float)(Theoffset_Y + (TXdx / 2) + intervalcdY * 1)},
                {(float)(Theoffset_X + intervalcdX * opop), (float)(Theoffset_Y + TXdx + intervalcdY * 2)},
                {(float)(Theoffset_X + intervalcdX * opop), (float)(Theoffset_Y + (TXdx * 1.5) + intervalcdY * 3)},
                {(float)(Theoffset_X + intervalcdX * opop), (float)(Theoffset_Y + (TXdx * 2) + intervalcdY * 4)}
                };

            const float _txt_X = Theoffset_X - (TXdx * 3.6f);

            CDdrawXY[1][0] = CDdrawXY[1][0] + (TXdx / 14.0f); // x 二次改变偏移
            CDdrawXY[0][1] = CDdrawXY[0][1] + (TXdx) + (TXdx / 5.0f);
            CDdrawXY[1][1] = CDdrawXY[1][1] + (TXdx) + (TXdx / 5.0f); // 绘制头像
            CDdrawXY[2][1] = CDdrawXY[2][1] + (TXdx) + (TXdx / 5.0f);
            CDdrawXY[3][1] = CDdrawXY[3][1] + (TXdx) + (TXdx / 5.0f);
            CDdrawXY[4][1] = CDdrawXY[4][1] + (TXdx) + (TXdx / 5.0f);
            
            int CD数组 = 0;
            
            
            
            ImU32 darkColor;
            if (hp1 <= 0) {
              	darkColor = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	darkColor = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
            ImGui::GetForegroundDrawList()->AddImage(
                handId != NULL ? handId : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                {CDdrawXY[CD数组][0] - TXdx, CDdrawXY[CD数组][1] - TXdx},
                {CDdrawXY[CD数组][0] + TXdx, CDdrawXY[CD数组][1] + TXdx}, ImVec2(0, 0), ImVec2(1, 1), darkColor); // 顶上头像

            //ImU32 color = ImGui::GetColorU32(ImVec4(1.0f, 1.0f, 1.0f, 1.0f));
            ImGui::GetForegroundDrawList()->AddCircleArc(
                ImVec2(CDdrawXY[CD数组][0], CDdrawXY[CD数组][1]), TXdx, ImVec2(0, 360),
                darkColor, 0, 5);

            ImGui::GetForegroundDrawList()->AddCircleArc(
                ImVec2(CDdrawXY[CD数组][0], CDdrawXY[CD数组][1]), TXdx, ImVec2(0, aa),
                颜色配置.血量颜色, 0, 5);
                
                
                
                if (小技能计时) {
            //一技能
            CD数组++;
           
              tubiaoid4=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].Space1id].textureId);
              ImU32 Space1Color;
              int intvalue3 = dataTable.heroTemp[i].Space1;
              if (intvalue3 > 0) {
              	Space1Color = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	Space1Color = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                tubiaoid4 != NULL ? tubiaoid4 : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                {CDdrawXY[CD数组][0] - TXdx, CDdrawXY[CD数组][1] - TXdx},
                {CDdrawXY[CD数组][0] + TXdx, CDdrawXY[CD数组][1] + TXdx}, ImVec2(0, 0), ImVec2(1, 1), Space1Color); // 顶上技能
            if (intvalue3 > 0) { // 绘制大招
              string test1 = std::to_string(intvalue3);
              ImVec2 Space1Size = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, test1.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                  NULL, skills_txt_dx,
                  ImVec2(CDdrawXY[CD数组][0] - Space1Size.x * 0.55f, CDdrawXY[CD数组][1] - Space1Size.y * 0.5f),
                  Paint_white, test1.c_str());
            }

            
           //二技能
           CD数组++;
           
                            tubiaoid3=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].Space2id].textureId);
              ImU32 Space2Color;
              int intvalue2 = dataTable.heroTemp[i].Space2;
              if (intvalue2 > 0) {
              	Space2Color = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	Space2Color = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                tubiaoid3 != NULL ? tubiaoid3 : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                {CDdrawXY[CD数组][0] - TXdx, CDdrawXY[CD数组][1] - TXdx},
                {CDdrawXY[CD数组][0] + TXdx, CDdrawXY[CD数组][1] + TXdx}, ImVec2(0, 0), ImVec2(1, 1), Space2Color); // 顶上技能
            if (intvalue2 > 0) { // 绘制大招
              string test2 = std::to_string(intvalue2);
              ImVec2 Space2Size = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, test2.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                  NULL, skills_txt_dx,
                  ImVec2(CDdrawXY[CD数组][0] - Space2Size.x * 0.55f, CDdrawXY[CD数组][1] - Space2Size.y * 0.5f),
                  Paint_white, test2.c_str());
            }
            
            }

            //大招
            				CD数组++;
              				
                            tubiaoid=reinterpret_cast<ImTextureID>(技能贴图.头像[dataTable.heroTemp[i].Spaceid].textureId);
              ImU32 SpaceColor;
              int intvalue = dataTable.heroTemp[i].Space;
              if (intvalue > 0) {
              	SpaceColor = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	SpaceColor = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                tubiaoid != NULL ? tubiaoid : reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId),
                {CDdrawXY[CD数组][0] - TXdx, CDdrawXY[CD数组][1] - TXdx},
                {CDdrawXY[CD数组][0] + TXdx, CDdrawXY[CD数组][1] + TXdx}, ImVec2(0, 0), ImVec2(1, 1), SpaceColor); // 顶上技能
            if (intvalue > 0) { // 绘制大招
              string test = std::to_string(intvalue);
              ImVec2 SpaceSize = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, test.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                  NULL, skills_txt_dx,
                  ImVec2(CDdrawXY[CD数组][0] - SpaceSize.x * 0.55f, CDdrawXY[CD数组][1] - SpaceSize.y * 0.5f),
                  Paint_white, test.c_str());
            }
            
            //召唤师
            CD数组++;
              
              ImU32 SkillColor;
              int intvalue1 = dataTable.heroTemp[i].TB;
              int intvalue1_ = dataTable.heroTemp[i].Skill;
                if (intvalue1 == 801162) {
                  tubiaoid2 = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1- 800000]
                          .textureId);

                } else if (intvalue1 >= 80102 && intvalue1 <= 80121) {
                  tubiaoid2 = reinterpret_cast<ImTextureID>(
                      技能贴图.头像[intvalue1 - 80000]
                          .textureId);
                } else {
                  tubiaoid2 = reinterpret_cast<ImTextureID>(技能贴图.头像[0].textureId);
                }

              
              if (intvalue1_ > 0) {
              	SkillColor = IM_COL32(150, 150, 150, 255); // 128表示透明度，你可以根据需要调整
              } else {
              	SkillColor = IM_COL32(255, 255, 255, 255); // 128表示透明度，你可以根据需要调整
              }
              ImGui::GetForegroundDrawList()->AddImage(
                  tubiaoid2,
                  {(float)CDdrawXY[CD数组][0] - TXdx, CDdrawXY[CD数组][1] - TXdx},
                  {(float)CDdrawXY[CD数组][0] + TXdx, CDdrawXY[CD数组][1] + TXdx}, ImVec2(0, 0), ImVec2(1, 1), SkillColor); // 顶上召唤师技能
                   
            if (intvalue1_ > 0) { // 绘制召唤计时
              string test = std::to_string(intvalue1_);
              ImVec2 SkillSize = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, test.c_str());//字体居中
              ImGui::GetForegroundDrawList()->AddText(
                  NULL, skills_txt_dx,
                  ImVec2(CDdrawXY[CD数组][0] - SkillSize.x * 0.55f, CDdrawXY[CD数组][1] - SkillSize.y * 0.5f),
                  Paint_white, test.c_str());
            }
          }
         
           if(顶上技能2)
          {
          
          float Theoffset_X = CDjs_X + jinenglanzX; // 113
            float Theoffset_Y = CDjs_Y + jinenglanzY; //-4
          DrawTopskill(ImGui::GetForegroundDrawList(),handId,dataTable.heroTemp[i].Id,蓝方敌人视野判断,红方敌人视野判断,opop,aa,dataTable.heroTemp[i].Space,dataTable.heroTemp[i].Space2,dataTable.heroTemp[i].Space1,dataTable.heroTemp[i].Skill,dataTable.heroTemp[i].TB);
          
          }

          opop++;
        }
      }
    }//10次循环结束

    uint64_t BuffAddress; // buff数量地址
    uint64_t BxAddress;   // 兵线数量地址
    uint64_t bxAddress;   // 兵线属性首位置
    uint64_t Buffsl;      // buff坐标数量

    if (野怪) {
      int 野怪距敌 = 0;
      int 距离野怪 = 0;
      bool 野怪距敌判断 = false;
      
      long temp2 = ReadValue(libGame_base + 0x1DA0);
      BuffAddress =
          ReadValue(ReadValue(ReadValue(temp2 + 0x3B0) + 0x88) + 0x120);
      // printf("START\n");
      for (int i = 0; i < 23; i++) {
      
      
                  if (ESPMenu.是否开启共享) {
                    //共享全源数据
       // bbuff1 = Driver->读取指针(野怪数组 + i * 0x18);
        buffid = driver->read<int>(dataTable.pve[i].id);
        ygtime = driver->read<int>(dataTable.pve[i].cd ) / 1000;
        xxx1 = driver->read<int>(dataTable.pve[i].X);
        yyy1 = driver->read<int>(dataTable.pve[i].Y);
        buffx = xxx1 * rientation * 2400/2/11.2f*1.455 / 50000.0f + 2400/2/11.2f*1.455;
        buffy = yyy1 * rientation * 2400/2/11.2f*1.455 / 50000.0f * -1 + 2400/2/11.2f*1.455;
        //传输野怪数据
        creeps += std::to_string(0)
            + "," + std::to_string(ygtime)
            + "," + std::to_string(buffid)
            + "," + std::to_string(buffx + ESPMenu.野怪左右调整)
            + "," + std::to_string(buffy + ESPMenu.野怪上下调整)
            + "==";
        //传输野怪数据完毕
        }
      
      
        std::vector<float> pvePosX(21, 0.0f);
        std::vector<float> pvePosY(21, 0.0f);
        pvePosX[i] = dataTable.pveTemp[i].coord.X;
        pvePosY[i] = dataTable.pveTemp[i].coord.Y;
        dataTable.pve[i].cd =
            ReadValue((u_long)(BuffAddress + i * 0x18)) + 0x240;
        dataTable.pve[i].maxcd =
            ReadValue((u_long)(BuffAddress + i * 0x18)) + 0x1e4;
        dataTable.pve[i].cc = ReadValue((u_long)(BuffAddress + i * 0x18));
        dataTable.pve[i].id = dataTable.pve[i].cc + 0xC0;
        long int 实体指针=ReadValue(dataTable.pve[i].cc+0x398);
        dataTable.pve[i].X = ReadValue(ReadValue(ReadValue(实体指针+0x230)+0xf0)+0x10)+0x0;
        dataTable.pve[i].Y = ReadValue(ReadValue(ReadValue(实体指针+0x230)+0xf0)+0x10)+0x8;
        dataTable.pve[i].hp =
            ReadValue(实体指针 + 0x168) + 0x98;
        dataTable.pve[i].maxhp =
            ReadValue(实体指针 + 0x168) + 0xA0;
            
int 惩戒 = driver->read<int>(driver->read<uint64_t>(driver->read<uint64_t>(driver->read<uint64_t>(driver->read<uint64_t>(driver->read<uint64_t>(lil2cpp_base+0x55EEA8)+0xB8)+0x0)+0x20)+0x28)+0x1F8);
             if (召唤师ID == 183) {
            惩戒伤害 = 惩戒 * 1.25;
            } else {
            惩戒伤害 = 惩戒;
            }
            //旧(上帝so+0xB6B820)+0xB8)+0x50)+0x20)+0x20)+0x1E0);
//libil2cpp.so:bss + 0xDD68 -> 0xB8 -> 0x2B0 -> 0x260 -> 0x20 -> 0x1E0
//新libil2cpp.so:bss[1] + 0x4E8980 -> + 0xB8 -> + 0x10 -> + 0x20 -> + 0x20 -> + 0x1E0
        dataTable.pveTemp[i].cd = driver->read<int>(dataTable.pve[i].cd) / 1000;
        dataTable.pveTemp[i].maxcd =
            driver->read<int>(dataTable.pve[i].maxcd) / 1000;
        dataTable.pveTemp[i].id = driver->read<int>(dataTable.pve[i].id);
        if(dataTable.pveTemp[i].id == 0){continue;}
        int 固定坐标X = ReadDword(dataTable.pve[i].cc + 0x2b8);
        int 固定坐标Y = ReadDword(dataTable.pve[i].cc + 0x2C0);
        float pveX =
            (float)driver->read<int>(dataTable.pve[i].X);
        float pveY =
            (float)driver->read<int>(dataTable.pve[i].Y);
            
            float 野怪跨度X = pveX - pvePosX[i];
			  if (野怪跨度X < 0) {
			  	野怪跨度X = 野怪跨度X * -1;
			  }
			  float 野怪跨度Y = pveY - pvePosY[i];
			  if (野怪跨度Y < 0) {
			  	野怪跨度Y = 野怪跨度Y * -1;
			  }
			  //printf("X %.0f\tY %.0f\n", 野怪跨度X, 野怪跨度Y);
			  if (野怪跨度X > 1000 || 野怪跨度Y > 1000) {
			  	dataTable.pveTemp[i].coord.X = pveX;
                dataTable.pveTemp[i].coord.Y = pveY;
			  } else {
            
            std::pair<float, float> pvesmoothedCoords = smoothPosition(i, pveX, pveY, pvePosX, pvePosY);
            
            dataTable.pveTemp[i].coord.X = pvesmoothedCoords.first;
            dataTable.pveTemp[i].coord.Y = pvesmoothedCoords.second;
            }
            
            
            //int 野怪距敌 = 计算距离(ImVec2(dataTable.pveTemp[i].coord.X,
                            //dataTable.pveTemp[i].coord.Y), 目标坐标);
            dataTable.pveTemp[i].hp = driver->read<int>(dataTable.pve[i].hp);
            dataTable.pveTemp[i].maxhp = driver->read<int>(dataTable.pve[i].maxhp);
            //printf("hp %d  maxhp %d\n", dataTable.pveTemp[i].hp, dataTable.pveTemp[i].maxhp);
        map_buff = CalMatrixMem(dataTable.pveTemp[i].coord, dataTable.Matrix);
        map_buff.X = map_buff.X + SmallHPX;
        map_buff.Y = map_buff.Y + SmallHPY;
        实体野怪 = ImVec2((int)map_coord.X, (int)map_coord.Y);
        ImVec2 固定野怪 = ImVec2((int)pveX, (int)pveY);
        距离野怪 = 计算距离(自身坐标, 固定野怪);
        //printf("距离野怪 %d\n",距离野怪);
        // printf("X %.0f  Y %.0f\n", (float)dataTable.pveTemp[i].coord.X,
        // (float)dataTable.pveTemp[i].coord.Y); printf("X %.0f  Y %.0f\n",
        // (float)map_buff.X, (float)map_buff.Y);
        float pvegdX =
            (float)(固定坐标X * rientation * Wwra /
                        50000.0f +
                    Wwra);
        float pvegdY =
            (float)(固定坐标Y * rientation * Wwra /
                        50000.0f * -1 +
                    Wwra);

         //printf("ID %d\t距离 %d\t血量 %d\t伤害 %d\n",dataTable.pveTemp[i].id, 距离野怪, dataTable.pveTemp[i].hp, 惩戒伤害);

          if (自动惩戒 && 召唤师技能判断 && dataTable.pveTemp[i].hp > 0) {
          // printf("自动惩戒已开启\n");
          if (dataTable.pveTemp[i].id == 166018 ||
              dataTable.pveTemp[i].id == 166009 ||
              dataTable.pveTemp[i].id == 166012 ||
              dataTable.pveTemp[i].id == 1660221 ||
              dataTable.pveTemp[i].id == 166022 ||
              dataTable.pveTemp[i].id == 266010 ||
              dataTable.pveTemp[i].id == 266011 ||
              
              dataTable.pveTemp[i].id == 1010350 ||
              dataTable.pveTemp[i].id == 1010351 ||
              dataTable.pveTemp[i].id == 1010334 ||
              dataTable.pveTemp[i].id == 1010336 ||
              dataTable.pveTemp[i].id == 1010335 ||
              dataTable.pveTemp[i].id == 1010333) {
              
              for (int l = 0; l < 5; l++) {
			  野怪距敌 = 计算距离(固定野怪, ImVec2(dr_x[l], dr_y[l]));
			  //printf("野怪距敌 %d\n", 野怪距敌);
			  //printf("X坐标 %d\tY坐标 %d\t距敌 %d\t距自 %d\n", dr_x[l], dr_y[l], 野怪距敌, 距离野怪);
			  if (距离野怪 <= 10000) {
			  
			  	//提示
              ImGui::GetForegroundDrawList()->AddImage(
                      reinterpret_cast<ImTextureID>(其他贴图.图标[1].textureId),
                      {(int)map_buff.X - (map_buff.H * 0.2f),
                       (int)map_buff.Y - (map_buff.H * 0.2f) +
                           (-map_buff.H * 1.0f)},
                      {(int)map_buff.X + (map_buff.H * 0.2f),
                       (int)map_buff.Y + (map_buff.H * 0.2f) +
                           (-map_buff.H * 1.0f)});

			  if (野怪距敌 <= 10000) {
			  	野怪距敌判断 = true;
			  	//printf("野怪距敌判断成功\n");
			  	break;
			  }
			  }
			  
				}
				
                           
            if (智能不惩) {
              if (距离野怪 < 5900 && dataTable.pveTemp[i].hp <= 惩戒伤害 &&
                   野怪距敌判断 && !触摸缓冲) {
                惩戒判断 = true;
              } else {
                惩戒判断 = false;
              }
            } else {
              if (距离野怪 < 5900 && dataTable.pveTemp[i].hp > 0 && dataTable.pveTemp[i].hp <= 惩戒伤害 && !触摸缓冲) {
                惩戒判断 = true;
              } else {
                惩戒判断 = false;
              }
            }

            if (惩戒判断) {
              // printf("点击惩戒\n");
              触摸缓冲 = true;
              if (屏幕方向 == 3) {
                	Touch::Down(zhsY, yxpx - zhsX);
                } else if (屏幕方向 == 1) {
                	Touch::Down(yxpy - zhsY, zhsX);
                }
              std::thread cj([&]() {
                usleep(1000 * 5);
                Touch::Up();
                // std::this_thread::sleep_for(1);
                usleep(1000 * 250);
                触摸缓冲 = false;
              });
              // 启动线程
              cj.detach(); // 或者 t.detach();
            }
          }
        }

        if (dataTable.pveTemp[i].hp > 0 &&
            dataTable.pveTemp[i].cd == dataTable.pveTemp[i].maxcd) {
            
            if (dataTable.pveTemp[i].hp < dataTable.pveTemp[i].maxhp) {
            
            string text = std::to_string(dataTable.pveTemp[i].hp);

		  ImVec2 textSize2 = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, text.c_str());
		  ImVec2 textPos2 = ImVec2((float)(pvegdX + SmallMapX - 6.0 + 93) - textSize2.x * 0.5f, (float)(pvegdY + SmallMapY - 7.9 + 6) - textSize2.y * 0.25f); // 计算居中位置
          ImGui::GetForegroundDrawList()->AddText(
              NULL, 31.0f,
              textPos2,
              ImColor(0, 255, 255), text.c_str());
            
            
            
            }
            
            
          string text1 = std::to_string(dataTable.pveTemp[i].hp);

// 天蓝色135, 206, 235//赤红219,77,109
          /*ImVec2((int)map_coord.X - (map_coord.H * 0.5f),
                               (int)map_coord.Y - (map_coord.H * 1.32f)),
                        ImVec2((int)map_coord.X + (map_coord.H * 0.5),
                               (int)map_coord.Y + (map_coord.H * 0.2f)),*/
          
          ImVec2 topLeft((int)map_buff.X - (map_buff.H * 0.5f), (int)map_buff.Y - (map_buff.H * 0.075f));
		  ImVec2 bottomRight = ImVec2((int)map_buff.X + (map_buff.H * 0.5f), (int)map_buff.Y + (map_buff.H * 0.075f));


		  // 绘制血条的边框
		  ImGui::GetForegroundDrawList()->AddRect(topLeft, bottomRight, ImColor(255, 255, 255), 5);  // 使用白色绘制边框
/*if (自动惩戒 && 距离野怪 < 7500) {
		ImGui::GetForegroundDrawList()->AddImage(
                      reinterpret_cast<ImTextureID>(其他贴图.图标[1].textureId),
                      {(int)map_buff.X - (map_buff.H * 0.2f),
                       (int)map_buff.Y - (map_buff.H * 0.2f) +
                           (-map_buff.H * 1.0f)},
                      {(int)map_buff.X + (map_buff.H * 0.2f),
                       (int)map_buff.Y + (map_buff.H * 0.2f) +
                           (-map_buff.H * 1.0f)});
}*/
		  
float buff_hp = (float)dataTable.pveTemp[i].hp / (float)dataTable.pveTemp[i].maxhp - 0.5f;
// 绘制长条形的血条
//printf("伤害  %d\n", 惩戒伤害);
if(dataTable.pveTemp[i].hp <= 惩戒伤害 && dataTable.pveTemp[i].hp != dataTable.pveTemp[i].maxhp) {
ImGui::GetForegroundDrawList()->AddRectFilled(topLeft, ImVec2((int)map_buff.X + (map_buff.H * buff_hp), (int)map_buff.Y + (map_buff.H * 0.07f)), ImColor(255, 0, 0, 150), 5);//红色
} else {
ImGui::GetForegroundDrawList()->AddRectFilled(topLeft, ImVec2((int)map_buff.X + (map_buff.H * buff_hp), (int)map_buff.Y + (map_buff.H * 0.07f)), ImColor(0, 255, 255, 150), 5);//蓝色
}

// 计算文字的大小
ImVec2 textSize = ImGui::CalcTextSize(text1.c_str());

// 计算文字的位置，使其位于矩形方框的正中间
ImVec2 textPos((topLeft.x + bottomRight.x - textSize.x) * 0.5f, (topLeft.y + bottomRight.y - textSize.y) * 0.5f - (map_buff.H * 0.005f));
		  

          //ImVec2 textSize = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, text1.c_str());
		  //ImVec2 textPos = ImVec2(map_buff.X - textSize.x * 0.5f, map_buff.Y - (map_buff.H * 0.15f)); // 计算居中位置
ImGui::GetForegroundDrawList()->AddText(NULL, 33.0f, textPos, ImColor(255, 255, 255), text1.c_str());
        }
        if (dataTable.pveTemp[i].cd == 0 || dataTable.pveTemp[i].cd == dataTable.pveTemp[i].maxcd || dataTable.pveTemp[i].cd > 240 || dataTable.pveTemp[i].id == 166009 || dataTable.pveTemp[i].id == 1010333 || dataTable.pveTemp[i].id == 166018 || dataTable.pveTemp[i].id == 1010335 || dataTable.pveTemp[i].id == 166012 || dataTable.pveTemp[i].id == 1010334 || dataTable.pveTemp[i].id == 166022 || dataTable.pveTemp[i].id == 1010336 || dataTable.pveTemp[i].id == 1660221) {
        //166009/1010333/166018/1010335/166012/1010334/166022/1010336/1660221
          continue;
        } else {
        
          string text = std::to_string(dataTable.pveTemp[i].cd);

		  ImVec2 textSize2 = ImGui::GetFont()->CalcTextSizeA(ImGui::GetFontSize(), FLT_MAX, 0, text.c_str());
		  ImVec2 textPos2 = ImVec2((float)(pvegdX + SmallMapX - 6.0 + 93) - textSize2.x * 0.5f, (float)(pvegdY + SmallMapY - 7.9 + 6) - textSize2.y * 0.25f); // 计算居中位置
          ImGui::GetForegroundDrawList()->AddText(
              NULL, 31.0f,
              textPos2,
              ImColor(255, 255, 255), text.c_str());
        }
      }
    }
   if (野怪) {
    
    	dataTable.pveTemp[1].coord.X = -55000 + 2500;
    	dataTable.pveTemp[1].coord.Y = -55000 + 2500;
    
        Pvc_coord = CalMatrixMem(dataTable.pveTemp[1].coord, dataTable.Matrix);
        Pvc_coord.X = Pvc_coord.X + SmallHPX;
        Pvc_coord.Y = Pvc_coord.Y + SmallHPY;
        
std::string txt = "BY & BY是吴彦祖";
ImVec2 textSize = ImGui::CalcTextSize(txt.c_str());
ImVec2 textPos(Pvc_coord.X - textSize.x * 0.5f, Pvc_coord.Y - textSize.y * 0.5f);
    	绘制字体描边(34.0f,
        textPos,
        ImColor(0, 255, 255), txt.c_str());
    
    }
	
if (兵线) {
      int number; // 兵线数量

      long temp5 = ReadValue(libGame_base + 0x161910);
      BxAddress = ReadValue(ReadValue(temp5 + 0x138) + 0x108);
      number = 50;

      long cont = 0;
      for (int l = 0; l < number; l++) {
        bxAddress = ReadValue(BxAddress + (l * 0x18));
        int temp = driver->read<int>(bxAddress + 0x3c);
        if (temp == foeComp) {
      
          int bxxl = driver->read<int>(ReadValue(bxAddress + 0x168) + 0x98);

          dataTable.CeTemp[cont].coord.X = (float)driver->read<int>(
              ReadValue(ReadValue(ReadValue(bxAddress + 0x230) + 0xf0) + 0x10) +
              0x0);
          dataTable.CeTemp[cont].coord.Y = (float)driver->read<int>(
              ReadValue(ReadValue(ReadValue(bxAddress + 0x230) + 0xf0) + 0x10) +
              0x8);
          if (dataTable.CeTemp[cont].coord.X == 0 ||
              dataTable.CeTemp[cont].coord.Y == 0) {
            continue;
          }
          if (bxxl <= 0) {
            continue;
          } 
            Pvc_coord = CalMatrixMem(dataTable.CeTemp[cont].coord, dataTable.Matrix);
          Pvc_coord.X = Pvc_coord.X + SmallHPX;
        Pvc_coord.Y = Pvc_coord.Y + SmallHPY;
        
        if (实体兵线) {
            ImGui::GetForegroundDrawList()->AddCircleFilled(ImVec2(Pvc_coord.X, Pvc_coord.Y), 8.0f,
                                                      ImColor(0, 255, 255), 32);
            }
          dataTable.CeTemp[cont].coord.X =
              dataTable.CeTemp[cont].coord.X * rientation * Wwra / 50000.0f +
              Wwra;
          dataTable.CeTemp[cont].coord.Y = dataTable.CeTemp[cont].coord.Y *
                                               rientation * Wwra / 50000.0f *
                                               -1 +
                                           Wwra;
                                           
                             if (ESPMenu.是否开启共享) {
                
                    
                  //定义传输兵线
                bingx = dataTable.CeTemp[cont].coord.X * 1.0;
                bingy =    dataTable.CeTemp[cont].coord.Y * 1.0;
                soldier += std::to_string(bingx + ESPMenu.兵线左右调整) + "," + std::to_string(bingy + ESPMenu.兵线左右调整) + "," + std::to_string(temp) + "==";
               }                                    
                                           
          cont++;
        }
      }
      dataTable.xbsl = cont;

      for (int i = 0; i < dataTable.xbsl; i++) {
        float x = dataTable.CeTemp[i].coord.X + SmallMapX + 93;
        float y = dataTable.CeTemp[i].coord.Y + SmallMapY + 6;
        ImGui::GetForegroundDrawList()->AddRect(
            ImVec2((float)x - soldiers_dx, (float)y - soldiers_dx),
            ImVec2((float)x + soldiers_dx, (float)y + soldiers_dx), Paint_red,
            soldiers_dx, 0, soldiers_dx * 2);
      }
    }

        
        
        
    if (绘制触摸) {
      ImGui::GetForegroundDrawList()->AddCircleFilled(ImVec2(zhsX, zhsY), 50.0f,
                                                      Paint_chired, 32);
      绘制触摸 = false;
    }
    
    //}
    
  } else if (ReadDword(libGame_base + 0x1DF82D4) == 0) {
    // 获取当前时间
    /*#include <time.h>
#include <string>

// 假设你已经包含了ImGui的头文件，并且已经初始化了ImGui环境

void RenderTimeText() {
    // 获取当前时间
    time_t now = time(NULL);
    struct tm *ltm = localtime(&now);

    // 定义一个足够大的缓冲区来存储格式化的时间字符串
    char buffer[100];

    // 使用strftime格式化时间
    strftime(buffer, sizeof(buffer), "%m月%d日 %H:%M:%S", ltm);

    // 使用ImGui的AddText函数绘制时间
    ImGui::GetForegroundDrawList()->AddText(
        ImVec2(0.0375 * yxpx, 0.925925925925925925 * yxpy),
        ImColor(219, 77, 109), "Nian\n时间\n%s", buffer);
}*/
    //当前时间戳
    



    // 获取当前时间
                    auto now = std::chrono::system_clock::now();
                    auto now_c = std::chrono::system_clock::to_time_t(now);
                    std::stringstream ss;
                    ss << std::put_time(std::localtime(&now_c), " 时间：%m月%d日 %H:%M:%S");
                    
                    // 添加时间信息到文本中
                    std::string text = " BY定制内核\n 等待进入对局\n";
                    text += ss.str();
                    const char* cstr = text.c_str();
    //
    ImVec2 字体尺寸 = ImGui::CalcTextSize(cstr);
    
    绘制字体描边(30.0f + yxpx / 1000,
        ImVec2(0.0375 * yxpx, 0.98 * yxpy - 字体尺寸.y),
        ImColor(219, 77, 109), cstr);
  }
if (ESPMenu.是否开启共享) {
        gameDataStr = 
        MG_PACKET_PREFIX + 设备id + 
        MG_PACKET_ROOM_SEPARATOR + character + 
        MG_PACKET_SECTION_SEPARATOR + creeps + 
        MG_PACKET_SECTION_SEPARATOR + soldier;
        char* gameData = (char*)gameDataStr.data();  //设备
        mgSendShareData(gameData, strlen(gameData));
    }

}
