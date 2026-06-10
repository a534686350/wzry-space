package com.qy.wzryoverlay;

import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Handler;
import android.os.Looper;

import java.io.InputStream;
import java.util.HashMap;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;

public class HeroIconCache {
    private final OkHttpClient client;
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final Map<String, Bitmap> cache = new HashMap<>();
    private final Set<String> loading = new HashSet<>();

    public HeroIconCache(OkHttpClient client) {
        this.client = client;
    }

    public Bitmap get(String heroId, Runnable onLoaded) {
        if (heroId == null || heroId.length() == 0) return null;
        Bitmap bitmap = cache.get(heroId);
        if (bitmap != null) return bitmap;
        if (loading.contains(heroId)) return null;
        loading.add(heroId);
        loadCandidate(heroId, 0, onLoaded);
        return null;
    }

    public Bitmap getSummoner(int skillId, Runnable onLoaded) {
        if (skillId <= 0) return null;
        String key = "summoner_" + skillId;
        Bitmap bitmap = cache.get(key);
        if (bitmap != null) return bitmap;
        if (loading.contains(key)) return null;
        loading.add(key);
        Request request = new Request.Builder().url("https://game.gtimg.cn/images/yxzj/img201606/summoner/" + skillId + ".jpg").build();
        client.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                mainHandler.post(() -> loading.remove(key));
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    if (!response.isSuccessful() || response.body() == null) return;
                    Bitmap bitmap = BitmapFactory.decodeStream(response.body().byteStream());
                    if (bitmap == null) return;
                    mainHandler.post(() -> {
                        cache.put(key, bitmap);
                        loading.remove(key);
                        if (onLoaded != null) onLoaded.run();
                    });
                } finally {
                    response.close();
                    mainHandler.post(() -> loading.remove(key));
                }
            }
        });
        return null;
    }

    private void loadCandidate(String heroId, int index, Runnable onLoaded) {
        String[] urls = urlsFor(heroId);
        if (index >= urls.length) {
            loading.remove(heroId);
            return;
        }
        Request request = new Request.Builder().url(urls[index]).build();
        client.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                mainHandler.post(() -> loadCandidate(heroId, index + 1, onLoaded));
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    if (!response.isSuccessful() || response.body() == null) {
                        mainHandler.post(() -> loadCandidate(heroId, index + 1, onLoaded));
                        return;
                    }
                    InputStream in = response.body().byteStream();
                    Bitmap bitmap = BitmapFactory.decodeStream(in);
                    if (bitmap == null) {
                        mainHandler.post(() -> loadCandidate(heroId, index + 1, onLoaded));
                        return;
                    }
                    mainHandler.post(() -> {
                        cache.put(heroId, bitmap);
                        loading.remove(heroId);
                        if (onLoaded != null) onLoaded.run();
                    });
                } finally {
                    response.close();
                }
            }
        });
    }

    private String[] urlsFor(String id) {
        String base = "https://game.gtimg.cn/images/yxzj/img201606/heroimg/" + id + "/";
        if ("188".equals(id)) {
            return new String[]{base + "18803.png", base + "188.jpg", base + "188.png"};
        }
        if ("581".equals(id)) {
            return new String[]{base + "58107.png", base + "581.jpg", base + "581.png"};
        }
        return new String[]{base + id + ".jpg", base + id + ".png"};
    }
}
