package com.qy.wzryoverlay;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.media.projection.MediaProjectionManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.WindowManager;
import android.widget.ArrayAdapter;
import android.widget.AdapterView;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.SeekBar;
import android.widget.Spinner;
import android.widget.Switch;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Collections;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.WebSocket;
import okhttp3.WebSocketListener;

public class MainActivity extends Activity {
    // ===== 服务器配置 =====
    // DEFAULT_API_BASE：PHP 后台地址（APP 登录、拉取配置用）
    // 强烈建议使用域名而非 IP，换服务器时只改 DNS 解析即可，无需重新打包 APP
    private static final String DEFAULT_API_BASE = "http://你的域名或新服务器IP";
    // 不再需要备用后台，删除旧 BACKUP_API_BASE
    // DEFAULT_SERVER_HOST：8888 WebSocket 服务器地址（登录后会从后台 game_servers API 动态获取，此处仅为兜底）
    private static final String DEFAULT_SERVER_HOST = "你的域名或新服务器IP";
    private static final int DEFAULT_SERVER_PORT = 8888;
    // ======================
    private static final int CURRENT_VERSION_CODE = 11;
    private static final int REQUEST_MEDIA_PROJECTION = 5001;
    private static final String CURRENT_VERSION_NAME = "v6.1.11";
    private static final MediaType JSON = MediaType.parse("application/json; charset=utf-8");
    private static final int DEFAULT_FPS = 90;
    private static final int[] FPS_VALUES = new int[]{60, 90, 120, 144};

