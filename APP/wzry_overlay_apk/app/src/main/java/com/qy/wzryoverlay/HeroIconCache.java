package com.qy.wzryoverlay;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.RectF;
import android.graphics.Shader;
import android.os.Handler;
import android.os.Looper;

import java.io.InputStream;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Set;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;

public class HeroIconCache {
    private static final long FAILED_RETRY_DELAY_MS = 60_000L;
    private static final int MAX_CACHE_ITEMS = 260;
    private static final int MAX_ICON_SIZE_PX = 128;

    private final Context context;
    private final OkHttpClient client;
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final Map<String, Bitmap> cache = new LinkedHashMap<String, Bitmap>(MAX_CACHE_ITEMS, 0.75f, true) {
        @Override
        protected boolean removeEldestEntry(Map.Entry<String, Bitmap> eldest) {
            return size() > MAX_CACHE_ITEMS;
        }
    };
    private final Set<String> loading = new HashSet<>();
    private final Map<String, Long> failedUntil = new HashMap<>();

    public HeroIconCache(Context context, OkHttpClient client) {
        this.context = context.getApplicationContext();
        this.client = client;
    }

    public Bitmap get(String heroId, Runnable onLoaded) {
        if (heroId == null || heroId.length() == 0) return null;
        Bitmap bitmap = cache.get(heroId);
        if (bitmap != null) return bitmap;
        bitmap = loadBundledHeroIcon(heroId);
        if (bitmap != null) {
            cache.put(heroId, bitmap);
            return bitmap;
        }
        if (isCoolingDown(heroId)) return null;
        if (loading.contains(heroId)) return null;
        loading.add(heroId);
        loadUrlCandidate(heroId, urlsForHero(heroId), 0, onLoaded);
        return null;
    }

    public Bitmap getSummoner(int skillId, Runnable onLoaded) {
        if (skillId <= 0) return null;
        String key = "summoner_" + canonicalSummonerId(skillId);
        Bitmap bitmap = cache.get(key);
        if (bitmap != null) return bitmap;
        bitmap = loadBundledSummonerIcon(skillId);
        if (bitmap != null) {
            cache.put(key, bitmap);
            return bitmap;
        }
        if (isCoolingDown(key)) return null;
        if (loading.contains(key)) return null;
        loading.add(key);
        loadUrlCandidate(key, summonerUrlsFor(skillId), 0, onLoaded);
        return null;
    }

    public Bitmap getSummonerPlaceholder() {
        String key = "summoner_placeholder";
        Bitmap bitmap = cache.get(key);
        if (bitmap != null) return bitmap;
        bitmap = createSummonerPlaceholder();
        cache.put(key, bitmap);
        return bitmap;
    }

    public Bitmap getUlt(String heroId, Runnable onLoaded) {
        if (heroId == null || heroId.length() == 0) return null;
        String key = "ult_" + heroId;
        Bitmap bitmap = cache.get(key);
        if (bitmap != null) return bitmap;
        bitmap = loadBundledUltIcon(heroId);
        if (bitmap != null) {
            cache.put(key, bitmap);
            return bitmap;
        }
        if (isCoolingDown(key)) return null;
        if (loading.contains(key)) return null;
        loading.add(key);
        loadUrlCandidate(key, ultUrlsFor(heroId), 0, onLoaded);
        return null;
    }

