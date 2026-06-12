package com.qy.wzryoverlay;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.res.ColorStateList;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.ColorFilter;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.PixelFormat;
import android.graphics.RectF;
import android.graphics.Shader;
import android.graphics.Typeface;
import android.graphics.drawable.Drawable;
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
    private static final int MIN_POLL_DELAY_MS = 33;
    private static final int MIN_UI_FRAME_MS = 33;

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
    private final Object radarDataLock = new Object();
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
    private boolean showZeroSkillCd = false;
    private float panelAlpha = 0.88f;
    private int minionLaneRotationSteps;
    private int overlaySizePx;
    private int activeMainPage = 1;
    private int activeDrawTab = 0;
    private Button panelRoomButton;
    private RadarData pendingRadarData;
    private boolean radarFramePosted;
    private volatile long lastRadarFrameAt;

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
        frameDelayMs = Math.max(MIN_POLL_DELAY_MS, 1000 / Math.max(1, fps));
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
        radarView.setHeroIconCache(new HeroIconCache(this, client));
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
                overlayWindowFlags(false),
                PixelFormat.TRANSLUCENT);
        applyCutoutMode(params);
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
        scheduleRadarUpdate(parsed);
    }

    private void scheduleRadarUpdate(RadarData parsed) {
        if (parsed == null) return;
        synchronized (radarDataLock) {
            pendingRadarData = parsed;
            if (radarFramePosted) return;
            radarFramePosted = true;
        }
        long now = System.currentTimeMillis();
        long delay = Math.max(0, MIN_UI_FRAME_MS - (now - lastRadarFrameAt));
        handler.postDelayed(this::flushRadarUpdate, delay);
    }

    private void flushRadarUpdate() {
        RadarData latest;
        synchronized (radarDataLock) {
            latest = pendingRadarData;
            pendingRadarData = null;
            radarFramePosted = false;
        }
        if (latest != null && radarView != null) {
            lastRadarFrameAt = System.currentTimeMillis();
            radarView.setData(latest);
        }
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
        synchronized (radarDataLock) {
            pendingRadarData = null;
            radarFramePosted = false;
        }
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
        applyEstimatedFitResult(estimatedMiniMapSize());
    }

    private int estimatedMiniMapSize() {
        int sw = getResources().getDisplayMetrics().widthPixels;
        int sh = getResources().getDisplayMetrics().heightPixels;
        int longSide = Math.max(sw, sh);
        int shortSide = Math.min(sw, sh);
        if (longSide >= 2400) {
            return (int) (shortSide * 0.30f);
        }
        if (longSide >= 2160) {
            return (int) (shortSide * 0.29f);
        }
        if (longSide >= 1920) {
            return (int) (shortSide * 0.28f);
        }
        return (int) (shortSide * 0.27f);
    }

    private void applyEstimatedFitResult(int mapSizePx) {
        mapSizePx = clamp(mapSizePx, dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
        int screenH = getResources().getDisplayMetrics().heightPixels;
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        int left = prefs.contains("map_x") ? Math.round(prefs.getFloat("map_x", 0f)) : 0;
        int top = prefs.contains("map_y") ? Math.round(prefs.getFloat("map_y", 0f) + ((screenH - mapSizePx) / 2f)) : 0;
        applyAutoFitBounds(left, top, mapSizePx);
    }

    private void applyAutoFitBounds(int left, int top, int mapSizePx) {
        int screenW = getResources().getDisplayMetrics().widthPixels;
        int screenH = getResources().getDisplayMetrics().heightPixels;
        mapSizePx = clamp(mapSizePx, dp(MIN_OVERLAY_DP), dp(MAX_OVERLAY_DP));
        left = clamp(left, 0, Math.max(0, screenW - mapSizePx));
        top = clamp(top, 0, Math.max(0, screenH - mapSizePx));

        mapX = left;
        mapY = top - ((screenH - mapSizePx) / 2f);
        mapScale = 1f;
        heroX = heroY = 0f;
        minionX = minionY = 0f;
        monsterX = monsterY = 0f;
        skillX = left;
        skillY = Math.max(0, top - dp(4));
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
                new Thread(() -> {
                    MiniMapBounds detected = detectMiniMapBounds(pixels, imgW, imgH);
                    handler.post(() -> {
                        restoreOverlayViews();
                        if (detected != null && detected.size > dp(40)) {
                            applyAutoFitBounds(detected.left, detected.top, detected.size);
                            if (radarView != null) {
                                radarView.setStatus("识别小地图 " + detected.left + "," + detected.top + " " + detected.size + "px");
                            }
                        } else {
                            applyEstimatedFitResult(estimatedMiniMapSize());
                            if (radarView != null) radarView.setStatus("未识别到小地图,已按屏幕估算");
                        }
                    });
                }, "radar-map-detect").start();
            } catch (Exception e) {
                if (image != null) image.close();
                display.release();
                reader.close();
                projection.stop();
                handler.post(() -> {
                    restoreOverlayViews();
                    applyEstimatedFitResult(estimatedMiniMapSize());
                    if (radarView != null) radarView.setStatus("截屏识别失败,已按屏幕估算");
                });
            }
        }, handler);
    }

    private MiniMapBounds detectMiniMapBounds(int[] pixels, int w, int h) {
        if (pixels == null || pixels.length < w * h || w <= 0 || h <= 0) return null;
        int shortSide = Math.min(w, h);
        int minSize = clamp(Math.round(shortSide * 0.18f), dp(MIN_OVERLAY_DP), Math.min(shortSide, dp(MAX_OVERLAY_DP)));
        int maxSize = clamp(Math.round(shortSide * 0.42f), minSize, Math.min(shortSide, dp(MAX_OVERLAY_DP)));
        int sizeStep = Math.max(14, shortSide / 18);
        MiniMapBounds best = null;

        for (int size = minSize; size <= maxSize; size += sizeStep) {
            int posStep = Math.max(14, size / 7);
            best = scanMiniMapCandidates(pixels, w, h, size, posStep, 0, 0, w - size, h - size, best);
        }
        if (best == null) return null;

        int refineRange = Math.max(16, best.size / 4);
        int refineMinSize = clamp(best.size - refineRange, minSize, maxSize);
        int refineMaxSize = clamp(best.size + refineRange, refineMinSize, maxSize);
        int refineStep = Math.max(4, best.size / 28);
        for (int size = refineMinSize; size <= refineMaxSize; size += refineStep) {
            int minX = clamp(best.left - refineRange, 0, Math.max(0, w - size));
            int maxX = clamp(best.left + refineRange, minX, Math.max(0, w - size));
            int minY = clamp(best.top - refineRange, 0, Math.max(0, h - size));
            int maxY = clamp(best.top + refineRange, minY, Math.max(0, h - size));
            best = scanMiniMapCandidates(pixels, w, h, size, refineStep, minX, minY, maxX, maxY, best);
        }
        return best != null && best.score >= 34f ? best : null;
    }

    private MiniMapBounds scanMiniMapCandidates(int[] pixels, int w, int h, int size, int step,
                                                int minX, int minY, int maxX, int maxY, MiniMapBounds best) {
        if (size <= 0 || maxX < minX || maxY < minY) return best;
        for (int y = minY; y <= maxY; y += step) {
            for (int x = minX; x <= maxX; x += step) {
                float score = scoreMiniMapCandidate(pixels, w, h, x, y, size);
                if (best == null || score > best.score) {
                    best = new MiniMapBounds(x, y, size, score);
                }
            }
        }
        return best;
    }

    private float scoreMiniMapCandidate(int[] pixels, int w, int h, int left, int top, int size) {
        int sampleStep = Math.max(4, size / 24);
        int borderSamples = 0;
        int darkBorder = 0;
        float borderBrightness = 0f;
        for (int i = 0; i <= size; i += sampleStep) {
            int x = Math.min(w - 1, left + i);
            int y = Math.min(h - 1, top + i);
            int topPx = pixels[top * w + x];
            int bottomPx = pixels[(top + size - 1) * w + x];
            int leftPx = pixels[y * w + left];
            int rightPx = pixels[y * w + left + size - 1];
            borderBrightness += brightness(topPx) + brightness(bottomPx) + brightness(leftPx) + brightness(rightPx);
            darkBorder += isMiniMapDark(topPx) ? 1 : 0;
            darkBorder += isMiniMapDark(bottomPx) ? 1 : 0;
            darkBorder += isMiniMapDark(leftPx) ? 1 : 0;
            darkBorder += isMiniMapDark(rightPx) ? 1 : 0;
            borderSamples += 4;
        }
        if (borderSamples == 0) return 0f;

        int insideSamples = 0;
        int darkInside = 0;
        float insideSum = 0f;
        float insideSqSum = 0f;
        int grid = 7;
        for (int gy = 1; gy < grid; gy++) {
            int y = top + Math.round(size * (gy / (float) grid));
            for (int gx = 1; gx < grid; gx++) {
                int x = left + Math.round(size * (gx / (float) grid));
                int px = pixels[y * w + x];
                float b = brightness(px);
                insideSum += b;
                insideSqSum += b * b;
                if (isMiniMapDark(px)) darkInside++;
                insideSamples++;
            }
        }
        if (insideSamples == 0) return 0f;

        float borderDarkRatio = darkBorder / (float) borderSamples;
        float insideDarkRatio = darkInside / (float) insideSamples;
        float insideAvg = insideSum / insideSamples;
        float variance = Math.max(0f, insideSqSum / insideSamples - insideAvg * insideAvg);
        float textureScore = clamp((variance - 180f) / 1800f, 0f, 1f);
        float balancedInside = clamp(1f - Math.abs(insideDarkRatio - 0.55f) / 0.55f, 0f, 1f);
        float contrastScore = outsideContrastScore(pixels, w, h, left, top, size, borderBrightness / borderSamples);
        float positionScore = 1f - Math.min(1f, (left / (float) w) * 0.35f + (top / (float) h) * 0.45f);

        float score = borderDarkRatio * 43f
                + balancedInside * 20f
                + textureScore * 24f
                + contrastScore * 9f
                + positionScore * 4f;
        if (borderDarkRatio < 0.24f || insideDarkRatio < 0.12f) score -= 24f;
        if (insideDarkRatio > 0.94f && textureScore < 0.18f) score -= 34f;
        return score;
    }

    private float outsideContrastScore(int[] pixels, int w, int h, int left, int top, int size, float borderAvg) {
        int offset = Math.max(3, size / 26);
        int sampleStep = Math.max(6, size / 14);
        float sum = 0f;
        int count = 0;
        for (int i = 0; i <= size; i += sampleStep) {
            int x = clamp(left + i, 0, w - 1);
            int y = clamp(top + i, 0, h - 1);
            if (top - offset >= 0) { sum += brightness(pixels[(top - offset) * w + x]); count++; }
            if (top + size + offset < h) { sum += brightness(pixels[(top + size + offset) * w + x]); count++; }
            if (left - offset >= 0) { sum += brightness(pixels[y * w + left - offset]); count++; }
            if (left + size + offset < w) { sum += brightness(pixels[y * w + left + size + offset]); count++; }
        }
        if (count == 0) return 0f;
        return clamp(Math.abs((sum / count) - borderAvg) / 90f, 0f, 1f);
    }

    private boolean isMiniMapDark(int px) {
        return brightness(px) < 118f;
    }

    private float brightness(int px) {
        int r = (px >> 16) & 0xff;
        int g = (px >> 8) & 0xff;
        int b = px & 0xff;
        return 0.299f * r + 0.587f * g + 0.114f * b;
    }

    private static class MiniMapBounds {
        final int left;
        final int top;
        final int size;
        final float score;

        MiniMapBounds(int left, int top, int size, float score) {
            this.left = left;
            this.top = top;
            this.size = size;
            this.score = score;
        }
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
        if (!prefs.contains("map_x") && prefs.contains("overlay_x")) mapX = prefs.getInt("overlay_x", 0);
        if (!prefs.contains("map_y") && prefs.contains("overlay_y")) mapY = prefs.getInt("overlay_y", 0);
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
        showZeroSkillCd = prefs.getBoolean("show_zero_skill_cd", false);
        panelAlpha = clamp(prefs.getFloat("adjust_panel_alpha", 0.88f), 0.45f, 1f);
        minionLaneRotationSteps = prefs.getInt("minion_lane_rotation_steps", 0);
        activeMainPage = prefs.getInt("adjust_main_page", activeMainPage);
        activeDrawTab = prefs.getInt("adjust_draw_tab", activeDrawTab);
        if (activeMainPage < 0 || activeMainPage > 2) activeMainPage = 1;
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
                .putFloat("adjust_panel_alpha", panelAlpha)
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
            toggleButton.setBackground(makePanelHeaderBackground());
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
                ? clamp((int) (sw * 0.30f), dp(300), dp(460))
                : clamp((int) (sw * 0.84f), dp(270), dp(360));
        panelParams.height = landscape
                ? clamp((int) (sh * 0.62f), dp(210), dp(330))
                : clamp((int) (sh * 0.42f), dp(240), dp(420));
        int maxX = Math.max(0, (sw - panelParams.width) / 2);
        int maxY = Math.max(0, (sh - panelParams.height) / 2);
        panelParams.x = clamp(prefs.getInt("adjust_panel_x", 0), -maxX, maxX);
        panelParams.y = clamp(prefs.getInt("adjust_panel_y", 0), -maxY, maxY);
        applyPanelAlpha();
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
        int flags = overlayWindowFlags(!adjustMode || !panelVisible);
        if (params.flags != flags) {
            params.flags = flags;
            windowManager.updateViewLayout(radarView, params);
        }
    }

    private LinearLayout buildAdjustPanel() {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(7), dp(6), dp(7), dp(7));
        root.setBackground(new PocketPanelDrawable(dp(18)));
        root.setAlpha(panelAlpha);

        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);
        top.setOnTouchListener(new PanelDragTouchListener());
        top.setPadding(dp(6), 0, dp(5), 0);
        top.setBackground(makePanelHeaderBackground());
        TextView title = panelText("雷达面板", 13, true);
        title.setTextColor(0xffffffff);
        top.addView(title, new LinearLayout.LayoutParams(0, dp(34), 1));
        Button save = panelButton("保存");
        save.setOnClickListener(v -> saveAdjustPrefs());
        Button hide = panelButton("收起");
        hide.setOnClickListener(v -> hidePanelOnly());
        top.addView(save, new LinearLayout.LayoutParams(dp(48), dp(27)));
        top.addView(hide, new LinearLayout.LayoutParams(dp(48), dp(27)));
        root.addView(top, new LinearLayout.LayoutParams(-1, dp(34)));

        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.HORIZONTAL);

        LinearLayout nav = new LinearLayout(this);
        nav.setOrientation(LinearLayout.VERTICAL);
        nav.setPadding(dp(3), dp(4), dp(3), dp(4));
        nav.setBackground(makePanelNavBackground());
        String[] names = new String[]{"房间", "绘制", "面板"};
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
            LinearLayout.LayoutParams rowLp = new LinearLayout.LayoutParams(-1, dp(29));
            rowLp.bottomMargin = dp(5);
            nav.addView(row, rowLp);
            mainNavButtons[i] = row;
        }
        content.addView(nav, new LinearLayout.LayoutParams(dp(58), -1));

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(false);
        scroll.setBackground(makePanelContentBackground());
        panelContent = new LinearLayout(this);
        panelContent.setOrientation(LinearLayout.VERTICAL);
        panelContent.setPadding(dp(7), dp(6), dp(7), dp(6));
        scroll.addView(panelContent, new ScrollView.LayoutParams(-1, -2));
        LinearLayout.LayoutParams ctlLp = new LinearLayout.LayoutParams(0, -1, 1);
        ctlLp.leftMargin = dp(7);
        ctlLp.topMargin = dp(7);
        content.addView(scroll, ctlLp);
        LinearLayout.LayoutParams contentLp = new LinearLayout.LayoutParams(-1, 0, 1);
        contentLp.topMargin = dp(7);
        root.addView(content, contentLp);
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
            panelContent.addView(panelText("房间", 13, true), new LinearLayout.LayoutParams(-1, dp(24)));
            renderPanelRoomChooser();
            addPanelAction(panelContent, "一键适配", this::autoFitResolution);
        } else if (activeMainPage == 1) {
            renderGroupedSettingPage();
        } else {
            panelContent.addView(panelText("面板", 13, true), new LinearLayout.LayoutParams(-1, dp(24)));
            addPanelAlphaRow(panelContent);
            addPanelAction(panelContent, "面板居中", this::centerAdjustPanel);
            addPanelAction(panelContent, "收起面板", this::hidePanelOnly);
            addPanelAction(panelContent, "显示调节", () -> {
                panelVisible = true;
                if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
                prefs.edit().putBoolean("adjust_panel_visible", true).apply();
                updatePanelVisibility();
                updateRadarTouchMode();
            });
            addPanelAction(panelContent, "退出调节", () -> {
                adjustMode = false;
                if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
                prefs.edit().putBoolean("overlay_adjust_mode", false).apply();
                hideAdjustControls();
                updateRadarTouchMode();
            });
            addPanelAction(panelContent, "关闭悬浮窗", () -> {
                running = false;
                stopSelf();
            });
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
        roomCountText.setBackground(makePanelButtonBackground(false));
        header.addView(panelRoomButton, new LinearLayout.LayoutParams(0, dp(36), 1));
        LinearLayout.LayoutParams countLp = new LinearLayout.LayoutParams(dp(54), dp(36));
        countLp.leftMargin = dp(6);
        header.addView(roomCountText, countLp);
        panelContent.addView(header, new LinearLayout.LayoutParams(-1, dp(38)));

        ScrollView roomScroll = new ScrollView(this);
        roomScroll.setFillViewport(false);
        roomScroll.setNestedScrollingEnabled(true);
        roomScroll.setBackground(makePanelNavBackground());
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
                room.setTextColor(selected ? 0xffffffff : panelTextColor());
                room.setBackground(makePanelButtonBackground(selected));
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
                mainNavButtons[i].setTextColor(i == activeMainPage ? 0xffffffff : panelTextColor());
                mainNavButtons[i].setBackground(makePanelButtonBackground(i == activeMainPage));
            }
        }
        if (tabButtons != null) {
            for (int i = 0; i < tabButtons.length; i++) {
                tabButtons[i].setTextColor(i == activeDrawTab ? 0xffffffff : panelTextColor());
                tabButtons[i].setBackground(makePanelButtonBackground(i == activeDrawTab));
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

    private void addPanelAlphaRow(LinearLayout parent) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        TextView value = panelText(Math.round(panelAlpha * 100) + "%", 11, false);
        value.setGravity(Gravity.CENTER);
        SeekBar bar = new SeekBar(this);
        stylePanelSeekBar(bar);
        final int min = 45;
        final int max = 100;
        final int range = max - min;
        bar.setMax(range);
        bar.setProgress(clamp(Math.round(panelAlpha * 100) - min, 0, range));
        Runnable applyAlpha = () -> {
            int next = min + bar.getProgress();
            panelAlpha = clamp(next / 100f, 0.45f, 1f);
            value.setText(next + "%");
            applyPanelAlpha();
            if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
            prefs.edit().putFloat("adjust_panel_alpha", panelAlpha).apply();
        };
        bar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override public void onProgressChanged(SeekBar seekBar, int progress, boolean fromUser) { applyAlpha.run(); }
            @Override public void onStartTrackingTouch(SeekBar seekBar) {}
            @Override public void onStopTrackingTouch(SeekBar seekBar) {}
        });
        Button minus = panelButton("-");
        minus.setOnClickListener(v -> { bar.setProgress(Math.max(0, bar.getProgress() - 5)); applyAlpha.run(); });
        Button plus = panelButton("+");
        plus.setOnClickListener(v -> { bar.setProgress(Math.min(range, bar.getProgress() + 5)); applyAlpha.run(); });
        row.addView(panelText("Alpha", 10, false), new LinearLayout.LayoutParams(dp(52), dp(36)));
        row.addView(minus, new LinearLayout.LayoutParams(dp(26), dp(26)));
        row.addView(bar, new LinearLayout.LayoutParams(0, dp(36), 1));
        row.addView(plus, new LinearLayout.LayoutParams(dp(26), dp(26)));
        row.addView(value, new LinearLayout.LayoutParams(dp(44), dp(36)));
        parent.addView(row, new LinearLayout.LayoutParams(-1, dp(38)));
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
            bar.setProgressBackgroundTintList(ColorStateList.valueOf(0x55b6eaff));
            bar.setThumbTintList(ColorStateList.valueOf(0xffffd84d));
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
        button.setBackground(makePanelButtonBackground(false));
        return button;
    }

    private int panelTheme() {
        if (prefs == null) prefs = getSharedPreferences("alin_radar", MODE_PRIVATE);
        return prefs.getInt("theme", 0);
    }

    private int panelBgColor() {
        return 0xeef8fdff;
    }

    private int panelInsetColor() {
        return 0xccdff7ff;
    }

    private int panelHeaderColor() {
        return 0xff0ea5e9;
    }

    private int panelContentColor() {
        return 0xdfffffff;
    }

    private int panelStrokeColor() {
        return 0xff38bdf8;
    }

    private int panelTextColor() {
        return 0xff073b5f;
    }

    private int panelButtonColor(boolean active) {
        return active ? 0xff0ea5e9 : 0xeef4fbff;
    }

    private int panelAccentColor() {
        return 0xff0284c7;
    }

    private GradientDrawable makePanelHeaderBackground() {
        GradientDrawable drawable = new GradientDrawable(
                GradientDrawable.Orientation.LEFT_RIGHT,
                new int[]{0xff0284c7, 0xff0ea5e9, 0xff38bdf8});
        drawable.setCornerRadius(dp(14));
        drawable.setStroke(dp(1), 0xffffffff);
        return drawable;
    }

    private GradientDrawable makePanelContentBackground() {
        GradientDrawable drawable = new GradientDrawable(
                GradientDrawable.Orientation.TOP_BOTTOM,
                new int[]{0xf7ffffff, 0xe9e0f7ff});
        drawable.setCornerRadius(dp(15));
        drawable.setStroke(dp(1), 0xcc7dd3fc);
        return drawable;
    }

    private GradientDrawable makePanelNavBackground() {
        GradientDrawable drawable = new GradientDrawable(
                GradientDrawable.Orientation.TOP_BOTTOM,
                new int[]{0xf2ffffff, 0xd7bae6fd});
        drawable.setCornerRadius(dp(15));
        drawable.setStroke(dp(1), 0xbb7dd3fc);
        return drawable;
    }

    private GradientDrawable makePanelButtonBackground(boolean active) {
        GradientDrawable drawable = new GradientDrawable(
                GradientDrawable.Orientation.LEFT_RIGHT,
                active
                        ? new int[]{0xff0284c7, 0xff38bdf8}
                        : new int[]{0xfaffffff, 0xe6e0f7ff});
        drawable.setCornerRadius(dp(999));
        drawable.setStroke(dp(1), active ? 0xffffffff : 0xaa7dd3fc);
        return drawable;
    }

    private GradientDrawable makeBox(int color, int strokeColor, int radius) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(color);
        drawable.setCornerRadius(radius);
        drawable.setStroke(dp(1), strokeColor);
        return drawable;
    }

    private void applyPanelAlpha() {
        if (adjustPanel != null) adjustPanel.setAlpha(panelAlpha);
    }

    private int overlayWindowFlags(boolean notTouchable) {
        int flags = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE
                | WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN
                | WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS;
        if (Build.VERSION.SDK_INT >= 19) {
            flags |= WindowManager.LayoutParams.FLAG_LAYOUT_INSET_DECOR;
        }
        if (notTouchable) flags |= WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE;
        return flags;
    }

    private void applyCutoutMode(WindowManager.LayoutParams layoutParams) {
        if (Build.VERSION.SDK_INT >= 28) {
            layoutParams.layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
        }
    }

    private static class PocketPanelDrawable extends Drawable {
        private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final RectF rect = new RectF();
        private final float radius;
        private int alpha = 255;

        PocketPanelDrawable(float radius) {
            this.radius = radius;
        }

        @Override
        public void draw(Canvas canvas) {
            rect.set(getBounds());
            paint.setAlpha(alpha);
            paint.setShader(new LinearGradient(
                    rect.left, rect.top, rect.right, rect.bottom,
                    new int[]{0xff0ea5e9, 0xff38bdf8, 0xffeffbff},
                    new float[]{0f, 0.48f, 1f},
                    Shader.TileMode.CLAMP));
            canvas.drawRoundRect(rect, radius, radius, paint);

            paint.setShader(null);
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(Math.max(2f, radius * 0.10f));
            paint.setColor(0xeaffffff);
            paint.setAlpha(alpha);
            canvas.drawRoundRect(inset(rect, 2f), radius * 0.92f, radius * 0.92f, paint);

            paint.setStyle(Paint.Style.FILL);
            paint.setColor(0xffff4b5f);
            paint.setAlpha(Math.round(alpha * 0.92f));
            float collarTop = rect.top + rect.height() * 0.16f;
            canvas.drawRoundRect(
                    new RectF(rect.left + rect.width() * 0.08f, collarTop,
                            rect.right - rect.width() * 0.08f, collarTop + Math.max(5f, rect.height() * 0.022f)),
                    radius * 0.55f, radius * 0.55f, paint);

            paint.setColor(0xeaffffff);
            paint.setAlpha(Math.round(alpha * 0.82f));
            RectF belly = new RectF(
                    rect.left + rect.width() * 0.20f,
                    rect.top + rect.height() * 0.26f,
                    rect.right - rect.width() * 0.10f,
                    rect.bottom - rect.height() * 0.08f);
            canvas.drawRoundRect(belly, radius * 1.18f, radius * 1.18f, paint);

            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(Math.max(2f, radius * 0.12f));
            paint.setColor(0xff25a9e0);
            paint.setAlpha(Math.round(alpha * 0.62f));
            RectF pocket = new RectF(
                    rect.left + rect.width() * 0.39f,
                    rect.top + rect.height() * 0.55f,
                    rect.right - rect.width() * 0.17f,
                    rect.bottom - rect.height() * 0.13f);
            canvas.drawArc(pocket, 0, 180, false, paint);
            canvas.drawLine(pocket.left, pocket.centerY(), pocket.right, pocket.centerY(), paint);

            paint.setStyle(Paint.Style.FILL);
            paint.setColor(0xffffd84d);
            paint.setAlpha(Math.round(alpha * 0.95f));
            float bellR = Math.max(5f, rect.height() * 0.035f);
            float bellCx = rect.left + rect.width() * 0.18f;
            float bellCy = collarTop + bellR * 1.05f;
            canvas.drawCircle(bellCx, bellCy, bellR, paint);
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(Math.max(1f, bellR * 0.16f));
            paint.setColor(0xffb45309);
            paint.setAlpha(Math.round(alpha * 0.75f));
            canvas.drawCircle(bellCx, bellCy, bellR * 0.82f, paint);
            canvas.drawLine(bellCx - bellR * 0.55f, bellCy - bellR * 0.12f, bellCx + bellR * 0.55f, bellCy - bellR * 0.12f, paint);

            paint.setStyle(Paint.Style.FILL);
            paint.setAlpha(Math.round(alpha * 0.70f));
            drawBubble(canvas, rect.left + rect.width() * 0.09f, rect.top + rect.height() * 0.33f, radius * 0.23f, 0xffffffff);
            drawBubble(canvas, rect.left + rect.width() * 0.89f, rect.top + rect.height() * 0.24f, radius * 0.18f, 0xffffffff);
            drawBubble(canvas, rect.left + rect.width() * 0.13f, rect.bottom - rect.height() * 0.16f, radius * 0.16f, 0xffffd84d);

            paint.setAlpha(255);
            paint.setShader(null);
            paint.setStyle(Paint.Style.FILL);
        }

        private void drawBubble(Canvas canvas, float cx, float cy, float r, int color) {
            paint.setColor(color);
            canvas.drawCircle(cx, cy, r, paint);
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(Math.max(1f, r * 0.20f));
            paint.setColor(0xaa0284c7);
            canvas.drawCircle(cx, cy, r * 0.78f, paint);
            paint.setStyle(Paint.Style.FILL);
        }

        private RectF inset(RectF source, float amount) {
            return new RectF(source.left + amount, source.top + amount, source.right - amount, source.bottom - amount);
        }

        @Override
        public void setAlpha(int alpha) {
            this.alpha = alpha;
            invalidateSelf();
        }

        @Override
        public void setColorFilter(ColorFilter colorFilter) {
            paint.setColorFilter(colorFilter);
            invalidateSelf();
        }

        @Override
        public int getOpacity() {
            return PixelFormat.TRANSLUCENT;
        }
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