    private final OkHttpClient http = new OkHttpClient();
    private final List<GameServer> servers = new ArrayList<>();
    private final List<String> rooms = new ArrayList<>();
    private final Map<String, String> roomServerAddresses = new HashMap<>();
    private String selectedRoomLabel = "";
    private SharedPreferences prefs;
    private EditText usernameInput;
    private EditText passwordInput;
    private EditText registerUsernameInput;
    private EditText registerPasswordInput;
    private EditText activateUsernameInput;
    private EditText cardInput;
    private EditText activateCardInput;
    private EditText securityInput;
    private EditText apiBaseInput;
    private Spinner fpsSpinner;
    private Button minionFixButton;
    private Button roomSelectButton;
    private Button roomArrowButton;
    private Button connectRoomButton;
    private ScrollView roomListScroll;
    private LinearLayout roomListContent;
    private TextView roomCountBadge;
    private TextView statusText;
    private WebSocket roomSocket;
    private final List<WebSocket> roomListSockets = new ArrayList<>();
    private final Handler roomRefreshHandler = new Handler(Looper.getMainLooper());
    private int roomRefreshToken;
    private String trialUrl = "";
    private String buyUrl = "";
    private String downloadUrl = "";
    private String groupUrl = "";
    private String accountCardCode = "";
    private String accountExpiresAt = "";
    private String accountCardStatus = "";
    private boolean appLoginRequired = true;
    private boolean appLoginEnabled;
    private boolean appOnlyLogin;
    private String appLoginUsername = "";
    private String appLoginPassword = "";
    private String appLoginTitle = "";
    private String appLoginMessage = "";
    private boolean appLoginDialogShown;
    private boolean loggedIn;
    private boolean secureMode;
    private boolean pendingOverlayStart;
    private boolean updateBlocked;
    private boolean booting;
    private boolean loadingRooms;
    private String activeApiBase = DEFAULT_API_BASE;
    private String bundledApiBase = "";
    private String bundledServerHost = "";
    private int bundledServerPort = DEFAULT_SERVER_PORT;
    private String bundledLoginMode = "auto";
    private String bundledAppName = "ALin雷达";
    private String bundledBuyUrl = "";
    private boolean fixedBundledServer;
    private int minionLaneRotationSteps;
    private float heroIconScale = 1f;
    private final Handler heartbeatHandler = new Handler(Looper.getMainLooper());
    private Runnable heartbeatRunnable;
    private int themeIndex;
    private int bgStart;
    private int bgEnd;
    private int cardBg;
    private int borderColor;
    private int primaryText;
    private int secondaryText;
    private int inputBg;
    private int inputText;
    private int hintText;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        loadBundledAppConfig();
        activeApiBase = fixedBundledServer && bundledApiBase.length() > 0
                ? bundledApiBase
                : normalizeApiBase(prefs.getString("api_base", bundledApiBase.length() > 0 ? bundledApiBase : DEFAULT_API_BASE));
        if (fixedBundledServer && bundledApiBase.length() > 0) {
            prefs.edit().putString("api_base", bundledApiBase).apply();
        }
        loggedIn = prefs.getBoolean("logged_in", false);
        appOnlyLogin = prefs.getBoolean("app_only_login", false);
        secureMode = prefs.getBoolean("secure_mode", false);
        themeIndex = prefs.getInt("theme", 0);
        minionLaneRotationSteps = prefs.getInt("minion_lane_rotation_steps", 0);
        heroIconScale = prefs.getFloat("hero_icon_scale", 1f);
        heroX = prefs.getFloat("hero_x", 0f);
        heroY = prefs.getFloat("hero_y", 0f);
        minionX = prefs.getFloat("minion_x", 0f);
        minionY = prefs.getFloat("minion_y", 0f);
        monsterX = prefs.getFloat("monster_x", 0f);
        monsterY = prefs.getFloat("monster_y", 0f);
        accountCardCode = prefs.getString("card_code", "");
        accountExpiresAt = prefs.getString("expires_at", "");
        accountCardStatus = prefs.getString("card_status", "");
        selectedRoomLabel = prefs.getString("selected_room", "");
        buyUrl = bundledBuyUrl;
        applyThemeColors();
        applySecureMode();
        if (Build.VERSION.SDK_INT >= 33) requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 10);
        if (!isFrontendOnlyMode()) loadAppLinks();
        booting = true;
        showStartupPage();
        if (isFrontendOnlyMode()) enterWithoutLogin("纯前端模式，已免登录进入主页");
        else checkRemoteConfig(false, false);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (!isFrontendOnlyMode()) checkRemoteConfig(false, false);
        if (pendingOverlayStart && (Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(this))) {
            pendingOverlayStart = false;
            startOverlay();
        }
    }

    @Override
    protected void onDestroy() {
        closeRoomSocket();
        stopOnlineHeartbeat();
        super.onDestroy();
    }

    private void showStartupPage() {
        LinearLayout root = createRoot(appTitle(), "正在检测版本和公告");
        statusText = status(root);
        setStatus("正在连接后台...");
    }

    private void showLoginPage() {
        closeRoomSocket();
        stopOnlineHeartbeat();
        LinearLayout root = createRoot(appTitle(), "账号登录");
        addThemeMenu(root);

        if (!(fixedBundledServer && bundledApiBase.length() > 0)) {
            LinearLayout apiCard = section(root);
            TextView apiTitle = label("后台地址");
            apiTitle.setTextSize(16);
            apiTitle.setTypeface(Typeface.DEFAULT_BOLD);
            apiCard.addView(apiTitle, new LinearLayout.LayoutParams(-1, -2));
            apiBaseInput = makeInput("http://域名或服务器IP");
            apiBaseInput.setInputType(InputType.TYPE_TEXT_VARIATION_URI);
            apiBaseInput.setText(activeApiBase);
            apiCard.addView(apiBaseInput, lpTop(48, 12));
            apiCard.addView(makeSmallButton("保存并重连后台", this::saveApiBaseAndReconnect), lpTop(40, 10));
        }

        LinearLayout tabRow = new LinearLayout(this);
        tabRow.setOrientation(LinearLayout.HORIZONTAL);
        Button loginTab = makeSmallButton("登录", () -> {});
        Button registerTab = makeSmallButton("注册", () -> {});
        Button activateTab = makeSmallButton("激活", () -> {});
        tabRow.addView(loginTab, new LinearLayout.LayoutParams(0, dp(42), 1));
        tabRow.addView(registerTab, new LinearLayout.LayoutParams(0, dp(42), 1));
        tabRow.addView(activateTab, new LinearLayout.LayoutParams(0, dp(42), 1));
        root.addView(tabRow, lpTop(-2, 12));

        LinearLayout card = section(root);
        TextView title = label("账号登录");
        title.setTextSize(18);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        card.addView(title, new LinearLayout.LayoutParams(-1, -2));

        usernameInput = makeInput("用户名");
        usernameInput.setText(prefs.getString("username", ""));
        card.addView(usernameInput, lpTop(48, 14));

        passwordInput = makeInput("密码");
        passwordInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        card.addView(passwordInput, lpTop(48, 10));

        Button loginBtn = makeButton("登录");
        loginBtn.setOnClickListener(v -> login());
        card.addView(loginBtn, lpTop(48, 14));

        LinearLayout linkRow = new LinearLayout(this);
        linkRow.setOrientation(LinearLayout.HORIZONTAL);
        linkRow.addView(makeSmallButton("领卡", this::claimTrialCard), new LinearLayout.LayoutParams(0, dp(40), 1));
        linkRow.addView(makeSmallButton("买卡", () -> openUrl(buyUrl)), new LinearLayout.LayoutParams(0, dp(40), 1));
        card.addView(linkRow, lpTop(-2, 10));

        LinearLayout registerCard = section(root);
        TextView registerTitle = label("注册账号");
        registerTitle.setTextSize(18);
        registerTitle.setTypeface(Typeface.DEFAULT_BOLD);
        registerCard.addView(registerTitle, new LinearLayout.LayoutParams(-1, -2));
        registerUsernameInput = makeInput("用户名");
        registerUsernameInput.setText(prefs.getString("username", ""));
        registerCard.addView(registerUsernameInput, lpTop(48, 14));
        registerPasswordInput = makeInput("密码");
        registerPasswordInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        registerCard.addView(registerPasswordInput, lpTop(48, 10));
        cardInput = makeInput("卡密（注册时填写）");
        registerCard.addView(cardInput, lpTop(48, 10));
        securityInput = makeInput("安全码（注册时填写）");
        securityInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        registerCard.addView(securityInput, lpTop(48, 10));
        Button registerBtn = makeButton("注册");
        registerBtn.setOnClickListener(v -> register());
        registerCard.addView(registerBtn, lpTop(48, 14));

        LinearLayout activateCard = section(root);
        TextView activateTitle = label("激活卡密");
        activateTitle.setTextSize(18);
        activateTitle.setTypeface(Typeface.DEFAULT_BOLD);
        activateCard.addView(activateTitle, new LinearLayout.LayoutParams(-1, -2));
        activateUsernameInput = makeInput("用户名");
        activateUsernameInput.setText(prefs.getString("username", ""));
        activateCard.addView(activateUsernameInput, lpTop(48, 14));
        activateCardInput = makeInput("卡密（激活时填写）");
        activateCard.addView(activateCardInput, lpTop(48, 10));
        Button activateBtn = makeButton("激活卡密");
        activateBtn.setOnClickListener(v -> activateCard());
        activateCard.addView(activateBtn, lpTop(48, 14));

        registerCard.setVisibility(View.GONE);
        activateCard.setVisibility(View.GONE);
        loginTab.setOnClickListener(v -> showAuthCard(card, registerCard, activateCard, loginTab, registerTab, activateTab, 0));
        registerTab.setOnClickListener(v -> showAuthCard(card, registerCard, activateCard, loginTab, registerTab, activateTab, 1));
        activateTab.setOnClickListener(v -> showAuthCard(card, registerCard, activateCard, loginTab, registerTab, activateTab, 2));
        showAuthCard(card, registerCard, activateCard, loginTab, registerTab, activateTab, 0);

        statusText = status(root);
        setStatus("请输入账号信息");
    }

    private void showAuthCard(LinearLayout loginCard, LinearLayout registerCard, LinearLayout activateCard,
                              Button loginTab, Button registerTab, Button activateTab, int index) {
        loginCard.setVisibility(index == 0 ? View.VISIBLE : View.GONE);
        registerCard.setVisibility(index == 1 ? View.VISIBLE : View.GONE);
        activateCard.setVisibility(index == 2 ? View.VISIBLE : View.GONE);
        loginTab.setBackground(makeGradient(index == 0 ? 0xff2563eb : 0xff94a3b8, index == 0 ? 0xff7c3aed : 0xff64748b, dp(10)));
        registerTab.setBackground(makeGradient(index == 1 ? 0xff2563eb : 0xff94a3b8, index == 1 ? 0xff7c3aed : 0xff64748b, dp(10)));
        activateTab.setBackground(makeGradient(index == 2 ? 0xff2563eb : 0xff94a3b8, index == 2 ? 0xff7c3aed : 0xff64748b, dp(10)));
    }

    private void showUpdateNotes() {
        int lastSeen = prefs.getInt("last_seen_version", 0);
        if (lastSeen >= CURRENT_VERSION_CODE) return;
        prefs.edit().putInt("last_seen_version", CURRENT_VERSION_CODE).apply();
        String notes = "v6.1.11 更新说明\n\n"
            + "▶ 新增一键适配，自动调整小地图大小和位置\n"
            + "▶ 新增截屏识别小地图功能（需授权截屏权限）\n"
            + "▶ 技能面板新增间距调节，缩放和间距分开控制\n"
            + "▶ 技能面板图标显示修复，缩放不再丢失图标\n"
            + "▶ 对方死亡后地图上不再显示头像，技能面板保留\n"
            + "▶ 加减号微调精度修复，每次加减1单位\n"
            + "▶ 悬浮窗房间列表支持下拉滑动\n"
            + "▶ 主页房间号背景改为透明\n"
            + "▶ 点击刷新房间立即显示状态反馈";
        new android.app.AlertDialog.Builder(this)
            .setTitle("更新说明")
            .setMessage(notes)
            .setPositiveButton("知道了", null)
            .show();
    }

    private void showRadarPage() {
        LinearLayout root = createPlainRoot("王者荣耀小地图");
        showUpdateNotes();

        LinearLayout roomSelectRow = new LinearLayout(this);
        roomSelectRow.setOrientation(LinearLayout.HORIZONTAL);
        roomSelectButton = makeSmallButton(roomSelectLabel(), this::toggleRoomList);
        roomSelectButton.setGravity(Gravity.CENTER_VERTICAL);
        roomSelectButton.setPadding(dp(12), 0, dp(12), 0);
        roomSelectButton.setTextSize(14);
        roomSelectRow.addView(roomSelectButton, new LinearLayout.LayoutParams(0, dp(54), 1));
        roomCountBadge = label("0间");
        roomCountBadge.setGravity(Gravity.CENTER);
        roomCountBadge.setTextColor(primaryText);
        roomCountBadge.setTextSize(13);
        LinearLayout.LayoutParams countLp = new LinearLayout.LayoutParams(dp(52), dp(54));
        countLp.leftMargin = dp(8);
        roomSelectRow.addView(roomCountBadge, countLp);
        roomArrowButton = makeSmallButton("v", this::toggleRoomList);
        LinearLayout.LayoutParams arrowLp = new LinearLayout.LayoutParams(dp(50), dp(54));
        arrowLp.leftMargin = dp(8);
        roomSelectRow.addView(roomArrowButton, arrowLp);
        Button refreshRoomsButton = makeSmallButton("刷新", this::refreshAllRooms);
        LinearLayout.LayoutParams refreshLp = new LinearLayout.LayoutParams(dp(62), dp(54));
        refreshLp.leftMargin = dp(8);
        roomSelectRow.addView(refreshRoomsButton, refreshLp);
        root.addView(roomSelectRow, lpTop(-2, 26));

        LinearLayout roomListRow = new LinearLayout(this);
        roomListRow.setOrientation(LinearLayout.HORIZONTAL);
        roomListRow.setGravity(Gravity.TOP);
        roomListScroll = new ScrollView(this);
        roomListScroll.setFillViewport(false);
        roomListScroll.setNestedScrollingEnabled(true);
        roomListScroll.setVisibility(View.GONE);
        roomListScroll.setBackground(makeStrokeBox(0x33ffffff, borderColor, dp(8)));
        roomListContent = new LinearLayout(this);
        roomListContent.setOrientation(LinearLayout.VERTICAL);
        roomListContent.setPadding(dp(5), dp(5), dp(5), dp(5));
        roomListScroll.addView(roomListContent, new ScrollView.LayoutParams(-1, -2));
        roomListRow.addView(roomListScroll, new LinearLayout.LayoutParams(0, -2, 1));
        root.addView(roomListRow, lpTop(-2, 6));

        fpsSpinner = new Spinner(this);
        ArrayAdapter<String> fpsAdapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, new String[]{"60 FPS", "90 FPS", "120 FPS", "144 FPS"});
        fpsAdapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        fpsSpinner.setAdapter(fpsAdapter);
        fpsSpinner.setSelection(fpsIndexFor(prefs.getInt("radar_fps", DEFAULT_FPS)));
        fpsSpinner.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override
            public void onItemSelected(AdapterView<?> parent, View view, int position, long id) {
                saveSelectedFps(fpsFromIndex(position));
            }

            @Override
            public void onNothingSelected(AdapterView<?> parent) {
            }
        });
        root.addView(fpsSpinner, lpTop(54, 12));

        connectRoomButton = makeButton(connectRoomButtonText());
        connectRoomButton.setOnClickListener(v -> startOverlay());
        root.addView(connectRoomButton, lpTop(48, 12));
        updateConnectRoomButton();

        minionFixButton = makeButton(minionFixText());
        minionFixButton.setOnClickListener(v -> cycleMinionLaneFix());
        applyMinionFixButtonStyle();
        root.addView(minionFixButton, lpTop(48, 12));

        root.addView(switchRow("显示技能状态", "开启后在小地图右侧显示英雄大招和技能状态", prefs.getBoolean("show_skill_panel", true), on -> {
            prefs.edit().putBoolean("show_skill_panel", on).apply();
            setOverlaySkillPanel(on);
            setStatus(on ? "技能状态已显示" : "技能状态已隐藏");
        }), lpTop(-2, 24));
        root.addView(switchRow("防截图", "开启后悬浮窗内容不会被截图捕获", secureMode, on -> {
            secureMode = on;
            prefs.edit().putBoolean("secure_mode", secureMode).apply();
            applySecureMode();
            setStatus(secureMode ? "防截图已开启" : "防截图已关闭");
        }), lpTop(-2, 20));

        Button capturePermBtn = makeButton(NativeOverlayService.sProjectionData != null ? "截屏权限已授权 ✓" : "授权截屏(一键适配)");
        capturePermBtn.setOnClickListener(v -> requestScreenCapture());
        root.addView(capturePermBtn, lpTop(46, 10));

        statusText = status(root);
        setStatus("正在读取房间...");
        startOnlineHeartbeat();
        if (fixedBundledServer) loadBundledServerOnly();
        else loadPublicServers();
    }

    private void addThemeMenu(LinearLayout root) {
        Button button = makeSmallButton("主题设置", this::showThemeDialog);
        root.addView(button, lpTop(42, 10));
    }

    private void showThemeDialog() {
        ScrollView scroll = new ScrollView(this);
        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(dp(14), dp(8), dp(14), dp(8));
        scroll.addView(content, new ScrollView.LayoutParams(-1, -2));

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("主题")
                .setView(scroll)
                .setNegativeButton("取消", null)
                .create();

        addThemeOption(content, dialog, 0, "亮色", 0xfff8fafc, 0xffdbeafe, 0xff2563eb, 0xff0f172a);
        addThemeOption(content, dialog, 1, "深色", 0xff111827, 0xff1e293b, 0xff60a5fa, 0xffffffff);
        addThemeOption(content, dialog, 2, "蓝白", 0xffe0f2fe, 0xffeff6ff, 0xff7c3aed, 0xff0f172a);
        addThemeOption(content, dialog, 3, "墨绿", 0xff052e2b, 0xff064e3b, 0xff34d399, 0xffffffff);
        dialog.show();
    }

    private void addThemeOption(LinearLayout parent, AlertDialog dialog, int index, String name,
                                int startColor, int endColor, int accentColor, int textColor) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(dp(12), dp(8), dp(12), dp(8));
        row.setBackground(makeStrokeBox(startColor,
                index == themeIndex ? accentColor : borderColor, dp(12)));
        row.setOnClickListener(v -> {
            applyTheme(index);
            dialog.dismiss();
        });

        LinearLayout swatches = new LinearLayout(this);
        swatches.setOrientation(LinearLayout.HORIZONTAL);
        swatches.addView(themeSwatch(startColor), new LinearLayout.LayoutParams(dp(24), dp(24)));
        LinearLayout.LayoutParams gap = new LinearLayout.LayoutParams(dp(24), dp(24));
        gap.leftMargin = dp(5);
        swatches.addView(themeSwatch(endColor), gap);
        LinearLayout.LayoutParams accentGap = new LinearLayout.LayoutParams(dp(24), dp(24));
        accentGap.leftMargin = dp(5);
        swatches.addView(themeSwatch(accentColor), accentGap);
        row.addView(swatches, new LinearLayout.LayoutParams(dp(86), dp(32)));

        TextView label = new TextView(this);
        label.setText(index == themeIndex ? name + "  当前" : name);
        label.setTextColor(textColor);
        label.setTextSize(15);
        label.setTypeface(index == themeIndex ? Typeface.DEFAULT_BOLD : Typeface.DEFAULT);
        row.addView(label, new LinearLayout.LayoutParams(0, dp(36), 1));

        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(54));
        lp.bottomMargin = dp(8);
        parent.addView(row, lp);
    }

    private View themeSwatch(int color) {
        View view = new View(this);
        view.setBackground(makeStrokeBox(color, 0x66ffffff, dp(999)));
        return view;
    }

    private void applyTheme(int index) {
        themeIndex = index;
        prefs.edit().putInt("theme", themeIndex).apply();
        applyThemeColors();
        if (loggedIn) showRadarPage(); else showLoginPage();
    }

    private void addSliderGroup(LinearLayout parent, String title, String target) {
        TextView t = label(title + "位置");
        t.setPadding(0, dp(12), 0, dp(4));
        parent.addView(t, new LinearLayout.LayoutParams(-1, -2));
        addOffsetSlider(parent, target, true);
        addOffsetSlider(parent, target, false);
    }

    private void addOffsetSlider(LinearLayout parent, String target, boolean isX) {
        TextView title = label(isX ? "左右" : "上下");
        parent.addView(title, new LinearLayout.LayoutParams(-1, -2));
        SeekBar bar = new SeekBar(this);
        bar.setMax(200);
        float current = currentOffsetValue(target, isX);
        bar.setProgress(Math.max(0, Math.min(200, Math.round(current + 100))));
        bar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override
            public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) {
                if (!fromUser) return;
                float value = progress - 100;
                setOffsetFromSliders(target, isX, value);
            }

            @Override public void onStartTrackingTouch(SeekBar seekBar) {}
            @Override public void onStopTrackingTouch(SeekBar seekBar) {}
        });
        parent.addView(bar, new LinearLayout.LayoutParams(-1, dp(38)));
    }

    private float heroX, heroY, minionX, minionY, monsterX, monsterY;

    private void addOverlayBoundsSliders(LinearLayout parent) {
        TextView title = label("悬浮窗调节");
        title.setPadding(0, dp(12), 0, dp(4));
        parent.addView(title, new LinearLayout.LayoutParams(-1, -2));
        int screenW = getResources().getDisplayMetrics().widthPixels;
        int screenH = getResources().getDisplayMetrics().heightPixels;
        addOverlaySlider(parent, "悬浮窗左右", "overlay_x", 0, Math.max(dp(1), screenW - dp(80)), prefs.getInt("overlay_x", dp(12)));
        addOverlaySlider(parent, "悬浮窗上下", "overlay_y", 0, Math.max(dp(1), screenH - dp(120)), prefs.getInt("overlay_y", dp(80)));
        addOverlaySlider(parent, "悬浮窗大小", "overlay_size", dp(80), dp(520), prefs.getInt("overlay_size", dp(260)));
    }

    private void addOverlaySlider(LinearLayout parent, String titleText, String key, int min, int max, int current) {
        TextView title = label(titleText);
        parent.addView(title, new LinearLayout.LayoutParams(-1, -2));
        SeekBar bar = new SeekBar(this);
        bar.setMax(Math.max(1, max - min));
        bar.setProgress(Math.max(0, Math.min(max - min, current - min)));
        bar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override
            public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) {
                if (!fromUser) return;
                int value = min + progress;
                prefs.edit().putInt(key, value).apply();
                sendOverlayBoundsFromPrefs();
            }

            @Override public void onStartTrackingTouch(SeekBar seekBar) {}
            @Override public void onStopTrackingTouch(SeekBar seekBar) {}
        });
        parent.addView(bar, new LinearLayout.LayoutParams(-1, dp(38)));
    }

    private void addHeroScaleSlider(LinearLayout parent) {
        TextView title = label("头像缩放 " + Math.round(heroIconScale * 100) + "%");
        parent.addView(title, new LinearLayout.LayoutParams(-1, -2));
        SeekBar bar = new SeekBar(this);
        bar.setMax(150);
        bar.setProgress(Math.max(0, Math.min(150, Math.round(heroIconScale * 100) - 50)));
        bar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override
            public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) {
                if (!fromUser) return;
                heroIconScale = (progress + 50) / 100f;
                title.setText("头像缩放 " + Math.round(heroIconScale * 100) + "%");
                prefs.edit().putFloat("hero_icon_scale", heroIconScale).apply();
                setOverlayHeroScale(heroIconScale);
            }

            @Override public void onStartTrackingTouch(SeekBar seekBar) {}
            @Override public void onStopTrackingTouch(SeekBar seekBar) {}
        });
        parent.addView(bar, new LinearLayout.LayoutParams(-1, dp(38)));
    }

    private void setOffsetFromSliders(String target, boolean isX, float value) {
        if ("hero".equals(target)) {
            if (isX) heroX = value; else heroY = value;
            prefs.edit().putFloat(isX ? "hero_x" : "hero_y", value).apply();
            setOverlayOffset(target, heroX, heroY);
        } else if ("minion".equals(target)) {
            if (isX) minionX = value; else minionY = value;
            prefs.edit().putFloat(isX ? "minion_x" : "minion_y", value).apply();
            setOverlayOffset(target, minionX, minionY);
        } else {
            if (isX) monsterX = value; else monsterY = value;
            prefs.edit().putFloat(isX ? "monster_x" : "monster_y", value).apply();
            setOverlayOffset(target, monsterX, monsterY);
        }
    }

    private float currentOffsetValue(String target, boolean isX) {
        if ("hero".equals(target)) return isX ? heroX : heroY;
        if ("minion".equals(target)) return isX ? minionX : minionY;
        return isX ? monsterX : monsterY;
    }

    private void setOverlayOffset(String target, float x, float y) {
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_SET_OFFSET);
        intent.putExtra("target", target);
        intent.putExtra("x", x);
        intent.putExtra("y", y);
        startService(intent);
    }

    private void setOverlayHeroScale(float scale) {
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_SET_HERO_SCALE);
        intent.putExtra("scale", scale);
        startService(intent);
    }

    private void setOverlayAdjustMode(boolean enabled) {
        prefs.edit().putBoolean("overlay_adjust_mode", enabled).apply();
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_SET_ADJUST_MODE);
        intent.putExtra("enabled", enabled);
        startService(intent);
    }

    private void setOverlaySkillPanel(boolean enabled) {
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_SET_SKILL_PANEL);
        intent.putExtra("enabled", enabled);
        startService(intent);
    }

    private void loadBundledAppConfig() {
        try (InputStream in = getAssets().open("radar-app-config.json")) {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buf = new byte[1024];
            int len;
            while ((len = in.read(buf)) > 0) out.write(buf, 0, len);
            JSONObject json = new JSONObject(new String(out.toByteArray(), StandardCharsets.UTF_8));
            bundledApiBase = normalizeApiBase(json.optString("apiBase", ""));
            bundledServerHost = normalizeServerHost(json.optString("serverHost", ""));
            bundledServerPort = normalizeServerPort(json.optString("serverPort", ""), DEFAULT_SERVER_PORT);
            bundledLoginMode = json.optString("loginMode", "auto").trim().toLowerCase();
            bundledAppName = json.optString("appName", bundledAppName).trim();
            bundledBuyUrl = json.optString("buyUrl", "").trim();
            if (bundledAppName.length() == 0) bundledAppName = "ALin雷达";
            if (!"backend".equals(bundledLoginMode) && !"frontend".equals(bundledLoginMode)) bundledLoginMode = "auto";
            fixedBundledServer = json.optBoolean("fixed", false) && bundledApiBase.length() > 0;
        } catch (Exception ignored) {
            bundledApiBase = "";
            bundledServerHost = "";
            bundledServerPort = DEFAULT_SERVER_PORT;
            bundledLoginMode = "auto";
            bundledAppName = "ALin雷达";
            bundledBuyUrl = "";
            fixedBundledServer = false;
        }
    }

    private String apiUrl(String path) {
        return activeApiBase + path;
    }

    private Request.Builder apiRequest(String path) {
        return new Request.Builder().url(apiUrl(path));
    }

    private String normalizeApiBase(String value) {
        String raw = value == null ? "" : value.trim();
        if (raw.length() == 0) raw = DEFAULT_API_BASE;
        if (!raw.matches("(?i)^[a-z][a-z0-9+.-]*://.*")) raw = "http://" + raw;
        while (raw.endsWith("/") && raw.length() > "http://".length()) {
            raw = raw.substring(0, raw.length() - 1);
        }
        return raw;
    }

    private String normalizeServerHost(String value) {
        String raw = value == null ? "" : value.trim();
        raw = raw.replaceFirst("(?i)^https?://", "");
        raw = raw.replaceFirst("(?i)^wss?://", "");
        int slash = raw.indexOf('/');
        if (slash >= 0) raw = raw.substring(0, slash);
        int colon = raw.indexOf(':');
        if (colon >= 0) raw = raw.substring(0, colon);
        return raw.trim();
    }

    private boolean isConfiguredApiBase(String value) {
        if (value == null || value.trim().length() == 0 || value.contains("你的域名")) return false;
        Uri uri = Uri.parse(normalizeApiBase(value));
        return uri.getScheme() != null && uri.getHost() != null && uri.getHost().trim().length() > 0;
    }

    private boolean shouldAutoEnterWithoutBackend() {
        return fixedBundledServer && ("auto".equals(bundledLoginMode) || "frontend".equals(bundledLoginMode));
    }

    private boolean isFrontendOnlyMode() {
        return fixedBundledServer && "frontend".equals(bundledLoginMode);
    }

    private int normalizeServerPort(String value, int fallback) {
        try {
            int port = Integer.parseInt(value == null ? "" : value.trim());
            return port > 0 && port <= 65535 ? port : fallback;
        } catch (Exception ignored) {
            return fallback;
        }
    }

    private String preferNonEmpty(String remoteValue, String currentValue) {
        String next = remoteValue == null ? "" : remoteValue.trim();
        if (next.length() > 0) return next;
        return currentValue == null ? "" : currentValue;
    }

    private String appTitle() {
        return bundledAppName == null || bundledAppName.trim().length() == 0 ? "ALin雷达" : bundledAppName.trim();
    }

    private int brandIconResId() {
        int customIcon = getResources().getIdentifier("ic_launcher", "mipmap", getPackageName());
        if (customIcon != 0) return customIcon;
        return getResources().getIdentifier("ic_radar", "drawable", getPackageName());
    }

    private boolean applyApiBaseFromInput() {
        if (fixedBundledServer && bundledApiBase.length() > 0) {
            activeApiBase = bundledApiBase;
            prefs.edit().putString("api_base", bundledApiBase).apply();
            return true;
        }
        if (apiBaseInput == null) return true;
        String next = normalizeApiBase(apiBaseInput.getText().toString());
        if (!isConfiguredApiBase(next)) {
            setStatus("请先填写后台地址，例如 http://example.com");
            return false;
        }
        activeApiBase = next;
        prefs.edit().putString("api_base", next).apply();
        apiBaseInput.setText(next);
        return true;
    }

    private void saveApiBaseAndReconnect() {
        if (!applyApiBaseFromInput()) return;
        setStatus("后台地址已保存，正在重连...");
        loadAppLinks();
        checkRemoteConfig(true, false);
    }

    private void sendOverlayBoundsFromPrefs() {
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_SET_OVERLAY_BOUNDS);
        intent.putExtra("x", prefs.getInt("overlay_x", dp(12)));
        intent.putExtra("y", prefs.getInt("overlay_y", dp(80)));
        intent.putExtra("size", prefs.getInt("overlay_size", dp(260)));
        startService(intent);
    }

    private void loadPublicServers() {
        if (fixedBundledServer) {
            loadBundledServerOnly();
            return;
        }
        if (!isConfiguredApiBase(activeApiBase)) {
            setStatus("请先填写后台地址");
            return;
        }
        String url = apiUrl("/api/index.php?module=game_servers&action=public") + (appOnlyLogin ? "&public_account=1" : "");
        Request request = new Request.Builder().url(url).get().build();
        http.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                runOnUiThread(() -> {
                    servers.clear();
                    servers.add(new GameServer("默认服务器", defaultServerHost(), defaultServerPort()));
                    refreshAllRooms();
                });
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    JSONObject json = new JSONObject(response.body() == null ? "" : response.body().string());
                    JSONArray list = json.optJSONObject("data") == null ? null : json.optJSONObject("data").optJSONArray("list");
                    ArrayList<GameServer> next = new ArrayList<>();
                    if (list != null) {
                        for (int i = 0; i < list.length(); i++) {
                            JSONObject row = list.optJSONObject(i);
                            if (row == null) continue;
                            String host = row.optString("host", "").trim();
                            int port = row.optInt("port", 8888);
                            String name = row.optString("name", "").trim();
                            if (host.length() > 0) next.add(new GameServer(name.length() > 0 ? name : ("服务器" + (i + 1)), host, port));
                        }
                    }
                    if (next.isEmpty()) next.add(new GameServer("默认服务器", defaultServerHost(), defaultServerPort()));
                    runOnUiThread(() -> {
                        servers.clear();
                        servers.addAll(next);
                        refreshAllRooms();
                    });
                } catch (Exception e) {
                    runOnUiThread(() -> {
                        loadBundledServerOnly();
                        setStatus("后台服务器列表解析失败，已使用内置 WebSocket");
                    });
                } finally {
                    response.close();
                }
            }
        });
    }

    private int pendingRoomServerCount = 0;

    private void loadBundledServerOnly() {
        servers.clear();
        servers.add(new GameServer("默认服务器", defaultServerHost(), defaultServerPort()));
        refreshAllRooms();
    }

    private void refreshAllRooms() {
        closeRoomSocket();
        rooms.clear();
        roomServerAddresses.clear();
        loadingRooms = true;
        if (roomSelectButton != null) roomSelectButton.setText("正在获取房间号");
        if (roomListScroll != null) {
            roomListScroll.setVisibility(View.VISIBLE);
            if (roomArrowButton != null) roomArrowButton.setText("^");
        }
        renderRooms(new ArrayList<>());
        if (servers.isEmpty()) {
            setStatus("暂无服务器，正在读取后台服务器");
            loadPublicServers();
            return;
        }
        pendingRoomServerCount = servers.size();
        int token = ++roomRefreshToken;
        setStatus("正在获取房间数据");
        for (GameServer server : servers) connectRoomListOnce(server, token);
    }

    private void connectRoomListOnce(GameServer server, int token) {
        final boolean[] finished = new boolean[]{false};
        final WebSocket[] socketRef = new WebSocket[1];
        Runnable timeout = () -> {
            if (finished[0] || token != roomRefreshToken) return;
            finished[0] = true;
            if (socketRef[0] != null) socketRef[0].close(1000, "timeout");
            finishRoomServerFetch(token, server, new ArrayList<>());
        };
        WebSocket ws = http.newWebSocket(new Request.Builder().url(buildWsUrl(server.address())).build(), new WebSocketListener() {
            @Override
            public void onOpen(WebSocket ws, Response response) {
                ws.send("getHome");
            }

            @Override
            public void onMessage(WebSocket ws, String text) {
                if (finished[0]) return;
                if (text == null || !text.contains("homeData##")) return;
                ArrayList<String> nextRooms = parseHomeRooms(text);
                finished[0] = true;
                runOnUiThread(() -> finishRoomServerFetch(token, server, nextRooms));
                ws.close(1000, "ok");
            }

            @Override
            public void onFailure(WebSocket ws, Throwable t, Response response) {
                if (!finished[0]) {
                    finished[0] = true;
                    runOnUiThread(() -> finishRoomServerFetch(token, server, new ArrayList<>()));
                }
            }

            @Override
            public void onClosed(WebSocket ws, int code, String reason) {
                if (!finished[0]) {
                    finished[0] = true;
                    runOnUiThread(() -> finishRoomServerFetch(token, server, new ArrayList<>()));
                }
            }
        });
        socketRef[0] = ws;
        roomSocket = ws;
        roomListSockets.add(ws);
        roomRefreshHandler.postDelayed(timeout, 6000);
    }

    private ArrayList<String> parseHomeRooms(String text) {
        ArrayList<String> nextRooms = new ArrayList<>();
        if (text == null || !text.contains("homeData##")) return nextRooms;
        String payload = text.substring(text.indexOf("homeData##") + "homeData##".length());
        String[] split = payload.split(",");
        for (String item : split) {
            String room = item == null ? "" : item.trim();
            if (isValidRoomId(room) && !nextRooms.contains(room)) nextRooms.add(room);
        }
        return nextRooms;
    }

    private boolean isValidRoomId(String room) {
        return room != null && room.trim().length() > 0 && room.trim().length() <= 80;
    }

    private void finishRoomServerFetch(int token, GameServer server, ArrayList<String> nextRooms) {
        if (token != roomRefreshToken) return;
        for (String room : nextRooms) {
            if (!rooms.contains(room)) rooms.add(room);
            roomServerAddresses.put(room, server.address());
        }
        renderRooms(new ArrayList<>(rooms));
        if (pendingRoomServerCount > 0) pendingRoomServerCount--;
        if (pendingRoomServerCount <= 0) {
            loadingRooms = false;
            renderRooms(new ArrayList<>(rooms));
            setStatus("房间数据已更新");
        }
    }

    private void renderRooms(List<String> nextRooms) {
        rooms.clear();
        rooms.addAll(nextRooms);
        Collections.sort(rooms);
        if (!loadingRooms && selectedRoomLabel.length() > 0 && !rooms.contains(selectedRoomLabel)) {
            selectedRoomLabel = "";
            prefs.edit().remove("selected_room").apply();
        }
        ArrayList<String> labels = new ArrayList<>();
        if (rooms.isEmpty()) labels.add(loadingRooms ? "正在获取房间号" : "暂无房间号");
        else labels.addAll(rooms);
        renderRoomList(labels);
        if (roomSelectButton != null) roomSelectButton.setText(roomSelectLabel());
        if (roomCountBadge != null) roomCountBadge.setText(rooms.size() + "间");
        updateConnectRoomButton();
    }

    private void renderRoomList(List<String> labels) {
        if (roomListContent == null) return;
        roomListContent.removeAllViews();
        for (String labelText : labels) {
            Button row = makeSmallButton(labelText, () -> selectRoomLabel(labelText));
            row.setGravity(Gravity.CENTER_VERTICAL);
            row.setPadding(dp(10), 0, dp(10), 0);
            row.setTextSize(13);
            row.setBackground(makeStrokeBox(0x22000000, 0x33888888, dp(8)));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(38));
            lp.bottomMargin = dp(4);
            roomListContent.addView(row, lp);
        }
        updateRoomListHeight(labels.size());
    }

    private void updateRoomListHeight(int itemCount) {
        if (roomListScroll == null) return;
        int rows = Math.max(1, Math.min(5, itemCount));
        LinearLayout.LayoutParams lp = (LinearLayout.LayoutParams) roomListScroll.getLayoutParams();
        if (lp == null) lp = lpTop(92, 6);
        lp.height = dp(10 + rows * 42);
        roomListScroll.setLayoutParams(lp);
    }

    private void toggleRoomList() {
        if (roomListScroll == null) return;
        boolean show = roomListScroll.getVisibility() != View.VISIBLE;
        roomListScroll.setVisibility(show ? View.VISIBLE : View.GONE);
        if (roomArrowButton != null) roomArrowButton.setText(show ? "^" : "v");
    }

    private void selectRoomLabel(String labelText) {
        String clean = cleanRoomLabel(labelText);
        if (rooms.contains(clean)) {
            selectedRoomLabel = clean;
            prefs.edit().putString("selected_room", selectedRoomLabel).apply();
            if (roomSelectButton != null) roomSelectButton.setText(roomSelectLabel());
            updateConnectRoomButton();
        }
        if (roomListScroll != null) roomListScroll.setVisibility(View.GONE);
        if (roomArrowButton != null) roomArrowButton.setText("v");
    }

    private String roomSelectLabel() {
        if (loadingRooms) return "正在获取房间号";
        String selected = selectedRoom();
        return selected.length() == 0 ? "暂无房间号" : selected;
    }

    private void closeRoomSocket() {
        roomRefreshToken++;
        roomRefreshHandler.removeCallbacksAndMessages(null);
        for (WebSocket ws : new ArrayList<>(roomListSockets)) {
            if (ws != null) ws.close(1000, "close");
        }
        roomListSockets.clear();
        if (roomSocket != null) {
            roomSocket.close(1000, "close");
            roomSocket = null;
        }
    }

    private String selectedRoom() {
        return selectedRoomLabel == null ? "" : selectedRoomLabel;
    }

    private String cleanRoomLabel(String label) {
        return label == null ? "" : label.trim();
    }

    private String selectedRoomServerAddress() {
        if (selectedRoomLabel != null && selectedRoomLabel.length() > 0) {
            String mapped = roomServerAddresses.get(selectedRoomLabel);
            if (mapped != null && mapped.length() > 0) return mapped;
        }
        return servers.isEmpty() ? defaultServerHost() + ":" + defaultServerPort() : servers.get(0).address();
    }

    private String defaultServerHost() {
        return bundledServerHost.length() > 0 ? bundledServerHost : DEFAULT_SERVER_HOST;
    }

    private int defaultServerPort() {
        return bundledServerPort > 0 ? bundledServerPort : DEFAULT_SERVER_PORT;
    }

    private String buildWsUrl(String value) {
        return "ws://" + normalizeHostPort(value) + "/ws";
    }

    private void login() {
        JSONObject body = new JSONObject();
        try {
            body.put("username", username());
            body.put("password", passwordInput.getText().toString());
            body.put("client", "app");
        } catch (Exception ignored) {}
        post("user_login", body, true);
    }

    private void register() {
        JSONObject body = new JSONObject();
        try {
            body.put("username", registerUsername());
            body.put("password", registerPasswordInput == null ? "" : registerPasswordInput.getText().toString());
            body.put("card_code", cardInput.getText().toString().trim());
            body.put("security_code", securityInput.getText().toString().trim());
        } catch (Exception ignored) {}
        post("register", body, true);
    }

    private void activateCard() {
        JSONObject body = new JSONObject();
        try {
            body.put("username", activateUsername());
            body.put("card_code", activateCardInput == null ? "" : activateCardInput.getText().toString().trim());
        } catch (Exception ignored) {}
        post("activate_card", body, false);
    }

    private void claimTrialCard() {
        if (trialUrl != null && trialUrl.trim().length() > 0) {
            openUrl(trialUrl);
            return;
        }
        if (!applyApiBaseFromInput()) return;
        setStatus("正在领取试用卡...");
        JSONObject body = new JSONObject();
        try {
            body.put("action", "claim");
            body.put("device_id", Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID));
            body.put("device_name", Build.MANUFACTURER + " " + Build.MODEL);
        } catch (Exception ignored) {}
        Request request = new Request.Builder()
                .url(apiUrl("/api/index.php?module=trial_card"))
                .post(RequestBody.create(body.toString(), JSON))
                .build();
        http.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                runOnUiThread(() -> setStatus("领卡失败：" + e.getMessage()));
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    JSONObject json = new JSONObject(response.body() == null ? "" : response.body().string());
                    int code = json.optInt("code", -1);
                    String msg = json.optString("msg", code == 0 ? "领取成功" : "领取失败");
                    JSONObject data = json.optJSONObject("data");
                    String codeText = data == null ? "" : data.optString("card_code", "");
                    runOnUiThread(() -> {
                        if (code == 0 && codeText.length() > 0) {
                            if (cardInput != null) cardInput.setText(codeText);
                            if (activateCardInput != null) activateCardInput.setText(codeText);
                            setStatus("已领取试用卡：" + codeText);
                        } else setStatus(msg);
                    });
                } catch (Exception e) {
                    runOnUiThread(() -> setStatus("领卡响应解析失败"));
                } finally {
                    response.close();
                }
            }
        });
    }

    private void post(String module, JSONObject body, boolean enterAppOnOk) {
        if (updateBlocked) {
            setStatus("当前版本不可用，请先更新或查看公告");
            return;
        }
        if (!applyApiBaseFromInput()) return;
        setStatus("请求中...");
        Request request = new Request.Builder().url(apiUrl("/api/index.php?module=" + module)).post(RequestBody.create(body.toString(), JSON)).build();
        http.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                runOnUiThread(() -> setStatus("服务器连接失败：" + e.getMessage()));
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    JSONObject json = new JSONObject(response.body() == null ? "" : response.body().string());
                    int code = json.optInt("code", -1);
                    String msg = json.optString("msg", code == 0 ? "ok" : "请求失败");
                    runOnUiThread(() -> {
                        if (code == 0) {
                            JSONObject data = json.optJSONObject("data");
                            if (data != null) {
                                accountCardCode = data.optString("card_code", accountCardCode);
                                accountExpiresAt = data.optString("expires_at", accountExpiresAt);
                                accountCardStatus = data.optString("card_status", accountCardStatus);
                                appOnlyLogin = data.optBoolean("app_only", false);
                            }
                            prefs.edit()
                                    .putString("username", loginUsernameForModule(module))
                                    .putString("card_code", accountCardCode)
                                    .putString("expires_at", accountExpiresAt)
                                    .putString("card_status", accountCardStatus)
                                    .putBoolean("app_only_login", appOnlyLogin)
                                    .putBoolean("logged_in", enterAppOnOk || loggedIn)
                                    .apply();
                            if (enterAppOnOk) {
                                loggedIn = true;
                                showRadarPage();
                            } else setStatus(msg);
                        } else setStatus(msg);
                    });
                } catch (Exception e) {
                    runOnUiThread(() -> setStatus("响应解析失败"));
                } finally {
                    response.close();
                }
            }
        });
    }

    private String loginUsernameForModule(String module) {
        if ("register".equals(module)) return registerUsername();
        if ("activate_card".equals(module)) return activateUsername();
        return username();
    }

    private void enterWithoutLogin(String message) {
        if (updateBlocked) {
            setStatus("当前版本不可用，请先更新或查看公告");
            return;
        }
        loggedIn = true;
        appOnlyLogin = false;
        accountCardCode = "免登录";
        accountExpiresAt = "后台控制";
        accountCardStatus = "免登录进入";
        if (prefs.getString("username", "").trim().length() == 0) {
            prefs.edit().putString("username", "免登录用户").apply();
        }
        showRadarPage();
        setStatus(message);
    }

    private void logout() {
        loggedIn = false;
        appOnlyLogin = false;
        stopService(new Intent(this, NativeOverlayService.class));
        prefs.edit().putBoolean("logged_in", false).putBoolean("app_only_login", false).apply();
        showLoginPage();
    }

    private void requestScreenCapture() {
        MediaProjectionManager mpm = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
        if (mpm != null) {
            startActivityForResult(mpm.createScreenCaptureIntent(), REQUEST_MEDIA_PROJECTION);
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQUEST_MEDIA_PROJECTION && resultCode == RESULT_OK && data != null) {
            NativeOverlayService.sProjectionResultCode = resultCode;
            NativeOverlayService.sProjectionData = data;
            setStatus("截屏权限已授权，可使用一键适配");
        } else if (requestCode == REQUEST_MEDIA_PROJECTION) {
            setStatus("截屏权限被拒绝");
        }
    }

    private void startOverlay() {
        if (!loggedIn) {
            setStatus("请先登录");
            return;
        }
        if (updateBlocked) {
            setStatus("当前版本不可用，请先更新或查看公告");
            return;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !Settings.canDrawOverlays(this)) {
            pendingOverlayStart = true;
            setStatus("请先授权悬浮窗，授权返回后会自动连接房间");
            requestOverlayPermission();
            return;
        }
        String server = selectedRoomServerAddress();
        String room = selectedRoom();
        if (server.length() == 0) {
            setStatus("正在读取后台服务器，请稍后再试");
            loadPublicServers();
            return;
        }
        if (room.length() == 0) {
            setStatus("请先从房间列表选择房间号");
            return;
        }
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.putExtra("site", server);
        intent.putExtra("room", room);
        int fps = selectedFps();
        intent.putExtra("fps", fps);
        intent.putExtra("minion_fix_steps", minionLaneRotationSteps);
        intent.putStringArrayListExtra("rooms", new ArrayList<>(rooms));
        ArrayList<String> mappedServers = new ArrayList<>();
        for (String r : rooms) mappedServers.add(roomServerAddresses.get(r));
        intent.putStringArrayListExtra("room_servers", mappedServers);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) startForegroundService(intent); else startService(intent);
        prefs.edit()
                .putString("selected_room", room)
                .putInt("radar_fps", fps)
                .putBoolean("adjust_panel_visible", true)
                .apply();
        setOverlayAdjustMode(true);
        setStatus("已连接房间 " + room + "，悬浮窗已弹出");
    }

    private void cycleMinionLaneFix() {
        minionLaneRotationSteps = (minionLaneRotationSteps + 1) % 4;
        prefs.edit().putInt("minion_lane_rotation_steps", minionLaneRotationSteps).apply();
        if (minionFixButton != null) minionFixButton.setText(minionFixText());
        applyMinionFixButtonStyle();
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_SET_MINION_FIX);
        intent.putExtra("steps", minionLaneRotationSteps);
        startService(intent);
        setStatus("兵线方向已旋转：" + minionFixLabel());
    }

    private String minionFixText() {
        return "修复兵线 " + minionFixLabel();
    }

    private String minionFixLabel() {
        String[] labels = new String[]{"0°", "90°", "180°", "270°"};
        return labels[minionLaneRotationSteps % 4];
    }

    private void applyMinionFixButtonStyle() {
        if (minionFixButton == null) return;
        if (minionLaneRotationSteps == 0) {
            minionFixButton.setTextColor(secondaryText);
            minionFixButton.setBackground(makeStrokeBox(inputBg, borderColor, dp(10)));
            return;
        }
        minionFixButton.setTextColor(Color.WHITE);
        if (themeIndex == 3) {
            minionFixButton.setBackground(makeGradient(0xff059669, 0xff22c55e, dp(10)));
        } else if (themeIndex == 0) {
            minionFixButton.setBackground(makeGradient(0xff0284c7, 0xff2563eb, dp(10)));
        } else if (themeIndex == 2) {
            minionFixButton.setBackground(makeGradient(0xff0ea5e9, 0xff7c3aed, dp(10)));
        } else {
            minionFixButton.setBackground(makeGradient(0xff2563eb, 0xff22d3ee, dp(10)));
        }
    }

    private int selectedFps() {
        return fpsSpinner == null ? prefs.getInt("radar_fps", DEFAULT_FPS) : fpsFromIndex(fpsSpinner.getSelectedItemPosition());
    }

    private int fpsFromIndex(int index) {
        return index >= 0 && index < FPS_VALUES.length ? FPS_VALUES[index] : DEFAULT_FPS;
    }

    private int fpsIndexFor(int fps) {
        for (int i = 0; i < FPS_VALUES.length; i++) {
            if (FPS_VALUES[i] == fps) return i;
        }
        return 1;
    }

    private void saveSelectedFps(int fps) {
        prefs.edit().putInt("radar_fps", fps).apply();
    }

    private String connectRoomButtonText() {
        String room = selectedRoom();
        if (room.length() > 0) return "连接房间 " + room;
        return loadingRooms ? "正在获取房间" : "请选择房间";
    }

    private void updateConnectRoomButton() {
        if (connectRoomButton == null) return;
        boolean ready = selectedRoom().length() > 0;
        connectRoomButton.setText(connectRoomButtonText());
        connectRoomButton.setEnabled(ready);
        connectRoomButton.setAlpha(ready ? 1f : 0.52f);
    }

    private String normalizeHostPort(String value) {
        String host = value == null ? "" : value.trim();
        host = host.replace("https://", "").replace("http://", "").replace("ws://", "").replace("wss://", "");
        int slash = host.indexOf('/');
        if (slash >= 0) host = host.substring(0, slash);
        if (!host.contains(":")) host += ":" + defaultServerPort();
        return host;
    }

    private void resetOverlay() {
        heroX = heroY = minionX = minionY = monsterX = monsterY = 0;
        heroIconScale = 1f;
        prefs.edit().putFloat("hero_icon_scale", heroIconScale).apply();
        Intent intent = new Intent(this, NativeOverlayService.class);
        intent.setAction(NativeOverlayService.ACTION_RESET);
        startService(intent);
    }

    private void startOnlineHeartbeat() {
        stopOnlineHeartbeat();
        if (isFrontendOnlyMode()) return;
        heartbeatRunnable = new Runnable() {
            @Override
            public void run() {
                sendOnlineHeartbeat();
                heartbeatHandler.postDelayed(this, 30000);
            }
        };
        heartbeatRunnable.run();
    }

    private void stopOnlineHeartbeat() {
        if (heartbeatRunnable != null) {
            heartbeatHandler.removeCallbacks(heartbeatRunnable);
            heartbeatRunnable = null;
        }
    }

    private void sendOnlineHeartbeat() {
        if (isFrontendOnlyMode()) return;
        if (!isConfiguredApiBase(activeApiBase)) return;
        JSONObject body = new JSONObject();
        try {
            body.put("client", "app");
            body.put("username", prefs.getString("username", username()));
            body.put("device_id", Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID));
        } catch (Exception ignored) {}
        Request request = new Request.Builder()
                .url(apiUrl("/api/index.php?module=client_online_heartbeat"))
                .post(RequestBody.create(body.toString(), JSON))
                .build();
        http.newCall(request).enqueue(new Callback() {
            @Override public void onFailure(Call call, java.io.IOException e) {}
            @Override public void onResponse(Call call, Response response) { response.close(); }
        });
    }

    private void loadAppLinks() {
        if (isFrontendOnlyMode()) return;
        if (!isConfiguredApiBase(activeApiBase)) return;
        Request request = new Request.Builder().url(apiUrl("/api/index.php?module=app_settings&action=public")).get().build();
        http.newCall(request).enqueue(new Callback() {
            @Override public void onFailure(Call call, java.io.IOException e) {}
            @Override
            public void onResponse(Call call, Response response) {
                try {
                    JSONObject json = new JSONObject(response.body() == null ? "" : response.body().string());
                    JSONObject data = json.optJSONObject("data");
                    if (data != null) {
                        trialUrl = preferNonEmpty(data.optString("trial_url", ""), trialUrl);
                        buyUrl = preferNonEmpty(data.optString("buy_card_url", ""), buyUrl);
                        downloadUrl = preferNonEmpty(data.optString("download_url", ""), downloadUrl);
                        groupUrl = preferNonEmpty(data.optString("group_url", ""), groupUrl.length() > 0 ? groupUrl : downloadUrl);
                    }
                } catch (Exception ignored) {
                } finally {
                    response.close();
                }
            }
        });
    }

    private void checkRemoteConfig(boolean manual, boolean noticeOnly) {
        if (!isConfiguredApiBase(activeApiBase)) {
            if (booting) {
                booting = false;
                if (shouldAutoEnterWithoutBackend()) enterWithoutLogin("未配置后台，已免登录进入主页");
                else showLoginPage();
            }
            setStatus("请先填写后台地址");
            return;
        }
        Request request = new Request.Builder().url(apiUrl("/api/index.php?module=app_remote_config&action=public")).get().build();
        http.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                runOnUiThread(() -> {
                    if (manual) setStatus("远程配置请求失败");
                    if (booting) {
                        booting = false;
                        if (shouldAutoEnterWithoutBackend()) enterWithoutLogin("未检测到后台，已免登录进入主页");
                        else showLoginPage();
                    }
                });
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    JSONObject json = new JSONObject(response.body() == null ? "" : response.body().string());
                    JSONObject data = json.optJSONObject("data");
                    if (data == null) {
                        runOnUiThread(() -> {
                            if (manual) setStatus("后台已连接，需要登录");
                            if (booting) {
                                booting = false;
                                if (loggedIn) showRadarPage();
                                else {
                                    showLoginPage();
                                    showAppLoginDialogIfNeeded();
                                }
                            }
                        });
                        return;
                    }
                    JSONObject links = data.optJSONObject("links");
                    appLoginRequired = data.optBoolean("login_required", true);
                    if (links != null) {
                        trialUrl = preferNonEmpty(links.optString("trial_url", ""), trialUrl);
                        buyUrl = preferNonEmpty(links.optString("buy_card_url", ""), buyUrl);
                        downloadUrl = preferNonEmpty(links.optString("download_url", ""), downloadUrl);
                        groupUrl = preferNonEmpty(links.optString("group_url", ""), groupUrl.length() > 0 ? groupUrl : downloadUrl);
                    }
                    JSONObject appLogin = data.optJSONObject("app_login");
                    if (appLogin != null) {
                        appLoginEnabled = appLogin.optBoolean("enabled", false);
                        appLoginUsername = appLogin.optString("username", "");
                        appLoginPassword = appLogin.optString("password", "");
                        appLoginTitle = appLogin.optString("title", "APP 公共账号");
                        appLoginMessage = appLogin.optString("message", "不会注册的用户可以使用下面账号登录 APP。");
                    }
                    runOnUiThread(() -> handleRemoteConfig(data.optJSONObject("update"), data.optJSONObject("popup"), manual, noticeOnly));
                } catch (Exception e) {
                    runOnUiThread(() -> {
                        if (manual) setStatus("远程配置解析失败");
                        if (booting) {
                            booting = false;
                            if (shouldAutoEnterWithoutBackend()) enterWithoutLogin("未检测到有效后台，已免登录进入主页");
                            else showLoginPage();
                        }
                    });
                } finally {
                    response.close();
                }
            }
        });
    }

    private void handleRemoteConfig(JSONObject update, JSONObject popup, boolean manual, boolean noticeOnly) {
        if (!noticeOnly && update != null) {
            int latestCode = update.optInt("version_code", CURRENT_VERSION_CODE);
            String apkUrl = update.optString("apk_url", "");
            if (latestCode > CURRENT_VERSION_CODE) {
                updateBlocked = true;
                booting = false;
                showUpdateBlockedPage(update, popup);
                return;
            } else if (manual) {
                if (latestCode < CURRENT_VERSION_CODE) setStatus("当前版本高于后台配置，已允许进入");
                else setStatus("已是最新版本");
            }
        }
        updateBlocked = false;
        if (booting) {
            booting = false;
            if (!appLoginRequired) enterWithoutLogin("后台已关闭 APP 登录，直接进入主页");
            else if (loggedIn) showRadarPage();
            else {
                showLoginPage();
                showAppLoginDialogIfNeeded();
            }
        }
        if (popup != null && popup.optBoolean("enabled", false)) {
            String url = popup.optString("url", "");
            AlertDialog.Builder builder = new AlertDialog.Builder(this)
                    .setTitle(popup.optString("title", "公告"))
                    .setMessage(popup.optString("message", "暂无内容"))
                    .setNegativeButton("知道了", null);
            if (url.length() > 0) builder.setPositiveButton("打开", (d, w) -> openUrl(url));
            builder.show();
        } else if (noticeOnly) setStatus("暂无公告");
    }

    private void showAppLoginDialogIfNeeded() {
        if (loggedIn || appLoginDialogShown || !appLoginEnabled) return;
        if (appLoginUsername.length() == 0 || appLoginPassword.length() == 0) return;
        appLoginDialogShown = true;
        String msg = (appLoginMessage.length() > 0 ? appLoginMessage + "\n\n" : "")
                + "账号：" + appLoginUsername + "\n密码：" + appLoginPassword;
        new AlertDialog.Builder(this)
                .setTitle(appLoginTitle.length() > 0 ? appLoginTitle : "APP 公共账号")
                .setMessage(msg)
                .setPositiveButton("填入账号", (d, w) -> {
                    if (usernameInput != null) usernameInput.setText(appLoginUsername);
                    if (passwordInput != null) passwordInput.setText(appLoginPassword);
                    setStatus("已填入 APP 公共账号");
                })
                .setNegativeButton("知道了", null)
                .show();
    }

    private void showUpdateBlockedPage(JSONObject update, JSONObject popup) {
        stopOnlineHeartbeat();
        LinearLayout root = createRoot(appTitle(), "当前版本需要更新");
        LinearLayout card = section(root);
        String title = update == null ? "发现版本变更" : update.optString("title", "发现版本变更");
        String message = update == null ? "当前版本与后台版本号不一致，请更新后使用。" : update.optString("message", "当前版本与后台版本号不一致，请更新后使用。");
        card.addView(label(title), new LinearLayout.LayoutParams(-1, -2));
        card.addView(label(message), lpTop(-2, 10));
        ArrayList<String> apkUrls = collectUpdateUrls(update);
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.addView(makeSmallButton("立即更新", () -> downloadAndInstallApk(apkUrls)), new LinearLayout.LayoutParams(0, dp(42), 1));
        card.addView(row, lpTop(-2, 14));
        if (popup != null && popup.optBoolean("enabled", false)) {
            card.addView(makeSmallButton("查看公告", () -> handleRemoteConfig(null, popup, true, true)), lpTop(42, 10));
        }
        statusText = status(root);
        setStatus("版本不一致，已阻止进入 APP");
    }

    private LinearLayout createPlainRoot(String titleText) {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(18), dp(34), dp(18), dp(20));
        root.setBackground(makeGradient(bgStart, bgEnd, 0));
        scroll.addView(root, new ScrollView.LayoutParams(-1, -2));

        LinearLayout titleRow = new LinearLayout(this);
        titleRow.setOrientation(LinearLayout.HORIZONTAL);
        titleRow.setGravity(Gravity.CENTER_VERTICAL);
        TextView title = new TextView(this);
        title.setText(titleText);
        title.setTextColor(primaryText);
        title.setTextSize(30);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        titleRow.addView(title, new LinearLayout.LayoutParams(0, -2, 1));
        Button menu = makePlainMenuButton(">");
        menu.setOnClickListener(v -> showMainMenu());
        titleRow.addView(menu, new LinearLayout.LayoutParams(dp(54), dp(48)));
        root.addView(titleRow, new LinearLayout.LayoutParams(-1, -2));
        root.addView(versionLabel(), new LinearLayout.LayoutParams(-1, -2));

        setContentView(scroll);
        return root;
    }

    private void showMainMenu() {
        String[] items = new String[]{"刷新房间", "账号信息", "授权悬浮窗", "关闭悬浮窗", "复位绘制", "检查更新", "查看公告", "主题", "退出"};
        new AlertDialog.Builder(this)
                .setTitle("菜单")
                .setItems(items, (dialog, which) -> {
                    if (which == 0) refreshAllRooms();
                    else if (which == 1) showAccountDialog();
                    else if (which == 2) requestOverlayPermission();
                    else if (which == 3) stopService(new Intent(this, NativeOverlayService.class));
                    else if (which == 4) resetOverlay();
                    else if (which == 5) checkRemoteConfig(true, false);
                    else if (which == 6) checkRemoteConfig(true, true);
                    else if (which == 7) showThemeDialog();
                    else if (which == 8) logout();
                })
                .show();
    }

    private void showAccountDialog() {
        String msg = "账号：" + prefs.getString("username", "-")
                + "\n卡密：" + (accountCardCode.length() > 0 ? accountCardCode : "-")
                + "\n到期时间：" + (accountExpiresAt.length() > 0 ? accountExpiresAt : "-")
                + "\n状态：" + (accountCardStatus.length() > 0 ? accountCardStatus : "-");
        new AlertDialog.Builder(this).setTitle("账号信息").setMessage(msg).setPositiveButton("知道了", null).show();
    }

    private LinearLayout createRoot(String titleText, String subtitle) {
        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(18), dp(22), dp(18), dp(20));
        root.setGravity(Gravity.CENTER_HORIZONTAL);
        root.setBackground(makeGradient(bgStart, bgEnd, 0));
        scroll.addView(root, new ScrollView.LayoutParams(-1, -2));

        ImageView brand = new ImageView(this);
        brand.setImageResource(brandIconResId());
        brand.setAdjustViewBounds(true);
        root.addView(brand, new LinearLayout.LayoutParams(dp(86), dp(86)));

        TextView title = new TextView(this);
        title.setText(titleText);
        title.setTextColor(primaryText);
        title.setTextSize(30);
        title.setTypeface(Typeface.DEFAULT_BOLD);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        TextView tip = new TextView(this);
        tip.setText(subtitle);
        tip.setTextColor(secondaryText);
        tip.setTextSize(15);
        tip.setGravity(Gravity.CENTER);
        tip.setPadding(0, dp(8), 0, dp(6));
        root.addView(tip, new LinearLayout.LayoutParams(-1, -2));
        root.addView(versionLabel(), new LinearLayout.LayoutParams(-1, -2));
        setContentView(scroll);
        return root;
    }

    private TextView versionLabel() {
        TextView version = new TextView(this);
        version.setText("当前版本：" + CURRENT_VERSION_NAME + " (" + CURRENT_VERSION_CODE + ")");
        version.setTextColor(secondaryText);
        version.setTextSize(12);
        version.setGravity(Gravity.CENTER);
        version.setPadding(0, dp(2), 0, dp(4));
        return version;
    }

    private LinearLayout section(LinearLayout root) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(16), dp(16), dp(16), dp(16));
        card.setBackground(makeStrokeBox(cardBg, borderColor, dp(14)));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.topMargin = dp(12);
        root.addView(card, lp);
        return card;
    }

    private TextView label(String text) {
        TextView label = new TextView(this);
        label.setText(text);
        label.setTextColor(secondaryText);
        label.setTextSize(14);
        label.setGravity(Gravity.CENTER_VERTICAL);
        return label;
    }

    private TextView status(LinearLayout root) {
        TextView text = new TextView(this);
        text.setTextColor(secondaryText);
        text.setTextSize(13);
        text.setGravity(Gravity.CENTER);
        text.setPadding(0, dp(12), 0, 0);
        root.addView(text, new LinearLayout.LayoutParams(-1, -2));
        return text;
    }

    private interface ToggleAction {
        void onChanged(boolean on);
    }

    private LinearLayout switchRow(String title, String subtitle, boolean checked, ToggleAction action) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout texts = new LinearLayout(this);
        texts.setOrientation(LinearLayout.VERTICAL);
        TextView titleView = label(title);
        titleView.setTextColor(primaryText);
        titleView.setTextSize(19);
        TextView subView = label(subtitle);
        subView.setTextSize(13);
        subView.setPadding(0, dp(8), 0, 0);
        texts.addView(titleView, new LinearLayout.LayoutParams(-1, -2));
        texts.addView(subView, new LinearLayout.LayoutParams(-1, -2));

        Switch sw = new Switch(this);
        sw.setChecked(checked);
        sw.setOnCheckedChangeListener((buttonView, isChecked) -> action.onChanged(isChecked));
        row.addView(texts, new LinearLayout.LayoutParams(0, -2, 1));
        row.addView(sw, new LinearLayout.LayoutParams(dp(82), dp(54)));
        return row;
    }

    private EditText makeInput(String hint) {
        EditText input = new EditText(this);
        input.setHint(hint);
        input.setHintTextColor(hintText);
        input.setTextColor(inputText);
        input.setTextSize(15);
        input.setSingleLine(true);
        input.setPadding(dp(12), 0, dp(12), 0);
        input.setBackground(makeStrokeBox(inputBg, borderColor, dp(10)));
        return input;
    }

    private Button makeSmallButton(String text, Runnable action) {
        Button button = makeButton(text);
        button.setTextSize(13);
        button.setOnClickListener(v -> action.run());
        return button;
    }

    private Button makePlainMenuButton(String text) {
        Button button = new Button(this);
        button.setText(text);
        button.setTextColor(primaryText);
        button.setTextSize(28);
        button.setTypeface(Typeface.DEFAULT_BOLD);
        button.setAllCaps(false);
        button.setBackgroundColor(Color.TRANSPARENT);
        return button;
    }

    private Button makeButton(String text) {
        Button button = new Button(this);
        button.setText(text);
        button.setTextColor(Color.WHITE);
        button.setTextSize(15);
        button.setAllCaps(false);
        button.setBackground(makeGradient(0xff2563eb, 0xff7c3aed, dp(10)));
        return button;
    }

    private LinearLayout.LayoutParams lpTop(int height, int top) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, height > 0 ? dp(height) : height);
        lp.topMargin = dp(top);
        return lp;
    }

    private GradientDrawable makeGradient(int startColor, int endColor, int radius) {
        GradientDrawable drawable = new GradientDrawable(GradientDrawable.Orientation.TL_BR, new int[]{startColor, endColor});
        drawable.setCornerRadius(radius);
        return drawable;
    }

    private GradientDrawable makeStrokeBox(int color, int strokeColor, int radius) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(color);
        drawable.setCornerRadius(radius);
        drawable.setStroke(dp(1), strokeColor);
        return drawable;
    }

    private void applyThemeColors() {
        if (themeIndex == 0) {
            bgStart = 0xfff8fafc; bgEnd = 0xffdbeafe; cardBg = 0xffffffff; borderColor = 0xff93c5fd;
            primaryText = 0xff0f172a; secondaryText = 0xff334155; inputBg = 0xffffffff; inputText = 0xff0f172a; hintText = 0xff64748b;
        } else if (themeIndex == 2) {
            bgStart = 0xffe0f2fe; bgEnd = 0xffeff6ff; cardBg = 0xeeffffff; borderColor = 0xff60a5fa;
            primaryText = 0xff0f172a; secondaryText = 0xff1e3a8a; inputBg = 0xffffffff; inputText = 0xff0f172a; hintText = 0xff64748b;
        } else if (themeIndex == 3) {
            bgStart = 0xff052e2b; bgEnd = 0xff064e3b; cardBg = 0xee0f172a; borderColor = 0xff34d399;
            primaryText = 0xffffffff; secondaryText = 0xffbbf7d0; inputBg = 0xff082f2c; inputText = 0xffffffff; hintText = 0xff86efac;
        } else {
            bgStart = 0xff111827; bgEnd = 0xff1e293b; cardBg = 0xee172033; borderColor = 0xff60a5fa;
            primaryText = 0xffffffff; secondaryText = 0xffdbeafe; inputBg = 0xff0b1220; inputText = 0xffffffff; hintText = 0xff94a3b8;
        }
    }

    private void applySecureMode() {
        if (secureMode) getWindow().setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE);
        else getWindow().clearFlags(WindowManager.LayoutParams.FLAG_SECURE);
    }

    private void toggleSecureMode() {
        secureMode = !secureMode;
        prefs.edit().putBoolean("secure_mode", secureMode).apply();
        applySecureMode();
        showRadarPage();
    }

    private void requestOverlayPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !Settings.canDrawOverlays(this)) {
            Intent intent = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION);
            intent.setData(Uri.parse("package:" + getPackageName()));
            startActivity(intent);
        }
    }

    private ArrayList<String> collectUpdateUrls(JSONObject update) {
        ArrayList<String> urls = new ArrayList<>();
        if (update == null) return urls;
        addUpdateUrl(urls, update.optString("apk_url", ""));
        JSONArray mirrors = update.optJSONArray("apk_urls");
        if (mirrors != null) {
            for (int i = 0; i < mirrors.length(); i++) {
                addUpdateUrl(urls, mirrors.optString(i, ""));
            }
        }
        return urls;
    }

    private void addUpdateUrl(ArrayList<String> urls, String url) {
        String normalized = resolveWebUrl(url);
        if (normalized.length() > 0 && !urls.contains(normalized)) urls.add(normalized);
    }

    private void downloadAndInstallApk(ArrayList<String> urls) {
        if (urls == null || urls.isEmpty()) {
            setStatus("后台还没有配置 APK 更新地址");
            return;
        }
        downloadAndInstallApk(urls, 0);
    }

    private void downloadAndInstallApk(ArrayList<String> urls, int index) {
        if (urls == null || index >= urls.size()) {
            setStatus("所有更新线路都下载失败");
            return;
        }
        final String updateUrl = urls.get(index);
        if (shouldOpenUpdateInBrowser(updateUrl)) {
            setStatus("已打开浏览器下载更新");
            openUrl(updateUrl);
            return;
        }
        setStatus(index == 0 ? "正在下载更新..." : "正在尝试备用更新线路...");
        Request request = new Request.Builder().url(updateUrl).get().build();
        http.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                runOnUiThread(() -> {
                    if (index + 1 < urls.size()) {
                        downloadAndInstallApk(urls, index + 1);
                        return;
                    }
                    setStatus("下载直链失败，已打开浏览器");
                    openUrl(updateUrl);
                });
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    if (!response.isSuccessful() || response.body() == null) {
                        throw new Exception("HTTP " + response.code());
                    }
                    File dir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
                    if (dir == null) dir = getCacheDir();
                    if (!dir.exists()) dir.mkdirs();
                    File apk = new File(dir, ApkInstallProvider.APK_FILE_NAME);
                    long total = response.body().contentLength();
                    long readTotal = 0;
                    long lastUi = 0;
                    byte[] buffer = new byte[8192];
                    try (InputStream in = response.body().byteStream(); FileOutputStream out = new FileOutputStream(apk)) {
                        int read;
                        while ((read = in.read(buffer)) != -1) {
                            out.write(buffer, 0, read);
                            readTotal += read;
                            long now = System.currentTimeMillis();
                            if (total > 0 && now - lastUi > 350) {
                                int percent = (int) Math.min(100, readTotal * 100 / total);
                                lastUi = now;
                                runOnUiThread(() -> setStatus("正在下载更新 " + percent + "%"));
                            }
                        }
                    }
                    if (!isLikelyApk(apk)) {
                        if (apk.exists()) apk.delete();
                        runOnUiThread(() -> {
                            if (index + 1 < urls.size()) {
                                downloadAndInstallApk(urls, index + 1);
                                return;
                            }
                            setStatus("更新地址不是 APK 直链，已打开浏览器下载");
                            openUrl(updateUrl);
                        });
                        return;
                    }
                    runOnUiThread(() -> installDownloadedApk(apk));
                } catch (Exception e) {
                    runOnUiThread(() -> {
                        if (index + 1 < urls.size()) downloadAndInstallApk(urls, index + 1);
                        else setStatus("更新下载失败: " + e.getMessage());
                    });
                } finally {
                    response.close();
                }
            }
        });
    }

    private void installDownloadedApk(File apk) {
        if (apk == null || !apk.exists() || apk.length() <= 0) {
            setStatus("安装包不存在，请重新下载");
            return;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !getPackageManager().canRequestPackageInstalls()) {
            setStatus("请先允许安装未知应用，然后回来再点立即更新");
            Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES);
            intent.setData(Uri.parse("package:" + getPackageName()));
            startActivity(intent);
            return;
        }
        Uri uri = Uri.parse("content://" + getPackageName() + ".apkprovider/update.apk");
        Intent intent = new Intent(Intent.ACTION_INSTALL_PACKAGE);
        intent.setDataAndType(uri, "application/vnd.android.package-archive");
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        intent.putExtra(Intent.EXTRA_NOT_UNKNOWN_SOURCE, true);
        try {
            startActivity(intent);
            setStatus("安装器已打开，请确认安装");
        } catch (Exception e) {
            Intent fallback = new Intent(Intent.ACTION_VIEW);
            fallback.setDataAndType(uri, "application/vnd.android.package-archive");
            fallback.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            fallback.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            try {
                startActivity(fallback);
                setStatus("安装器已打开，请确认安装");
            } catch (Exception second) {
                setStatus("无法打开安装器: " + second.getMessage());
            }
        }
    }

    private boolean isLikelyApk(File file) {
        if (file == null || !file.exists() || file.length() < 4) return false;
        byte[] header = new byte[4];
        try (FileInputStream in = new FileInputStream(file)) {
            int read = in.read(header);
            return read == 4 && header[0] == 'P' && header[1] == 'K';
        } catch (Exception ignored) {
            return false;
        }
    }

    private boolean shouldOpenUpdateInBrowser(String url) {
        if (url == null) return false;
        String lower = url.trim().toLowerCase();
        return lower.contains("lanzou") || lower.contains("lanzoum") || lower.contains("lanzoux") || !lower.contains(".apk");
    }

    private String resolveWebUrl(String url) {
        if (url == null) return "";
        String raw = url.trim();
        if (raw.length() == 0) return "";
        if (looksLikeExternalHost(raw)) return "https://" + raw;
        if (raw.matches("(?i)^[a-z][a-z0-9+.-]*:.*")) return raw;
        Uri base = Uri.parse(activeApiBase);
        String scheme = base.getScheme();
        String host = base.getHost();
        if (scheme == null || host == null) return raw;
        String origin = scheme + "://" + host + (base.getPort() >= 0 ? ":" + base.getPort() : "");
        return origin + (raw.startsWith("/") ? raw : "/" + raw);
    }

    private boolean looksLikeExternalHost(String raw) {
        if (raw.startsWith("/") || raw.startsWith("#") || raw.startsWith("./") || raw.startsWith("../")) return false;
        String hostPart = raw.split("[/?#]", 2)[0];
        if (hostPart.matches("(?i)^localhost(:\\d+)?$")) return true;
        if (hostPart.matches("^\\d{1,3}(\\.\\d{1,3}){3}(:\\d+)?$")) return true;
        int dot = hostPart.lastIndexOf('.');
        if (dot <= 0 || dot >= hostPart.length() - 1) return false;
        String suffix = hostPart.substring(dot + 1).toLowerCase().replaceFirst(":\\d+$", "");
        return !isRelativeFileExtension(suffix);
    }

    private boolean isRelativeFileExtension(String suffix) {
        switch (suffix) {
            case "html":
            case "htm":
            case "php":
            case "asp":
            case "aspx":
            case "jsp":
            case "json":
            case "xml":
            case "txt":
            case "apk":
            case "zip":
            case "rar":
            case "7z":
            case "js":
            case "css":
            case "png":
            case "jpg":
            case "jpeg":
            case "gif":
            case "webp":
            case "svg":
            case "ico":
                return true;
            default:
                return false;
        }
    }

    private void openUrl(String url) {
        if (url == null || url.trim().length() == 0) {
            setStatus("后台还没有配置这个链接");
            return;
        }
        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(resolveWebUrl(url))));
    }

    private String username() {
        return usernameInput == null ? prefs.getString("username", "") : usernameInput.getText().toString().trim();
    }

    private String registerUsername() {
        return registerUsernameInput == null ? username() : registerUsernameInput.getText().toString().trim();
    }

    private String activateUsername() {
        return activateUsernameInput == null ? username() : activateUsernameInput.getText().toString().trim();
    }

    private void setStatus(String text) {
        if (statusText != null) statusText.setText(text);
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }

    private static class GameServer {
        final String name;
        final String host;
        final int port;

        GameServer(String name, String host, int port) {
            this.name = name;
            this.host = host;
            this.port = port <= 0 ? 8888 : port;
        }

        String address() {
            return host + ":" + port;
        }
    }
}
