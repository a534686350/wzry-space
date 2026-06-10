package com.qy.wzryoverlay;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.res.ColorStateList;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.graphics.PixelFormat;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.hardware.display.DisplayManager;
import android.hardware.display.VirtualDisplay;
import android.media.Image;
import android.media.ImageReader;
import android.media.projection.MediaProjection;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.util.DisplayMetrics;
import android.view.Gravity;
import android.view.MotionEvent;
import android.view.View;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.SeekBar;
import android.widget.Switch;
import android.widget.TextView;
import android.widget.ScrollView;

import java.nio.ByteBuffer;

import java.util.ArrayList;

import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.WebSocket;
import okhttp3.WebSocketListener;

public class NativeOverlayService extends Service {
    public static final String ACTION_ADJUST = "com.qy.wzryoverlay.ADJUST";
    public static final String ACTION_SET_OFFSET = "com.qy.wzryoverlay.SET_OFFSET";
    public static final String ACTION_ZOOM = "com.qy.wzryoverlay.ZOOM";
    public static final String ACTION_RESET = "com.qy.wzryoverlay.RESET";
    public static final String ACTION_SET_MINION_FIX = "com.qy.wzryoverlay.SET_MINION_FIX";
    public static final String ACTION_SET_HERO_SCALE = "com.qy.wzryoverlay.SET_HERO_SCALE";
    public static final String ACTION_SET_MAP_SCALE = "com.qy.wzryoverlay.SET_MAP_SCALE";
    public static final String ACTION_SET_SKILL_SCALE = "com.qy.wzryoverlay.SET_SKILL_SCALE";
    public static final String ACTION_SET_OVERLAY_BOUNDS = "com.qy.wzryoverlay.SET_OVERLAY_BOUNDS";
    public static final String ACTION_SET_ADJUST_MODE = "com.qy.wzryoverlay.SET_ADJUST_MODE";
    public static final String ACTION_SET_SKILL_PANEL = "com.qy.wzryoverlay.SET_SKILL_PANEL";
    public static final String ACTION_AUTO_FIT_CAPTURE = "com.qy.wzryoverlay.AUTO_FIT_CAPTURE";
    private static final int MIN_OVERLAY_DP = 80;
    private static final int MAX_OVERLAY_DP = 520;
    static int sProjectionResultCode;
    static Intent sProjectionData;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final OkHttpClient client = new OkHttpClient();
    private WindowManager windowManager;
    private WindowManager.LayoutParams params;
    private WindowManager.LayoutParams panelParams;
    private WindowManager.LayoutParams toggleParams;
    private RadarView radarView;
    private LinearLayout adjustPanel;
    private LinearLayout panelContent;
    private Button[] mainNavButtons;
    private Button[] tabButtons;
    private Button toggleButton;
    private SharedPreferences prefs;
    private WebSocket webSocket;
    private String server = "";
    private String roomId = "";
    private final ArrayList<String> roomNames = new ArrayList<>();
    private final ArrayList<String> roomServers = new ArrayList<>();
    private TextView roomCountText;
    private boolean running;
    private boolean adjustMode;
    private boolean panelVisible = true;
    private boolean showSkillPanel = true;
    private int frameDelayMs = 100;
    private float heroX;
    private float heroY;
    private float minionX;
    private float minionY;
    private float monsterX;
    private float monsterY;
    private float mapX;
    private float mapY;
    private float skillX;
    private float skillY;
    private float mapScale = 1f;
    private float skillScale = 1f;
    private float skillGap = 0f;
    private float skillAvatarScale = 1f;
    private float heroScale = 0.72f;
    private float minionScale = 1f;
    private float monsterScale = 1f;
    private boolean showMap = true;
    private boolean showHeroes = true;
    private boolean showMinions = true;
    private boolean showMonsters = true;
    private boolean showZeroSkillCd = true;
    private int minionLaneRotationSteps;
    private int overlaySizePx;
    private int activeMainPage = 2;
    private int activeDrawTab = 0;
    private Button panelRoomButton;