    private void loadUrlCandidate(String key, String[] urls, int index, Runnable onLoaded) {
        if (index >= urls.length) {
            markFailed(key);
            return;
        }
        Request request = new Request.Builder().url(urls[index]).build();
        client.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                mainHandler.post(() -> loadUrlCandidate(key, urls, index + 1, onLoaded));
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    if (!response.isSuccessful() || response.body() == null) {
                        mainHandler.post(() -> loadUrlCandidate(key, urls, index + 1, onLoaded));
                        return;
                    }
                    Bitmap bitmap = decodeSmall(response.body().byteStream());
                    if (bitmap == null) {
                        mainHandler.post(() -> loadUrlCandidate(key, urls, index + 1, onLoaded));
                        return;
                    }
                    mainHandler.post(() -> {
                        cache.put(key, bitmap);
                        failedUntil.remove(key);
                        loading.remove(key);
                        if (onLoaded != null) onLoaded.run();
                    });
                } finally {
                    response.close();
                }
            }
        });
    }

    private boolean isCoolingDown(String key) {
        Long until = failedUntil.get(key);
        if (until == null) return false;
        if (System.currentTimeMillis() < until) return true;
        failedUntil.remove(key);
        return false;
    }

    private void markFailed(String key) {
        loading.remove(key);
        failedUntil.put(key, System.currentTimeMillis() + FAILED_RETRY_DELAY_MS);
    }

    private Bitmap decodeSmall(InputStream in) {
        Bitmap bitmap = BitmapFactory.decodeStream(in);
        if (bitmap == null) return null;
        int width = bitmap.getWidth();
        int height = bitmap.getHeight();
        int maxSide = Math.max(width, height);
        if (maxSide <= MAX_ICON_SIZE_PX) return bitmap;
        float scale = MAX_ICON_SIZE_PX / (float) maxSide;
        int targetW = Math.max(1, Math.round(width * scale));
        int targetH = Math.max(1, Math.round(height * scale));
        Bitmap scaled = Bitmap.createScaledBitmap(bitmap, targetW, targetH, true);
        if (scaled != bitmap) bitmap.recycle();
        return scaled;
    }

    private Bitmap createSummonerPlaceholder() {
        int size = MAX_ICON_SIZE_PX;
        Bitmap bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(bitmap);
        Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
        p.setShader(new LinearGradient(0, 0, size, size, 0xff475569, 0xff0f172a, Shader.TileMode.CLAMP));
        RectF rect = new RectF(0, 0, size, size);
        canvas.drawRoundRect(rect, size * 0.2f, size * 0.2f, p);
        p.setShader(null);
        p.setStyle(Paint.Style.FILL);
        p.setColor(0x3356ccf2);
        canvas.drawCircle(size * 0.5f, size * 0.5f, size * 0.28f, p);
        p.setStyle(Paint.Style.STROKE);
        p.setStrokeWidth(size * 0.06f);
        p.setColor(0xffdbeafe);
        canvas.drawCircle(size * 0.5f, size * 0.5f, size * 0.2f, p);
        p.setStyle(Paint.Style.FILL);
        p.setColor(0xffdbeafe);
        p.setTextAlign(Paint.Align.CENTER);
        p.setTextSize(size * 0.42f);
        p.setFakeBoldText(true);
        Paint.FontMetrics fm = p.getFontMetrics();
        canvas.drawText("?", size * 0.5f, size * 0.5f - (fm.ascent + fm.descent) / 2f, p);
        return bitmap;
    }

    private Bitmap loadBundledHeroIcon(String heroId) {
        return loadFirstBundled(new String[]{"hero_icons/" + heroId + ".jpg", "hero_icons/" + heroId + ".png"});
    }

    private Bitmap loadBundledSummonerIcon(int skillId) {
        int[] ids = summonerIdChain(skillId);
        String[] names = new String[ids.length * 2];
        int index = 0;
        for (int id : ids) {
            int assetId = canonicalSummonerId(id);
            names[index++] = "summoner_icons/" + assetId + ".jpg";
            names[index++] = "summoner_icons/" + assetId + ".png";
        }
        return loadFirstBundled(names);
    }

    private Bitmap loadBundledUltIcon(String heroId) {
        String[] suffixes = ultSuffixChain(heroId);
        String[] names = new String[suffixes.length + 2];
        int index = 0;
        for (String suffix : suffixes) {
            names[index++] = "skill_icons/" + heroId + suffix + ".png";
        }
        names[index++] = "skill_icons/" + heroId + ".png";
        names[index] = "skill_icons/" + heroId + ".jpg";
        return loadFirstBundled(names);
    }

    private Bitmap loadFirstBundled(String[] names) {
        for (String name : names) {
            try (InputStream in = context.getAssets().open(name)) {
                Bitmap bitmap = decodeSmall(in);
                if (bitmap != null) return bitmap;
            } catch (Exception ignored) {
            }
        }
        return null;
    }

    private String[] urlsForHero(String id) {
        String base = "https://game.gtimg.cn/images/yxzj/img201606/heroimg/" + id + "/";
        if ("188".equals(id)) {
            return new String[]{base + "18803.png", base + "188.jpg", base + "188.png"};
        }
        if ("581".equals(id)) {
            return new String[]{base + "58107.png", base + "581.jpg", base + "581.png"};
        }
        return new String[]{base + id + ".jpg", base + id + ".png"};
    }

    private String[] summonerUrlsFor(int skillId) {
        int[] ids = summonerIdChain(skillId);
        String[] urls = new String[ids.length * 3];
        int index = 0;
        for (int id : ids) {
            int assetId = canonicalSummonerId(id);
            String sid = String.valueOf(assetId);
            urls[index++] = "https://game.gtimg.cn/images/yxzj/img201606/summoner/" + sid + ".jpg";
            urls[index++] = "https://game.gtimg.cn/images/yxzj/img201606/summoner/" + sid + ".png";
            urls[index++] = "https://game.gtimg.cn/images/yxzj/img201606/summonero/" + sid + ".png";
        }
        return urls;
    }

    private String[] ultUrlsFor(String heroId) {
        String base = "https://game.gtimg.cn/images/yxzj/img201606/heroimg/" + heroId + "/";
        if ("188".equals(heroId)) return new String[]{base + "18803.png"};
        if ("581".equals(heroId)) return new String[]{base + "58107.png"};
        String[] suffixes = ultSuffixChain(heroId);
        String[] urls = new String[suffixes.length];
        for (int i = 0; i < suffixes.length; i++) {
            urls[i] = base + heroId + suffixes[i] + ".png";
        }
        return urls;
    }

    private int[] summonerIdChain(int skillId) {
        if (skillId == 80116) return new int[]{80116, 80104};
        if (skillId == 80117) return new int[]{80117, 80118};
        return new int[]{skillId};
    }

    private int canonicalSummonerId(int skillId) {
        if (skillId == 80116) return 80104;
        if (skillId == 80117) return 80118;
        return skillId;
    }

    private String[] ultSuffixChain(String heroId) {
        String preferred = forcedUltSuffix(heroId);
        String[] fallback = new String[]{preferred, "40", "30", "20", "10", "00", "32", "50", "60", "70", "80", "90"};
        String[] unique = new String[fallback.length];
        int count = 0;
        for (String suffix : fallback) {
            if (suffix == null || suffix.length() != 2) continue;
            boolean seen = false;
            for (int i = 0; i < count; i++) {
                if (suffix.equals(unique[i])) {
                    seen = true;
                    break;
                }
            }
            if (!seen) unique[count++] = suffix;
        }
        String[] out = new String[count];
        System.arraycopy(unique, 0, out, 0, count);
        return out;
    }

    private String forcedUltSuffix(String heroId) {
        if ("188".equals(heroId)) return "03";
        if ("581".equals(heroId)) return "07";
        if ("125".equals(heroId) || "170".equals(heroId) || "179".equals(heroId) || "182".equals(heroId)
                || "191".equals(heroId) || "531".equals(heroId) || "502".equals(heroId)
                || "507".equals(heroId) || "509".equals(heroId) || "517".equals(heroId)
                || "529".equals(heroId) || "540".equals(heroId)) {
            return "40";
        }
        return "30";
    }
}