    private final Runnable pollTask = new Runnable() {
        @Override
        public void run() {
            if (!running || webSocket == null) return;
            webSocket.send("getHome");
            if (roomId != null && roomId.trim().length() > 0) {
                webSocket.send("web" + System.currentTimeMillis() + "[==]" + roomId.trim());
            }
            handler.postDelayed(this, frameDelayMs);
        }
    };

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && handleCommand(intent)) {
            return START_STICKY;
        }
        String nextServer = intent != null ? intent.getStringExtra("site") : "";
        String nextRoom = intent != null ? intent.getStringExtra("room") : "";
        if (intent != null) {
            ArrayList<String> nextRooms = intent.getStringArrayListExtra("rooms");
            ArrayList<String> nextRoomServers = intent.getStringArrayListExtra("room_servers");
            if (nextRooms != null) {
                roomNames.clear();
                roomNames.addAll(nextRooms);
            }
            if (nextRoomServers != null) {
                roomServers.clear();
                roomServers.addAll(nextRoomServers);
            }
        }
        int fps = intent != null ? intent.getIntExtra("fps", 90) : 90;
        frameDelayMs = Math.max(6, 1000 / Math.max(1, fps));
        if (nextServer == null || nextServer.trim().length() == 0) nextServer = "127.0.0.1";
        if (nextRoom == null) nextRoom = "";
        boolean changed = !nextServer.equals(server) || !nextRoom.equals(roomId);
        server = nextServer;
        roomId = nextRoom;
        startForegroundCompat();
        showOverlay();
        if (changed && radarView != null) radarView.clearHeroCache();
        if (intent != null && intent.hasExtra("minion_fix_steps") && radarView != null) {
            minionLaneRotationSteps = intent.getIntExtra("minion_fix_steps", 0);
            if (prefs != null) prefs.edit().putInt("minion_lane_rotation_steps", minionLaneRotationSteps).apply();
            radarView.setMinionLaneRotationSteps(minionLaneRotationSteps);
        }
        if (changed || webSocket == null) connect();
        return START_STICKY;
    }

    private boolean handleCommand(Intent intent) {
        String action = intent.getAction();
        if (action == null) return false;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        if (ACTION_SET_ADJUST_MODE.equals(action)) {
            adjustMode = intent.getBooleanExtra("enabled", false);
            prefs.edit().putBoolean("overlay_adjust_mode", adjustMode).apply();
            if (adjustMode) {
                panelVisible = prefs.getBoolean("adjust_panel_visible", true);
                showAdjustControls();
            } else {
                hideAdjustControls();
            }
            updateRadarTouchMode();
            return true;
        }
        if (ACTION_SET_SKILL_PANEL.equals(action)) {
            showSkillPanel = intent.getBooleanExtra("enabled", true);
            prefs.edit().putBoolean("show_skill_panel", showSkillPanel).apply();
            if (radarView != null) {
                radarView.setShowSkillPanel(showSkillPanel);
                applyOverlaySize(Math.max(params == null ? dp(260) : params.height, dp(MIN_OVERLAY_DP)));
            }
            return true;
        }
        if (ACTION_SET_OVERLAY_BOUNDS.equals(action) && radarView == null) {
            SharedPreferences.Editor editor = prefs.edit();
            if (intent.hasExtra("x")) editor.putInt("overlay_x", Math.max(0, intent.getIntExtra("x", dp(12))));
            if (intent.hasExtra("y")) editor.putInt("overlay_y", Math.max(0, intent.getIntExtra("y", dp(80))));
            if (intent.hasExtra("size")) editor.putInt("overlay_size", clamp(intent.getIntExtra("size", dp(260)), dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP)));
            editor.apply();
            return true;
        }
        if (ACTION_SET_HERO_SCALE.equals(action) && radarView == null) {
            prefs.edit().putFloat("hero_icon_scale", intent.getFloatExtra("scale", 1f)).apply();
            return true;
        }
        if (radarView == null) return false;
        if (ACTION_ADJUST.equals(action)) {
            String target = intent.getStringExtra("target");
            float dx = intent.getFloatExtra("dx", 0f);
            float dy = intent.getFloatExtra("dy", 0f);
            if ("hero".equals(target)) radarView.adjustHeroes(dx, dy);
            else if ("minion".equals(target)) radarView.adjustMinions(dx, dy);
            else if ("monster".equals(target)) radarView.adjustMonsters(dx, dy);
            return true;
        }
        if (ACTION_SET_OFFSET.equals(action)) {
            String target = intent.getStringExtra("target");
            float x = intent.getFloatExtra("x", 0f);
            float y = intent.getFloatExtra("y", 0f);
            applyOffset(target, x, y, true);
            return true;
        }
        if (ACTION_ZOOM.equals(action)) {
            radarView.zoom(intent.getFloatExtra("factor", 1f));
            return true;
        }
        if (ACTION_RESET.equals(action)) {
            resetDrawAdjustments();
            return true;
        }
        if (ACTION_SET_MINION_FIX.equals(action)) {
            minionLaneRotationSteps = intent.getIntExtra("steps", 0);
            prefs.edit().putInt("minion_lane_rotation_steps", minionLaneRotationSteps).apply();
            radarView.setMinionLaneRotationSteps(minionLaneRotationSteps);
            return true;
        }
        if (ACTION_SET_HERO_SCALE.equals(action)) {
            float scale = intent.getFloatExtra("scale", 1f);
            heroScale = scale;
            radarView.setHeroIconScale(heroScale);
            if (prefs != null) prefs.edit().putFloat("hero_icon_scale", heroScale).apply();
            return true;
        }
        if (ACTION_SET_MAP_SCALE.equals(action)) {
            mapScale = intent.getFloatExtra("scale", 1f);
            radarView.setMapScale(mapScale);
            if (prefs != null) prefs.edit().putFloat("map_scale", mapScale).apply();
            return true;
        }
        if (ACTION_SET_SKILL_SCALE.equals(action)) {
            skillScale = intent.getFloatExtra("scale", 1f);
            radarView.setSkillScale(skillScale);
            if (prefs != null) prefs.edit().putFloat("skill_scale", skillScale).apply();
            return true;
        }
        if (ACTION_SET_OVERLAY_BOUNDS.equals(action)) {
            if (params == null || windowManager == null) return true;
            int x = intent.getIntExtra("x", params.x);
            int y = intent.getIntExtra("y", params.y);
            int size = intent.getIntExtra("size", params.height);
            size = clamp(size, dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
            params.x = Math.max(0, x);
            params.y = Math.max(0, y);
            applyOverlaySize(size);
            saveOverlayBounds();
            return true;
        }
        if (ACTION_AUTO_FIT_CAPTURE.equals(action)) {
            captureScreenAndDetectMap();
            return true;
        }
        return false;
    }

    private void showOverlay() {
        if (radarView != null) {
            radarView.setRoomId(roomId);
            return;
        }
        windowManager = (WindowManager) getSystemService(WINDOW_SERVICE);
        prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        adjustMode = prefs.getBoolean("overlay_adjust_mode", false);
        showSkillPanel = prefs.getBoolean("show_skill_panel", true);
        radarView = new RadarView(this);
        radarView.setHeroIconCache(new HeroIconCache(client));
        radarView.setRoomId(roomId);
        radarView.setStatus("");
        loadAdjustPrefs();
        applyAllAdjustments(false);
        radarView.setShowSkillPanel(showSkillPanel);
        radarView.setBackgroundColor(0x00000000);

        int type = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
                : WindowManager.LayoutParams.TYPE_PHONE;
        int savedSize = prefs.getInt("overlay_size", dp(260));
        savedSize = clamp(savedSize, dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
        overlaySizePx = savedSize;
        radarView.setOverlaySize(overlaySizePx);
        params = new WindowManager.LayoutParams(WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.MATCH_PARENT, type,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
                PixelFormat.TRANSLUCENT);
        params.gravity = Gravity.TOP | Gravity.START;
        params.x = 0;
        params.y = 0;
        radarView.setOnTouchListener(new DragTouchListener());
        windowManager.addView(radarView, params);
        if (adjustMode) showAdjustControls();
        updateRadarTouchMode();
    }

    private void connect() {
        disconnect();
        running = true;
        Request request = new Request.Builder().url(buildWsUrl(server)).build();
        webSocket = client.newWebSocket(request, new WebSocketListener() {
            @Override
            public void onOpen(WebSocket ws, Response response) {
                handler.post(() -> {
                    handler.removeCallbacks(pollTask);
                    pollTask.run();
                });
            }

            @Override
            public void onMessage(WebSocket ws, String text) {
                handleMessage(text);
            }

            @Override
            public void onFailure(WebSocket ws, Throwable t, Response response) {
                reconnectLater();
            }

            @Override
            public void onClosed(WebSocket ws, int code, String reason) {
                reconnectLater();
            }
        });
    }

    private void handleMessage(String text) {
        if (text == null) return;
        String payload = null;
        if (text.startsWith("gameData##")) {
            payload = text.substring("gameData##".length());
        } else if (text.contains("gameData##")) {
            payload = text.substring(text.indexOf("gameData##") + "gameData##".length());
        }
        if (payload == null || payload.trim().length() == 0) return;
        RadarData parsed = RadarParser.parse(payload);
        handler.post(() -> {
            if (radarView != null) radarView.setData(parsed);
        });
    }

    private void reconnectLater() {
        if (!running) return;
        handler.removeCallbacks(pollTask);
        handler.postDelayed(() -> {
            if (running) connect();
        }, 3000);
    }

    private String buildWsUrl(String value) {
        String host = value == null ? "" : value.trim();
        host = host.replace("https://", "").replace("http://", "").replace("ws://", "").replace("wss://", "");
        int slash = host.indexOf('/');
        if (slash >= 0) host = host.substring(0, slash);
        if (!host.contains(":")) host += ":8888";
        return "ws://" + host + "/ws";
    }

    private void disconnect() {
        handler.removeCallbacks(pollTask);
        if (webSocket != null) {
            webSocket.close(1000, "stop");
            webSocket = null;
        }
    }

    @Override
    public void onDestroy() {
        running = false;
        disconnect();
        if (windowManager != null && radarView != null) {
            try {
                windowManager.removeView(radarView);
            } catch (Exception ignored) {
            }
        }
        hideAdjustControls();
        radarView = null;
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void startForegroundCompat() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel("native_overlay", "ALin Radar", NotificationManager.IMPORTANCE_LOW);
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) manager.createNotificationChannel(channel);
            Notification notification = new Notification.Builder(this, "native_overlay")
                    .setContentTitle("ALin Radar running")
                    .setSmallIcon(android.R.drawable.ic_menu_compass)
                    .build();
            startForeground(2, notification);
        } else {
            Notification notification = new Notification.Builder(this)
                    .setContentTitle("ALin Radar running")
                    .setSmallIcon(android.R.drawable.ic_menu_compass)
                    .build();
            startForeground(2, notification);
        }
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }

    private void saveOverlayBounds() {
        if (params == null) return;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit()
                .putInt("overlay_x", Math.round(mapX))
                .putInt("overlay_y", Math.round(mapY))
                .putInt("overlay_size", overlaySizePx > 0 ? overlaySizePx : dp(260))
                .apply();
    }

    private void applyOverlaySize(int size) {
        if (params == null || windowManager == null || radarView == null) return;
        size = clamp(size, dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
        overlaySizePx = size;
        radarView.setOverlaySize(overlaySizePx);
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit().putInt("overlay_size", size).apply();
    }

    private int overlayWidthFor(int size) {
        return showSkillPanel ? size + Math.max(dp(86), Math.min(dp(120), Math.round(size * 0.48f))) + dp(8) : size;
    }

    private int currentOverlaySize() {
        if (overlaySizePx > 0) return overlaySizePx;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        return prefs.getInt("overlay_size", dp(260));
    }

    private void autoFitResolution() {
        if (sProjectionData != null) {
            captureScreenAndDetectMap();
            return;
        }
        int sw = getResources().getDisplayMetrics().widthPixels;
        int sh = getResources().getDisplayMetrics().heightPixels;
        int longSide = Math.max(sw, sh);
        int shortSide = Math.min(sw, sh);
        int mapSizePx;
        if (longSide >= 2400) {
            mapSizePx = (int) (shortSide * 0.30f);
        } else if (longSide >= 2160) {
            mapSizePx = (int) (shortSide * 0.29f);
        } else if (longSide >= 1920) {
            mapSizePx = (int) (shortSide * 0.28f);
        } else {
            mapSizePx = (int) (shortSide * 0.27f);
        }
        applyAutoFitResult(mapSizePx);
    }

    private void applyAutoFitResult(int mapSizePx) {
        mapSizePx = clamp(mapSizePx, dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
        int screenW = getResources().getDisplayMetrics().widthPixels;
        int screenH = getResources().getDisplayMetrics().heightPixels;
        int shortSide = Math.min(screenW, screenH);
        mapX = 0f;
        mapY = -((shortSide - mapSizePx) / 2f);
        mapScale = 1f;
        heroX = heroY = 0f;
        minionX = minionY = 0f;
        monsterX = monsterY = 0f;
        skillX = skillY = 0f;
        heroScale = 0.72f;
        minionScale = 1f;
        monsterScale = 1f;
        skillScale = 1f;
        skillGap = 0f;
        overlaySizePx = mapSizePx;
        if (radarView != null) {
            radarView.setOverlaySize(overlaySizePx);
            applyAllAdjustments(true);
        }
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit().putInt("overlay_size", mapSizePx).apply();
        saveAdjustPrefs();
        renderPanelPage();
    }

    private void captureScreenAndDetectMap() {
        if (sProjectionData == null) {
            if (radarView != null) radarView.setStatus("请先授权截屏权限");
            return;
        }
        if (adjustPanel != null) adjustPanel.setVisibility(View.GONE);
        if (toggleButton != null) toggleButton.setVisibility(View.GONE);
        if (radarView != null) radarView.setVisibility(View.GONE);
        handler.postDelayed(() -> {
            try {
                doCapture();
            } catch (Exception e) {
                if (radarView != null) radarView.setStatus("截屏失败: " + e.getMessage());
                restoreOverlayViews();
            }
        }, 350);
    }

    private void restoreOverlayViews() {
        if (adjustPanel != null) adjustPanel.setVisibility(panelVisible ? View.VISIBLE : View.GONE);
        if (toggleButton != null) toggleButton.setVisibility(View.VISIBLE);
        if (radarView != null) radarView.setVisibility(View.VISIBLE);
    }

    private void doCapture() {
        DisplayMetrics metrics = getResources().getDisplayMetrics();
        int w = metrics.widthPixels;
        int h = metrics.heightPixels;
        int dpi = metrics.densityDpi;
        MediaProjectionManager mpm = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
        if (mpm == null) { restoreOverlayViews(); return; }
        MediaProjection projection = mpm.getMediaProjection(sProjectionResultCode, (Intent) sProjectionData.clone());
        if (projection == null) { restoreOverlayViews(); return; }
        ImageReader reader = ImageReader.newInstance(w, h, PixelFormat.RGBA_8888, 2);
        VirtualDisplay display = projection.createVirtualDisplay("autofit", w, h, dpi,
                DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR, reader.getSurface(), null, handler);
        reader.setOnImageAvailableListener(ir -> {
            Image image = null;
            try {
                image = ir.acquireLatestImage();
                if (image == null) return;
                int imgW = image.getWidth();
                int imgH = image.getHeight();
                Image.Plane plane = image.getPlanes()[0];
                ByteBuffer buffer = plane.getBuffer();
                int rowStride = plane.getRowStride();
                int pixelStride = plane.getPixelStride();
                int[] pixels = new int[imgW * imgH];
                for (int row = 0; row < imgH; row++) {
                    for (int col = 0; col < imgW; col++) {
                        int idx = row * rowStride + col * pixelStride;
                        int r = buffer.get(idx) & 0xff;
                        int g = buffer.get(idx + 1) & 0xff;
                        int b = buffer.get(idx + 2) & 0xff;
                        pixels[row * imgW + col] = (r << 16) | (g << 8) | b;
                    }
                }
                image.close();
                display.release();
                reader.close();
                projection.stop();
                int detected = detectMiniMapSize(pixels, imgW, imgH);
                handler.post(() -> {
                    restoreOverlayViews();
                    if (detected > dp(40)) {
                        applyAutoFitResult(detected);
                        if (radarView != null) radarView.setStatus("识别成功 " + detected + "px");
                    } else {
                        autoFitResolution();
                        if (radarView != null) radarView.setStatus("未检测到小地图,已按分辨率适配");
                    }
                });
            } catch (Exception e) {
                if (image != null) image.close();
                display.release();
                reader.close();
                projection.stop();
                handler.post(() -> {
                    restoreOverlayViews();
                    autoFitResolution();
                });
            }
        }, handler);
    }

    private int detectMiniMapSize(int[] pixels, int w, int h) {
        int scanH = Math.min(h, (int) (Math.min(w, h) * 0.45f));
        int scanW = scanH;
        int edgeRight = 0;
        int edgeBottom = 0;
        for (int y = scanH / 6; y < scanH; y += 2) {
            int darkRun = 0;
            int lastDark = -1;
            for (int x = 0; x < scanW; x++) {
                int px = pixels[y * w + x];
                int r = (px >> 16) & 0xff;
                int g = (px >> 8) & 0xff;
                int b = px & 0xff;
                float brightness = 0.299f * r + 0.587f * g + 0.114f * b;
                if (brightness < 80) {
                    darkRun++;
                    lastDark = x;
                } else {
                    if (darkRun >= 2 && lastDark > edgeRight && lastDark > scanW / 6) {
                        int nextBright = 0;
                        for (int nx = lastDark + 1; nx < Math.min(lastDark + 20, scanW); nx++) {
                            int npx = pixels[y * w + nx];
                            float nb = 0.299f * ((npx >> 16) & 0xff) + 0.587f * ((npx >> 8) & 0xff) + 0.114f * (npx & 0xff);
                            if (nb > 100) nextBright++;
                        }
                        if (nextBright >= 8) {
                            edgeRight = Math.max(edgeRight, lastDark);
                        }
                    }
                    darkRun = 0;
                }
            }
        }
        for (int x = scanW / 6; x < scanW; x += 2) {
            int darkRun = 0;
            int lastDark = -1;
            for (int y = 0; y < scanH; y++) {
                int px = pixels[y * w + x];
                int r = (px >> 16) & 0xff;
                int g = (px >> 8) & 0xff;
                int b = px & 0xff;
                float brightness = 0.299f * r + 0.587f * g + 0.114f * b;
                if (brightness < 80) {
                    darkRun++;
                    lastDark = y;
                } else {
                    if (darkRun >= 2 && lastDark > edgeBottom && lastDark > scanH / 6) {
                        int nextBright = 0;
                        for (int ny = lastDark + 1; ny < Math.min(lastDark + 20, scanH); ny++) {
                            int npx = pixels[ny * w + x];
                            float nb = 0.299f * ((npx >> 16) & 0xff) + 0.587f * ((npx >> 8) & 0xff) + 0.114f * (npx & 0xff);
                            if (nb > 100) nextBright++;
                        }
                        if (nextBright >= 8) {
                            edgeBottom = Math.max(edgeBottom, lastDark);
                        }
                    }
                    darkRun = 0;
                }
            }
        }
        if (edgeRight > dp(40) && edgeBottom > dp(40)) {
            return (edgeRight + edgeBottom) / 2;
        }
        if (edgeRight > dp(40)) return edgeRight;
        if (edgeBottom > dp(40)) return edgeBottom;
        return 0;
    }

    private int clamp(int value, int min, int max) {
        return Math.max(min, Math.min(max, value));
    }

    private float clamp(float value, float min, float max) {
        return Math.max(min, Math.min(max, value));
    }

    private void loadAdjustPrefs() {
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        heroX = prefs.getFloat("hero_x", 0f);
        heroY = prefs.getFloat("hero_y", 0f);
        minionX = prefs.getFloat("minion_x", 0f);
        minionY = prefs.getFloat("minion_y", 0f);
        monsterX = prefs.getFloat("monster_x", 0f);
        monsterY = prefs.getFloat("monster_y", 0f);
        mapX = prefs.getFloat("map_x", 0f);
        mapY = prefs.getFloat("map_y", 0f);
        skillX = prefs.getFloat("skill_x", 0f);
        skillY = prefs.getFloat("skill_y", 0f);
        mapScale = prefs.getFloat("map_scale", 1f);
        skillScale = prefs.getFloat("skill_scale", 1f);
        skillGap = prefs.getFloat("skill_gap", 0f);
        skillAvatarScale = prefs.getFloat("skill_avatar_scale", 1f);
        heroScale = prefs.getFloat("hero_icon_scale", 0.72f);
        minionScale = prefs.getFloat("minion_scale", 1f);
        monsterScale = prefs.getFloat("monster_scale", 1f);
        showMap = prefs.getBoolean("show_map", true);
        showHeroes = prefs.getBoolean("show_heroes", true);
        showMinions = prefs.getBoolean("show_minions", true);
        showMonsters = prefs.getBoolean("show_monsters", true);
        showSkillPanel = prefs.getBoolean("show_skill_panel", showSkillPanel);
        showZeroSkillCd = prefs.getBoolean("show_zero_skill_cd", true);
        minionLaneRotationSteps = prefs.getInt("minion_lane_rotation_steps", 0);
        activeMainPage = prefs.getInt("adjust_main_page", activeMainPage);
        activeDrawTab = prefs.getInt("adjust_draw_tab", activeDrawTab);
        if (activeMainPage < 0 || activeMainPage > 4) activeMainPage = 2;
        if (activeDrawTab < 0 || activeDrawTab > 4) activeDrawTab = 0;
    }

    private void saveAdjustPrefs() {
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit()
                .putFloat("hero_x", heroX).putFloat("hero_y", heroY)
                .putFloat("minion_x", minionX).putFloat("minion_y", minionY)
                .putFloat("monster_x", monsterX).putFloat("monster_y", monsterY)
                .putFloat("map_x", mapX).putFloat("map_y", mapY)
                .putFloat("skill_x", skillX).putFloat("skill_y", skillY)
                .putFloat("map_scale", mapScale)
                .putFloat("skill_scale", skillScale)
                .putFloat("skill_gap", skillGap)
                .putFloat("skill_avatar_scale", skillAvatarScale)
                .putFloat("hero_icon_scale", heroScale)
                .putFloat("minion_scale", minionScale)
                .putFloat("monster_scale", monsterScale)
                .putBoolean("show_map", showMap)
                .putBoolean("show_heroes", showHeroes)
                .putBoolean("show_minions", showMinions)
                .putBoolean("show_monsters", showMonsters)
                .putBoolean("show_skill_panel", showSkillPanel)
                .putBoolean("show_zero_skill_cd", showZeroSkillCd)
                .putInt("minion_lane_rotation_steps", minionLaneRotationSteps)
                .apply();
    }

    private void applyAllAdjustments(boolean save) {
        if (radarView == null) return;
        radarView.setMapOffset(mapX, mapY);
        radarView.setMapScale(mapScale);
        radarView.setSkillOffset(skillX, skillY);
        radarView.setSkillScale(skillScale);
        radarView.setSkillGap(skillGap);
        radarView.setSkillAvatarScale(skillAvatarScale);
        radarView.setHeroOffset(heroX, heroY);
        radarView.setMinionOffset(minionX, minionY);
        radarView.setMonsterOffset(monsterX, monsterY);
        radarView.setHeroIconScale(heroScale);
        radarView.setMinionScale(minionScale);
        radarView.setMonsterScale(monsterScale);
        radarView.setLayerVisibility(showMap, showHeroes, showMinions, showMonsters, showSkillPanel);
        radarView.setShowZeroSkillCd(showZeroSkillCd);
        radarView.setMinionLaneRotationSteps(minionLaneRotationSteps);
        if (save) saveAdjustPrefs();
    }

    private void resetDrawAdjustments() {
        heroX = heroY = minionX = minionY = monsterX = monsterY = 0f;
        mapX = mapY = skillX = skillY = 0f;
        mapScale = skillScale = 1f;
        skillGap = 0f;
        skillAvatarScale = 1f;
        minionScale = monsterScale = 1f;
        heroScale = 0.72f;
        minionLaneRotationSteps = 0;
        if (radarView != null) {
            radarView.resetAdjustments();
            radarView.setMinionLaneRotationSteps(minionLaneRotationSteps);
        }
        saveAdjustPrefs();
    }

    private void applyOffset(String target, float x, float y, boolean save) {
        if ("map".equals(target)) {
            mapX = x;
            mapY = y;
            if (radarView != null) radarView.setMapOffset(mapX, mapY);
        } else if ("skill".equals(target)) {
            skillX = x;
            skillY = y;
            if (radarView != null) radarView.setSkillOffset(skillX, skillY);
        } else if ("hero".equals(target)) {
            heroX = x;
            heroY = y;
            if (radarView != null) radarView.setHeroOffset(heroX, heroY);
        } else if ("minion".equals(target)) {
            minionX = x;
            minionY = y;
            if (radarView != null) radarView.setMinionOffset(minionX, minionY);
        } else if ("monster".equals(target)) {
            monsterX = x;
            monsterY = y;
            if (radarView != null) radarView.setMonsterOffset(monsterX, monsterY);
        } else if ("skillgap".equals(target)) {
            skillGap = x;
            if (radarView != null) radarView.setSkillGap(skillGap);
        }
        if (save) saveAdjustPrefs();
    }

    private void showAdjustControls() {
        if (windowManager == null) windowManager = (WindowManager) getSystemService(WINDOW_SERVICE);
        if (windowManager == null) return;
        if (toggleButton == null) {
            toggleButton = new Button(this);
            toggleButton.setAllCaps(false);
            toggleButton.setTextSize(12);
            toggleButton.setTextColor(Color.WHITE);
            toggleButton.setPadding(0, 0, 0, 0);
            toggleButton.setAlpha(0.72f);
            toggleButton.setBackground(makeBox(0x33000000, 0x44ffffff, dp(16)));
            toggleButton.setOnClickListener(v -> {
                panelVisible = !panelVisible;
                if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
                prefs.edit().putBoolean("adjust_panel_visible", panelVisible).apply();
                updatePanelVisibility();
                updateRadarTouchMode();
            });
        }
        if (toggleParams == null) {
            int type = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                    ? WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
                    : WindowManager.LayoutParams.TYPE_PHONE;
            toggleParams = new WindowManager.LayoutParams(dp(96), dp(28), type,
                    WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
                    PixelFormat.TRANSLUCENT);
            toggleParams.gravity = Gravity.TOP | Gravity.CENTER_HORIZONTAL;
            toggleParams.y = dp(2);
        }
        if (toggleButton.getParent() == null) {
            windowManager.addView(toggleButton, toggleParams);
        }
        if (adjustPanel == null) {
            adjustPanel = buildAdjustPanel();
        }
        if (panelParams == null) {
            int type = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                    ? WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
                    : WindowManager.LayoutParams.TYPE_PHONE;
            panelParams = new WindowManager.LayoutParams(dp(320), dp(300), type,
                    WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
                    PixelFormat.TRANSLUCENT);
            panelParams.gravity = Gravity.CENTER;
        }
        applyPanelSizeAndPosition();
        if (adjustPanel.getParent() == null) {
            windowManager.addView(adjustPanel, panelParams);
        } else {
            windowManager.updateViewLayout(adjustPanel, panelParams);
        }
        updatePanelVisibility();
    }

    private void applyPanelSizeAndPosition() {
        if (panelParams == null) return;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        int sw = getResources().getDisplayMetrics().widthPixels;
        int sh = getResources().getDisplayMetrics().heightPixels;
        boolean landscape = sw > sh;
        panelParams.width = landscape
                ? Math.max(dp(460), Math.min((int) (sw * 0.58f), dp(700)))
                : Math.max(dp(300), Math.min((int) (sw * 0.92f), dp(430)));
        panelParams.height = landscape
                ? Math.max(dp(250), Math.min((int) (sh * 0.84f), dp(430)))
                : Math.max(dp(300), Math.min((int) (sh * 0.56f), dp(520)));
        int maxX = Math.max(0, (sw - panelParams.width) / 2);
        int maxY = Math.max(0, (sh - panelParams.height) / 2);
        panelParams.x = clamp(prefs.getInt("adjust_panel_x", 0), -maxX, maxX);
        panelParams.y = clamp(prefs.getInt("adjust_panel_y", 0), -maxY, maxY);
    }

    private void savePanelPosition() {
        if (panelParams == null) return;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit()
                .putInt("adjust_panel_x", panelParams.x)
                .putInt("adjust_panel_y", panelParams.y)
                .apply();
    }

    private void hideAdjustControls() {
        if (windowManager != null && adjustPanel != null && adjustPanel.getParent() != null) {
            try { windowManager.removeView(adjustPanel); } catch (Exception ignored) {}
        }
        if (windowManager != null && toggleButton != null && toggleButton.getParent() != null) {
            try { windowManager.removeView(toggleButton); } catch (Exception ignored) {}
        }
    }

    private void updatePanelVisibility() {
        if (adjustPanel != null) adjustPanel.setVisibility(panelVisible ? View.VISIBLE : View.GONE);
        if (toggleButton != null) {
            toggleButton.setText(panelVisible ? "隐藏调节" : "展开调节");
            toggleButton.setAlpha(panelVisible ? 0.72f : 0.46f);
        }
    }

    private void updateRadarTouchMode() {
        if (params == null || windowManager == null || radarView == null) return;
        int flags = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE;
        if (!adjustMode || !panelVisible) flags |= WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE;
        if (params.flags != flags) {
            params.flags = flags;
            windowManager.updateViewLayout(radarView, params);
        }
    }

    private LinearLayout buildAdjustPanel() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(6), dp(5), dp(6), dp(6));
        root.setBackground(makeBox(panelBgColor(), panelStrokeColor(), dp(10)));

        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);
        top.setOnTouchListener(new PanelDragTouchListener());
        top.setPadding(dp(4), 0, dp(4), 0);
        top.setBackground(makeBox(panelHeaderColor(), panelStrokeColor(), dp(8)));
        TextView title = panelText("ALinRadar", 13, true);
        top.addView(title, new LinearLayout.LayoutParams(0, dp(38), 1));
        Button midHide = panelButton("锁定");
        midHide.setOnClickListener(v -> hidePanelOnly());
        top.addView(midHide, new LinearLayout.LayoutParams(dp(58), dp(30)));
        Button save = panelButton("保存");
        save.setOnClickListener(v -> saveAdjustPrefs());
        Button hide = panelButton("隐藏");
        hide.setOnClickListener(v -> hidePanelOnly());
        top.addView(save, new LinearLayout.LayoutParams(dp(52), dp(30)));
        top.addView(hide, new LinearLayout.LayoutParams(dp(52), dp(30)));
        root.addView(top, new LinearLayout.LayoutParams(-1, dp(38)));

        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.HORIZONTAL);

        LinearLayout nav = new LinearLayout(this);
        nav.setOrientation(LinearLayout.VERTICAL);
        nav.setPadding(dp(3), dp(3), dp(3), dp(3));
        nav.setBackground(makeBox(panelInsetColor(), panelStrokeColor(), dp(8)));
        String[] names = new String[]{"主页", "绘制", "设置", "触摸", "内存"};
        mainNavButtons = new Button[names.length];
        for (int i = 0; i < names.length; i++) {
            final int page = i;
            Button row = panelButton(names[i]);
            row.setGravity(Gravity.CENTER_VERTICAL);
            row.setPadding(dp(7), 0, 0, 0);
            row.setOnClickListener(v -> {
                activeMainPage = page;
                activeDrawTab = 0;
                savePanelPage();
                renderPanelPage();
            });
            LinearLayout.LayoutParams rowLp = new LinearLayout.LayoutParams(-1, dp(32));
            rowLp.bottomMargin = dp(4);
            nav.addView(row, rowLp);
            mainNavButtons[i] = row;
        }
        content.addView(nav, new LinearLayout.LayoutParams(dp(68), -1));

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(false);
        scroll.setBackground(makeBox(panelContentColor(), panelStrokeColor(), dp(8)));
        panelContent = new LinearLayout(this);
        panelContent.setOrientation(LinearLayout.VERTICAL);
        panelContent.setPadding(dp(6), dp(5), dp(6), dp(5));
        scroll.addView(panelContent, new ScrollView.LayoutParams(-1, -2));
        LinearLayout.LayoutParams ctlLp = new LinearLayout.LayoutParams(0, -1, 1);
        ctlLp.leftMargin = dp(6);
        content.addView(scroll, ctlLp);
        root.addView(content, new LinearLayout.LayoutParams(-1, 0, 1));
        renderPanelPage();
        return root;
    }

    private void hidePanelOnly() {
        panelVisible = false;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit().putBoolean("adjust_panel_visible", false).apply();
        updatePanelVisibility();
        updateRadarTouchMode();
    }

    private void renderPanelPage() {
        if (panelContent == null) return;
        panelContent.removeAllViews();
        updatePanelButtons();
        if (activeMainPage == 0) {
            panelContent.addView(panelText("主页区域", 13, true), new LinearLayout.LayoutParams(-1, dp(24)));
            renderPanelRoomChooser();
            addPanelAction(panelContent, sProjectionData != null ? "一键适配(截屏识别)" : "一键适配(按分辨率)", this::autoFitResolution);
            addPanelAction(panelContent, "保存", this::saveAdjustPrefs);
            addPanelAction(panelContent, "退出面板", () -> {
                adjustMode = false;
                if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
                prefs.edit().putBoolean("overlay_adjust_mode", false).apply();
                hideAdjustControls();
                updateRadarTouchMode();
            });        } else if (activeMainPage == 1) {
            addToggleRow(panelContent, "地图显示", showMap, on -> { showMap = on; applyAllAdjustments(true); });
            addToggleRow(panelContent, "英雄头像", showHeroes, on -> { showHeroes = on; applyAllAdjustments(true); });
            addToggleRow(panelContent, "技能面板", showSkillPanel, on -> { showSkillPanel = on; applyAllAdjustments(true); applyOverlaySize(currentOverlaySize()); });
            addToggleRow(panelContent, "兵线绘制", showMinions, on -> { showMinions = on; applyAllAdjustments(true); });
            addToggleRow(panelContent, "野怪计时", showMonsters, on -> { showMonsters = on; applyAllAdjustments(true); });
            addToggleRow(panelContent, "人物射线", false, on -> {});
            addToggleRow(panelContent, "触摸透传", !panelVisible, on -> hidePanelOnly());
        } else if (activeMainPage == 2) {
            renderSettingPage();
        } else if (activeMainPage == 3) {
            addToggleRow(panelContent, "隐藏后透触", true, on -> {});
            addToggleRow(panelContent, "调节时可拖动雷达", true, on -> {});
            addPanelAction(panelContent, "面板居中", this::centerAdjustPanel);
            addPanelAction(panelContent, "隐藏调节并锁定触摸", this::hidePanelOnly);
            addPanelAction(panelContent, "显示调节", () -> {
                panelVisible = true;
                if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
                prefs.edit().putBoolean("adjust_panel_visible", true).apply();
                updatePanelVisibility();
                updateRadarTouchMode();
            });
        } else {
            panelContent.addView(panelText("内存区域", 13, true), new LinearLayout.LayoutParams(-1, dp(28)));
            panelContent.addView(panelText("当前版本不读取内存，仅显示服务器雷达数据", 12, false), new LinearLayout.LayoutParams(-1, dp(32)));
            addPanelAction(panelContent, "保存", this::saveAdjustPrefs);
        }
    }

    private void centerAdjustPanel() {
        if (panelParams == null || windowManager == null || adjustPanel == null) return;
        panelParams.x = 0;
        panelParams.y = 0;
        savePanelPosition();
        if (adjustPanel.getParent() != null) windowManager.updateViewLayout(adjustPanel, panelParams);
    }

    private void savePanelPage() {
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit()
                .putInt("adjust_main_page", activeMainPage)
                .putInt("adjust_draw_tab", activeDrawTab)
                .apply();
    }

    private void renderPanelRoomChooser() {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        panelRoomButton = panelButton("当前: " + currentRoomLabel());
        roomCountText = panelText(roomNames.size() + "间", 12, true);
        roomCountText.setGravity(Gravity.CENTER);
        roomCountText.setBackground(makeBox(panelButtonColor(false), panelStrokeColor(), dp(6)));
        header.addView(panelRoomButton, new LinearLayout.LayoutParams(0, dp(36), 1));
        LinearLayout.LayoutParams countLp = new LinearLayout.LayoutParams(dp(54), dp(36));
        countLp.leftMargin = dp(6);
        header.addView(roomCountText, countLp);
        panelContent.addView(header, new LinearLayout.LayoutParams(-1, dp(38)));

        ScrollView roomScroll = new ScrollView(this);
        roomScroll.setFillViewport(false);
        roomScroll.setNestedScrollingEnabled(true);
        roomScroll.setBackground(makeBox(panelInsetColor(), panelStrokeColor(), dp(7)));
        roomScroll.setOnTouchListener((v, ev) -> { v.getParent().requestDisallowInterceptTouchEvent(true); return false; });
        LinearLayout list = new LinearLayout(this);
        list.setOrientation(LinearLayout.VERTICAL);
        list.setPadding(dp(4), dp(4), dp(4), dp(4));
        roomScroll.addView(list, new ScrollView.LayoutParams(-1, -2));

        if (roomNames.isEmpty()) {
            TextView empty = panelText(roomId == null || roomId.length() == 0 ? "正在获取房间号" : roomId, 12, false);
            empty.setGravity(Gravity.CENTER);
            list.addView(empty, new LinearLayout.LayoutParams(-1, dp(38)));
        } else {
            for (String name : roomNames) {
                Button room = panelButton(name);
                room.setGravity(Gravity.CENTER_VERTICAL);
                room.setPadding(dp(10), 0, dp(10), 0);
                boolean selected = name != null && name.equals(roomId);
                room.setBackground(makeBox(panelButtonColor(selected), panelStrokeColor(), dp(6)));
                room.setOnClickListener(v -> switchPanelRoom(name));
                LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(38));
                lp.bottomMargin = dp(4);
                list.addView(room, lp);
            }
        }

        int rows = roomNames.isEmpty() ? 1 : Math.min(2, roomNames.size());
        LinearLayout.LayoutParams scrollLp = new LinearLayout.LayoutParams(-1, dp(46 + (rows - 1) * 42));
        scrollLp.topMargin = dp(6);
        scrollLp.bottomMargin = dp(6);
        panelContent.addView(roomScroll, scrollLp);
    }

    private String currentRoomLabel() {
        return roomId == null || roomId.trim().length() == 0 ? "未选择" : roomId.trim();
    }

    private void switchPanelRoom(String nextRoom) {
        if (nextRoom == null || nextRoom.trim().length() == 0) return;
        nextRoom = nextRoom.trim();
        String nextServer = server == null ? "" : server;
        int idx = roomNames.indexOf(nextRoom);
        if (idx >= 0 && idx < roomServers.size()) {
            String mapped = roomServers.get(idx);
            if (mapped != null && mapped.length() > 0) nextServer = mapped;
        }
        if (nextServer.length() > 0) server = nextServer;
        boolean roomChanged = !nextRoom.equals(roomId);
        roomId = nextRoom;
        if (radarView != null) {
            if (roomChanged) radarView.clearHeroCache();
            radarView.setRoomId(roomId);
        }
        if (panelRoomButton != null) panelRoomButton.setText("当前: " + currentRoomLabel());
        connect();
        renderPanelPage();
    }

    private void renderSettingPage() {
        renderGroupedSettingPage();
    }

    private void renderGroupedSettingPage() {
        LinearLayout tabs = new LinearLayout(this);
        tabs.setOrientation(LinearLayout.HORIZONTAL);
        String[] tabNames = new String[]{"地图", "技能", "英雄", "兵线", "野怪"};
        tabButtons = new Button[tabNames.length];
        if (activeDrawTab < 0 || activeDrawTab >= tabNames.length) activeDrawTab = 0;
        for (int i = 0; i < tabNames.length; i++) {
            final int tab = i;
            Button b = panelButton(tabNames[i]);
            b.setOnClickListener(v -> {
                activeDrawTab = tab;
                savePanelPage();
                renderPanelPage();
            });
            tabs.addView(b, new LinearLayout.LayoutParams(0, dp(30), 1));
            tabButtons[i] = b;
        }
        panelContent.addView(tabs, new LinearLayout.LayoutParams(-1, dp(34)));
        updatePanelButtons();
        addPanelAction(panelContent, "全部复位", () -> {
            resetDrawAdjustments();
            renderPanelPage();
        });

        if (activeDrawTab == 0) {
            addToggleRow(panelContent, "地图显示", showMap, on -> { showMap = on; applyAllAdjustments(true); });
            addControlRow(panelContent, "地图左右", "map", true, -1200, 1200, Math.round(mapX));
            addControlRow(panelContent, "地图上下", "map", false, -1200, 1200, Math.round(mapY));
            addScaleRow(panelContent, "地图大小", "map", 25, 240, Math.round(mapScale * 100));
            addPanelAction(panelContent, "复位地图", () -> {
                mapX = mapY = 0f;
                mapScale = 1f;
                applyAllAdjustments(true);
                renderPanelPage();
            });
        } else if (activeDrawTab == 1) {
            addToggleRow(panelContent, "技能面板", showSkillPanel, on -> { showSkillPanel = on; applyAllAdjustments(true); applyOverlaySize(currentOverlaySize()); });
            addToggleRow(panelContent, "显示0秒CD", showZeroSkillCd, on -> {
                showZeroSkillCd = on;
                if (radarView != null) radarView.setShowZeroSkillCd(showZeroSkillCd);
                saveAdjustPrefs();
            });
            addControlRow(panelContent, "技能左右", "skill", true, -1200, 1200, Math.round(skillX));
            addControlRow(panelContent, "技能上下", "skill", false, -1200, 1200, Math.round(skillY));
            addScaleRow(panelContent, "技能大小", "skill", 15, 180, Math.round(skillScale * 100));
            addScaleRow(panelContent, "头像大小", "skillavatar", 45, 180, Math.round(skillAvatarScale * 100));
            addControlRow(panelContent, "技能间距", "skillgap", true, -50, 100, Math.round(skillGap));
            addPanelAction(panelContent, "复位技能", () -> {
                skillX = skillY = 0f;
                skillScale = 1f;
                skillAvatarScale = 1f;
                skillGap = 0f;
                applyAllAdjustments(true);
                renderPanelPage();
            });
        } else if (activeDrawTab == 2) {
            addToggleRow(panelContent, "英雄头像", showHeroes, on -> { showHeroes = on; applyAllAdjustments(true); });
            addControlRow(panelContent, "英雄左右", "hero", true, -1200, 1200, Math.round(heroX));
            addControlRow(panelContent, "英雄上下", "hero", false, -1200, 1200, Math.round(heroY));
            addScaleRow(panelContent, "头像大小", "hero", 25, 220, Math.round(heroScale * 100));
            addPanelAction(panelContent, "复位英雄", () -> {
                heroX = heroY = 0f;
                heroScale = 0.72f;
                applyAllAdjustments(true);
                renderPanelPage();
            });
        } else if (activeDrawTab == 3) {
            addToggleRow(panelContent, "兵线显示", showMinions, on -> { showMinions = on; applyAllAdjustments(true); });
            addPanelAction(panelContent, "兵线方向 " + minionFixLabel(), this::cycleMinionLaneFix);
            addControlRow(panelContent, "兵线左右", "minion", true, -1200, 1200, Math.round(minionX));
            addControlRow(panelContent, "兵线上下", "minion", false, -1200, 1200, Math.round(minionY));
            addScaleRow(panelContent, "兵线大小", "minion", 25, 300, Math.round(minionScale * 100));
            addPanelAction(panelContent, "复位兵线", () -> {
                minionX = minionY = 0f;
                minionScale = 1f;
                minionLaneRotationSteps = 0;
                applyAllAdjustments(true);
                if (radarView != null) radarView.setMinionLaneRotationSteps(minionLaneRotationSteps);
                renderPanelPage();
            });
        } else {
            addToggleRow(panelContent, "野怪显示", showMonsters, on -> { showMonsters = on; applyAllAdjustments(true); });
            addControlRow(panelContent, "野怪左右", "monster", true, -1200, 1200, Math.round(monsterX));
            addControlRow(panelContent, "野怪上下", "monster", false, -1200, 1200, Math.round(monsterY));
            addScaleRow(panelContent, "野怪大小", "monster", 25, 300, Math.round(monsterScale * 100));
            addPanelAction(panelContent, "复位野怪", () -> {
                monsterX = monsterY = 0f;
                monsterScale = 1f;
                applyAllAdjustments(true);
                renderPanelPage();
            });
        }
    }

    private void updatePanelButtons() {
        if (mainNavButtons != null) {
            for (int i = 0; i < mainNavButtons.length; i++) {
                mainNavButtons[i].setTextColor(panelTextColor());
                mainNavButtons[i].setBackground(makeBox(panelButtonColor(i == activeMainPage), panelStrokeColor(), dp(5)));
            }
        }
        if (tabButtons != null) {
            for (int i = 0; i < tabButtons.length; i++) {
                tabButtons[i].setTextColor(panelTextColor());
                tabButtons[i].setBackground(makeBox(panelButtonColor(i == activeDrawTab), panelStrokeColor(), dp(5)));
            }
        }
    }

    private void cycleMinionLaneFix() {
        minionLaneRotationSteps = (minionLaneRotationSteps + 1) % 4;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        prefs.edit().putInt("minion_lane_rotation_steps", minionLaneRotationSteps).apply();
        if (radarView != null) radarView.setMinionLaneRotationSteps(minionLaneRotationSteps);
        renderPanelPage();
    }

    private String minionFixLabel() {
        String[] labels = new String[]{"默认", "90°", "180°", "270°"};
        return labels[minionLaneRotationSteps % 4];
    }

    private interface PanelToggleAction {
        void onChanged(boolean on);
    }

    private void addToggleRow(LinearLayout parent, String label, boolean checked, PanelToggleAction action) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        Switch sw = new Switch(this);
        sw.setChecked(checked);
        sw.setOnCheckedChangeListener((buttonView, isChecked) -> action.onChanged(isChecked));
        row.addView(sw, new LinearLayout.LayoutParams(dp(58), dp(32)));
        row.addView(panelText(label, 12, false), new LinearLayout.LayoutParams(0, dp(32), 1));
        parent.addView(row, new LinearLayout.LayoutParams(-1, dp(34)));
    }

    private void addPanelAction(LinearLayout parent, String text, Runnable action) {
        Button button = panelButton(text);
        button.setOnClickListener(v -> action.run());
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(32));
        lp.topMargin = dp(4);
        parent.addView(button, lp);
    }

    private void addControlRow(LinearLayout parent, String label, String target, boolean isX, int min, int max, int current) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        TextView value = panelText(String.valueOf(current), 11, false);
        value.setGravity(Gravity.CENTER);
        SeekBar bar = new SeekBar(this);
        stylePanelSeekBar(bar);
        final int range = max - min;
        bar.setMax(range);
        bar.setProgress(clamp(current - min, 0, range));
        Runnable applyCtrl = () -> { int next = min + bar.getProgress(); value.setText(String.valueOf(next)); float x = currentOffsetX(target); float y = currentOffsetY(target); if (isX) x = next; else y = next; applyOffset(target, x, y, true); };
        bar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) { applyCtrl.run(); }
            @Override public void onStartTrackingTouch(SeekBar seekBar) {}
            @Override public void onStopTrackingTouch(SeekBar seekBar) {}
        });
        Button minus = panelButton("-");
        minus.setOnClickListener(v -> { bar.setProgress(Math.max(0, bar.getProgress() - 1)); applyCtrl.run(); });
        Button plus = panelButton("+");
        plus.setOnClickListener(v -> { bar.setProgress(Math.min(range, bar.getProgress() + 1)); applyCtrl.run(); });
        row.addView(panelText(label, 10, false), new LinearLayout.LayoutParams(dp(52), dp(36)));
        row.addView(minus, new LinearLayout.LayoutParams(dp(26), dp(26)));
        row.addView(bar, new LinearLayout.LayoutParams(0, dp(36), 1));
        row.addView(plus, new LinearLayout.LayoutParams(dp(26), dp(26)));
        row.addView(value, new LinearLayout.LayoutParams(dp(36), dp(36)));
        parent.addView(row, new LinearLayout.LayoutParams(-1, dp(38)));
    }
    private void addScaleRow(LinearLayout parent, String label, String target, int min, int max, int current) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        TextView value = panelText(String.valueOf(current), 11, false);
        value.setGravity(Gravity.CENTER);
        SeekBar bar = new SeekBar(this);
        stylePanelSeekBar(bar);
        final int range = max - min;
        bar.setMax(range);
        bar.setProgress(clamp(current - min, 0, range));
        Runnable applyScale = () -> {
            int next = min + bar.getProgress();
            value.setText(String.valueOf(next));
            float scale = next / 100f;
            if ("map".equals(target)) { mapScale = scale; if (radarView != null) radarView.setMapScale(mapScale); }
            else if ("skill".equals(target)) { skillScale = scale; if (radarView != null) radarView.setSkillScale(skillScale); }
            else if ("skillavatar".equals(target)) { skillAvatarScale = scale; if (radarView != null) radarView.setSkillAvatarScale(skillAvatarScale); }
            else if ("minion".equals(target)) { minionScale = scale; if (radarView != null) radarView.setMinionScale(minionScale); }
            else if ("monster".equals(target)) { monsterScale = scale; if (radarView != null) radarView.setMonsterScale(monsterScale); }
            else { heroScale = scale; if (radarView != null) radarView.setHeroIconScale(heroScale); }
            saveAdjustPrefs();
        };
        bar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) { applyScale.run(); }
            @Override public void onStartTrackingTouch(SeekBar seekBar) {}
            @Override public void onStopTrackingTouch(SeekBar seekBar) {}
        });
        Button minus = panelButton("-");
        minus.setOnClickListener(v -> { bar.setProgress(Math.max(0, bar.getProgress() - 1)); applyScale.run(); });
        Button plus = panelButton("+");
        plus.setOnClickListener(v -> { bar.setProgress(Math.min(range, bar.getProgress() + 1)); applyScale.run(); });
        row.addView(panelText(label, 10, false), new LinearLayout.LayoutParams(dp(52), dp(36)));
        row.addView(minus, new LinearLayout.LayoutParams(dp(26), dp(26)));
        row.addView(bar, new LinearLayout.LayoutParams(0, dp(36), 1));
        row.addView(plus, new LinearLayout.LayoutParams(dp(26), dp(26)));
        row.addView(value, new LinearLayout.LayoutParams(dp(36), dp(36)));
        parent.addView(row, new LinearLayout.LayoutParams(-1, dp(38)));
    }

    private void stylePanelSeekBar(SeekBar bar) {
        bar.setPadding(dp(2), 0, dp(2), 0);
        bar.setMinHeight(dp(40));
        if (Build.VERSION.SDK_INT >= 21) {
            int accent = panelAccentColor();
            bar.setSplitTrack(false);
            bar.setProgressTintList(ColorStateList.valueOf(accent));
            bar.setProgressBackgroundTintList(ColorStateList.valueOf(0x667c8aa5));
            bar.setThumbTintList(ColorStateList.valueOf(accent));
        }
    }
    private float currentOffsetX(String target) {
        if ("map".equals(target)) return mapX;
        if ("skill".equals(target)) return skillX;
        if ("skillgap".equals(target)) return skillGap;
        if ("hero".equals(target)) return heroX;
        if ("minion".equals(target)) return minionX;
        return monsterX;
    }

    private float currentOffsetY(String target) {
        if ("map".equals(target)) return mapY;
        if ("skill".equals(target)) return skillY;
        if ("skillgap".equals(target)) return 0f;
        if ("hero".equals(target)) return heroY;
        if ("minion".equals(target)) return minionY;
        return monsterY;
    }

    private TextView panelText(String text, int sp, boolean bold) {
        TextView view = new TextView(this);
        view.setText(text);
        view.setTextColor(panelTextColor());
        view.setTextSize(sp);
        view.setGravity(Gravity.CENTER_VERTICAL);
        if (bold) view.setTypeface(Typeface.DEFAULT_BOLD);
        return view;
    }

    private Button panelButton(String text) {
        Button button = new Button(this);
        button.setText(text);
        button.setAllCaps(false);
        button.setTextSize(12);
        button.setTextColor(panelTextColor());
        button.setPadding(0, 0, 0, 0);
        button.setBackground(makeBox(panelButtonColor(false), panelStrokeColor(), dp(7)));
        return button;
    }

    private int panelTheme() {
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        return prefs.getInt("theme", 0);
    }

    private int panelBgColor() {
        int theme = panelTheme();
        if (theme == 3) return 0x88052e2b;
        if (theme == 1) return 0x88111827;
        if (theme == 2) return 0x99eeeffd;
        return 0x99f8fafc;
    }

    private int panelInsetColor() {
        int theme = panelTheme();
        if (theme == 3) return 0x66082f2c;
        if (theme == 1) return 0x660b1220;
        if (theme == 2) return 0x77e0f2fe;
        return 0x77ffffff;
    }

    private int panelHeaderColor() {
        int theme = panelTheme();
        if (theme == 3) return 0x88064e3b;
        if (theme == 1) return 0x880f172a;
        if (theme == 2) return 0x990284c7;
        return 0x992563eb;
    }

    private int panelContentColor() {
        int theme = panelTheme();
        if (theme == 3) return 0x550f172a;
        if (theme == 1) return 0x55020617;
        if (theme == 2) return 0x66eff6ff;
        return 0x66f8fafc;
    }

    private int panelStrokeColor() {
        int theme = panelTheme();
        if (theme == 3) return 0x7734d399;
        if (theme == 1) return 0x7760a5fa;
        if (theme == 2) return 0x7760a5fa;
        return 0x7738bdf8;
    }

    private int panelTextColor() {
        int theme = panelTheme();
        return (theme == 1 || theme == 3) ? 0xffffffff : 0xff0f172a;
    }

    private int panelButtonColor(boolean active) {
        int theme = panelTheme();
        if (theme == 3) return active ? 0x99059669 : 0x44334d42;
        if (theme == 1) return active ? 0x990284c7 : 0x44334155;
        if (theme == 2) return active ? 0x9938bdf8 : 0x66bfdbfe;
        return active ? 0x9938bdf8 : 0x66bae6fd;
    }

    private int panelAccentColor() {
        int theme = panelTheme();
        if (theme == 3) return 0xff34d399;
        if (theme == 1) return 0xff38bdf8;
        return 0xff2563eb;
    }

    private GradientDrawable makeBox(int color, int strokeColor, int radius) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(color);
        drawable.setCornerRadius(radius);
        drawable.setStroke(dp(1), strokeColor);
        return drawable;
    }

    private class DragTouchListener implements View.OnTouchListener {
        private int startX;
        private int startY;
        private int startSize;
        private float downX;
        private float downY;
        private float startPinchDistance;
        private boolean pinching;

        @Override
        public boolean onTouch(View v, MotionEvent event) {
            if (params == null || windowManager == null) return false;
            if (!adjustMode) return true;
            int action = event.getActionMasked();
            if (event.getPointerCount() >= 2) {
                float distance = pointerDistance(event);
                if (action == MotionEvent.ACTION_POINTER_DOWN || !pinching) {
                    pinching = true;
                    startPinchDistance = distance;
                    startSize = currentOverlaySize();
                    return true;
                }
                if (action == MotionEvent.ACTION_MOVE && startPinchDistance > 0) {
                    float scale = distance / startPinchDistance;
                    int nextSize = clamp((int) (startSize * scale), dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
                    applyOverlaySize(nextSize);
                    return true;
                }
            }
            switch (action) {
                case MotionEvent.ACTION_DOWN:
                    pinching = false;
                    startX = Math.round(mapX);
                    startY = Math.round(mapY);
                    downX = event.getRawX();
                    downY = event.getRawY();
                    return true;
                case MotionEvent.ACTION_MOVE:
                    if (pinching) return true;
                    mapX = startX + (int) (event.getRawX() - downX);
                    mapY = startY + (int) (event.getRawY() - downY);
                    if (radarView != null) radarView.setMapOffset(mapX, mapY);
                    return true;
                case MotionEvent.ACTION_POINTER_UP:
                case MotionEvent.ACTION_UP:
                case MotionEvent.ACTION_CANCEL:
                    pinching = false;
                    startPinchDistance = 0;
                    saveOverlayBounds();
                    saveAdjustPrefs();
                    return true;
                default:
                    return false;
            }
        }

        private float pointerDistance(MotionEvent event) {
            float dx = event.getRawX(0) - event.getRawX(1);
            float dy = event.getRawY(0) - event.getRawY(1);
            return (float) Math.sqrt(dx * dx + dy * dy);
        }

    }

    private class PanelDragTouchListener implements View.OnTouchListener {
        private int startX;
        private int startY;
        private float downX;
        private float downY;
        private boolean dragging;

        @Override
        public boolean onTouch(View v, MotionEvent event) {
            if (panelParams == null || windowManager == null || adjustPanel == null) return false;
            switch (event.getActionMasked()) {
                case MotionEvent.ACTION_DOWN:
                    startX = panelParams.x;
                    startY = panelParams.y;
                    downX = event.getRawX();
                    downY = event.getRawY();
                    dragging = false;
                    return true;
                case MotionEvent.ACTION_MOVE:
                    int dx = (int) (event.getRawX() - downX);
                    int dy = (int) (event.getRawY() - downY);
                    if (!dragging && Math.abs(dx) < dp(4) && Math.abs(dy) < dp(4)) return true;
                    dragging = true;
                    int sw = getResources().getDisplayMetrics().widthPixels;
                    int sh = getResources().getDisplayMetrics().heightPixels;
                    int maxX = Math.max(0, (sw - panelParams.width) / 2);
                    int maxY = Math.max(0, (sh - panelParams.height) / 2);
                    panelParams.x = clamp(startX + dx, -maxX, maxX);
                    panelParams.y = clamp(startY + dy, -maxY, maxY);
                    windowManager.updateViewLayout(adjustPanel, panelParams);
                    return true;
                case MotionEvent.ACTION_UP:
                case MotionEvent.ACTION_CANCEL:
                    if (dragging) savePanelPosition();
                    return dragging;
                default:
                    return false;
            }
        }
    }
}
