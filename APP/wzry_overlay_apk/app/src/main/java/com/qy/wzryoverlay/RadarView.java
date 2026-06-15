package com.qy.wzryoverlay;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Bitmap;
import android.graphics.BitmapShader;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RectF;
import android.graphics.Shader;
import android.graphics.Matrix;
import android.view.MotionEvent;
import android.view.View;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

public class RadarView extends View {
    private static final int MAX_STABLE_HEROES = 5;
    private static final int[] MONSTER_READY_CD_VALUES = {0, 60, 70, 90, 120, 240};

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint textPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private HeroIconCache iconCache;
    private RadarData data = new RadarData();
    private String status = "等待连接";
    private String roomId = "";
    private float heroOffsetX;
    private float heroOffsetY;
    private float minionOffsetX;
    private float minionOffsetY;
    private float monsterOffsetX;
    private float monsterOffsetY;
    private float mapOffsetX;
    private float mapOffsetY;
    private float skillOffsetX;
    private float skillOffsetY;
    private float mapScale = 1f;
    private float skillScale = 1f;
    private float skillGap = 0f;
    private float skillAvatarScale = 1f;
    private float heroIconScale = 0.72f;
    private float minionScale = 1f;
    private float monsterScale = 1f;
    private float overlaySizePx;
    private float lastPinchDistance;
    private int minionLaneRotationSteps;
    private boolean showSkillPanel = true;
    private boolean showMap = true;
    private boolean showHeroes = true;
    private boolean showMinions = true;
    private boolean showMonsters = true;
    private boolean showZeroSkillCd = false;
    private boolean touchEditingEnabled;
    private final Map<String, String> heroNames = new HashMap<>();
    private final Map<String, RadarData.Hero> lastHeroes = new HashMap<>();
    private final List<String> heroOrder = new ArrayList<>();

    public RadarView(Context context) {
        super(context);
        textPaint.setColor(Color.WHITE);
        textPaint.setTextAlign(Paint.Align.CENTER);
        textPaint.setFakeBoldText(true);
        setBackgroundColor(Color.TRANSPARENT);
        loadHeroNames();
    }

    public void setHeroIconCache(HeroIconCache iconCache) {
        this.iconCache = iconCache;
    }

    public void setRoomId(String roomId) {
        String next = roomId == null ? "" : roomId;
        if (!next.equals(this.roomId)) clearHeroCache();
        this.roomId = next;
        invalidate();
    }

    public void clearHeroCache() {
        lastHeroes.clear();
        heroOrder.clear();
        data = new RadarData();
        invalidate();
    }

    public void setStatus(String status) {
        this.status = status == null ? "" : status;
        invalidate();
    }

    public void setData(RadarData data) {
        this.data = mergeStableHeroes(data == null ? new RadarData() : data);
        invalidate();
    }

    private RadarData mergeStableHeroes(RadarData next) {
        Map<String, RadarData.Hero> incoming = new HashMap<>();
        List<String> incomingOrder = new ArrayList<>();
        List<String> previousOrder = new ArrayList<>(heroOrder);
        for (RadarData.Hero hero : next.heroes) {
            if (hero.id == null || hero.id.length() == 0) continue;
            RadarData.Hero last = lastHeroes.get(hero.id);
            if (!hasUsablePosition(hero)) {
                if (last == null || !hasUsablePosition(last)) continue;
                hero.x = last.x;
                hero.y = last.y;
            }
            hero.stale = false;
            if (hero.dead || hero.hp <= 0) {
                incomingOrder.add(hero.id);
                lastHeroes.remove(hero.id);
                heroOrder.remove(hero.id);
                continue;
            }
            incoming.put(hero.id, hero);
            incomingOrder.add(hero.id);
            lastHeroes.put(hero.id, copyHero(hero));
            if (!heroOrder.contains(hero.id)) heroOrder.add(hero.id);
        }
        if (incoming.size() >= MAX_STABLE_HEROES && !hasAnyActiveHero(incoming, previousOrder)) {
            heroOrder.clear();
            lastHeroes.clear();
            for (String id : incomingOrder) {
                RadarData.Hero hero = incoming.get(id);
                if (hero == null) continue;
                lastHeroes.put(id, copyHero(hero));
                heroOrder.add(id);
                if (heroOrder.size() >= MAX_STABLE_HEROES) break;
            }
        }
        next.heroes.clear();
        List<String> ordered = new ArrayList<>();
        for (String id : heroOrder) {
            if (!ordered.contains(id)) ordered.add(id);
        }
        for (String id : incomingOrder) {
            if (!ordered.contains(id)) ordered.add(id);
        }
        heroOrder.clear();
        for (String id : ordered) {
            RadarData.Hero hero = incoming.get(id);
            if (hero == null) {
                lastHeroes.remove(id);
                continue;
            }
            if (hero.dead || hero.hp <= 0) {
                lastHeroes.remove(id);
                continue;
            }
            next.heroes.add(hero);
            heroOrder.add(id);
            if (next.heroes.size() >= MAX_STABLE_HEROES) break;
        }
        return next;
    }

    private boolean hasAnyActiveHero(Map<String, RadarData.Hero> incoming, List<String> order) {
        for (String id : order) {
            if (incoming.containsKey(id)) return true;
        }
        return false;
    }

    private boolean hasUsablePosition(RadarData.Hero hero) {
        return hero != null && !Float.isNaN(hero.x) && !Float.isNaN(hero.y) && hero.x >= 0 && hero.y >= 0;
    }

    private RadarData.Hero copyHero(RadarData.Hero source) {
        RadarData.Hero hero = new RadarData.Hero();
        hero.id = source.id;
        hero.level = source.level;
        hero.ultCd = source.ultCd;
        hero.skillCd = source.skillCd;
        hero.summonerCd = source.summonerCd;
        hero.summonerSkillId = source.summonerSkillId;
        hero.deathCd = source.deathCd;
        hero.x = source.x;
        hero.y = source.y;
        hero.hp = source.hp;
        hero.team = source.team;
        hero.dead = source.dead;
        hero.stale = source.stale;
        return hero;
    }

    public void adjustHeroes(float dx, float dy) {
        heroOffsetX += dx;
        heroOffsetY += dy;
        invalidate();
    }

    public void adjustMinions(float dx, float dy) {
        minionOffsetX += dx;
        minionOffsetY += dy;
        invalidate();
    }

    public void adjustMonsters(float dx, float dy) {
        monsterOffsetX += dx;
        monsterOffsetY += dy;
        invalidate();
    }

    public void setHeroOffset(float x, float y) {
        heroOffsetX = x;
        heroOffsetY = y;
        invalidate();
    }

    public void setMinionOffset(float x, float y) {
        minionOffsetX = x;
        minionOffsetY = y;
        invalidate();
    }

    public void setMonsterOffset(float x, float y) {
        monsterOffsetX = x;
        monsterOffsetY = y;
        invalidate();
    }

    public void setHeroIconScale(float scale) {
        heroIconScale = clamp(scale, 0.25f, 2.2f);
        invalidate();
    }

    public void setOverlaySize(float sizePx) {
        overlaySizePx = Math.max(0, sizePx);
        invalidate();
    }

    public void setMinionScale(float scale) {
        minionScale = clamp(scale, 0.35f, 2.5f);
        invalidate();
    }

    public void setMonsterScale(float scale) {
        monsterScale = clamp(scale, 0.35f, 2.5f);
        invalidate();
    }

    public void setMapOffset(float x, float y) {
        mapOffsetX = x;
        mapOffsetY = y;
        invalidate();
    }

    public void setMapScale(float scale) {
        mapScale = clamp(scale, 0.25f, 2.4f);
        invalidate();
    }

    public void setSkillOffset(float x, float y) {
        skillOffsetX = x;
        skillOffsetY = y;
        invalidate();
    }

    public void setSkillScale(float scale) {
        skillScale = clamp(scale, 0.15f, 1.8f);
        invalidate();
    }

    public void setSkillGap(float gap) {
        skillGap = gap;
        invalidate();
    }

    public void setSkillAvatarScale(float scale) {
        skillAvatarScale = clamp(scale, 0.45f, 1.8f);
        invalidate();
    }

    public void setShowSkillPanel(boolean show) {
        showSkillPanel = show;
        invalidate();
    }

    public void setShowZeroSkillCd(boolean show) {
        showZeroSkillCd = show;
        invalidate();
    }

    public void setTouchEditingEnabled(boolean enabled) {
        touchEditingEnabled = enabled;
        if (!enabled) lastPinchDistance = 0;
    }

    public void setLayerVisibility(boolean map, boolean heroes, boolean minions, boolean monsters, boolean skills) {
        showMap = map;
        showHeroes = heroes;
        showMinions = minions;
        showMonsters = monsters;
        showSkillPanel = skills;
        invalidate();
    }

    public void resetAdjustments() {
        heroOffsetX = heroOffsetY = minionOffsetX = minionOffsetY = monsterOffsetX = monsterOffsetY = 0;
        mapOffsetX = mapOffsetY = skillOffsetX = skillOffsetY = 0;
        mapScale = 1f;
        skillScale = 1f;
        skillGap = 0f;
        skillAvatarScale = 1f;
        heroIconScale = 0.72f;
        minionScale = 1f;
        monsterScale = 1f;
        invalidate();
    }

    public void setMinionLaneRotationSteps(int steps) {
        minionLaneRotationSteps = ((steps % 4) + 4) % 4;
        invalidate();
    }

    public void zoom(float factor) {
        mapScale = clamp(mapScale * factor, 0.25f, 2.4f);
        invalidate();
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        int w = getWidth();
        int h = getHeight();
        float pad = dp(4);
        float sidePanelWidth = showSkillPanel ? Math.max(dp(86), Math.min(dp(120), Math.round(h * 0.48f))) + dp(8) : 0;
        float mapAreaWidth = Math.max(dp(40), w - sidePanelWidth);
        float size = overlaySizePx > 0 ? overlaySizePx : Math.min(mapAreaWidth, h);
        if (size <= 0) size = Math.min(w - pad * 2, h - pad * 2);
        float left = 0;
        float top = (h - size) / 2f;
        RectF map = new RectF(left + mapOffsetX, top + mapOffsetY, left + mapOffsetX + size, top + mapOffsetY + size);
        float skillLeft = size + dp(8);
        RectF skillPanel = new RectF(skillLeft + skillOffsetX, pad + skillOffsetY, w - pad + skillOffsetX, Math.min(h - pad, pad + dp(92)) + skillOffsetY);

        if (showMap) drawMapBase(canvas, map);
        canvas.save();
        canvas.clipRect(map);
        if (data.rotate180) {
            canvas.rotate(180, map.centerX(), map.centerY());
        }
        if (showMinions) drawMinions(canvas, map);
        if (showMonsters) drawMonsters(canvas, map);
        canvas.restore();
        if (showHeroes) drawHeroes(canvas, map);
        if (showSkillPanel) {
            drawSkillPanel(canvas, skillPanel);
        }
    }

    private void drawHeader(Canvas canvas, int width) {
        paint.setShader(new LinearGradient(0, 0, width, 0, 0xff38bdf8, 0xff8b5cf6, Shader.TileMode.CLAMP));
        paint.setStyle(Paint.Style.FILL);
        canvas.drawRoundRect(new RectF(dp(10), dp(8), width - dp(10), dp(34)), dp(10), dp(10), paint);
        paint.setShader(null);
        textPaint.setTextSize(dp(12));
        textPaint.setColor(0xffffffff);
        canvas.drawText("原生雷达  " + status, width / 2f, dp(26), textPaint);
    }

    private void drawMapBase(Canvas canvas, RectF map) {
        paint.setStyle(Paint.Style.FILL);
    }

    private void drawHeroes(Canvas canvas, RectF map) {
        for (RadarData.Hero hero : data.heroes) {
            if (hero.dead || hero.hp <= 0) continue;
            float x = coordX(map, hero.x + heroOffsetX);
            float y = coordY(map, hero.y + heroOffsetY);
            int fill = hero.team == 1 ? 0xff38bdf8 : 0xfffb7185;
            Bitmap icon = iconCache != null ? iconCache.get(hero.id, this::invalidate) : null;
            float iconRadius = clamp(dp(12) * heroIconScale, dp(3.5f), dp(26));
            float bgRadius = iconRadius + Math.max(dp(1), iconRadius * 0.18f);
            float borderRadius = iconRadius + Math.max(dp(1), iconRadius * 0.12f);
            float hpPercent = clamp(hero.hp, 0f, 100f) / 100f;
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(hero.dead ? 0xdd64748b : 0xcc020617);
            canvas.drawCircle(x, y, bgRadius, paint);
            if (icon != null) {
                drawCircularBitmap(canvas, icon, x, y, iconRadius);
            } else {
                paint.setColor(fill);
                canvas.drawCircle(x, y, iconRadius, paint);
            }
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeCap(Paint.Cap.ROUND);
            paint.setStrokeWidth(Math.max(dp(1), iconRadius * 0.1f));
            paint.setColor(fill);
            canvas.drawCircle(x, y, borderRadius, paint);

            float barW = Math.max(dp(14), iconRadius * 1.85f);
            float barH = Math.max(dp(3), iconRadius * 0.22f);
            float barTop = y + iconRadius + Math.max(dp(2), iconRadius * 0.2f);
            RectF barBg = new RectF(x - barW / 2f, barTop, x + barW / 2f, barTop + barH);
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(0xffcbd5e1);
            canvas.drawRoundRect(barBg, barH / 2f, barH / 2f, paint);
            if (hpPercent > 0f) {
                RectF hpBar = new RectF(barBg.left, barBg.top, barBg.left + barW * hpPercent, barBg.bottom);
                paint.setColor(hpPercent <= 0.35f ? 0xffff1744 : 0xffef4444);
                canvas.drawRoundRect(hpBar, barH / 2f, barH / 2f, paint);
            }
            paint.setStrokeCap(Paint.Cap.BUTT);
        }
    }

    private void drawSkillPanel(Canvas canvas, RectF panel) {
        float s = skillScale;
        float gap = skillGap;
        float colW = dp(28) * s + gap;
        float x = panel.left;
        int shown = 0;
        for (int i = 0; i < data.heroes.size() && shown < 5; i++) {
            RadarData.Hero hero = data.heroes.get(i);
            if (hero.dead) continue;
            drawSkillRow(canvas, hero, x, panel.top, colW, panel.height(), s);
            x += colW;
            shown++;
        }
    }

    private void drawSkillRow(Canvas canvas, RadarData.Hero hero, float left, float top, float width, float height, float s) {
        float avatarR = dp(13) * s * skillAvatarScale;
        float cx = left + width / 2f;
        float cy = top + height * 0.42f;
        float box = dp(20) * s;
        float ultBox = dp(20) * s;
        float ultTop = cy - avatarR - ultBox - dp(4) * s;
        RectF ultRect = new RectF(cx - ultBox / 2f, ultTop, cx + ultBox / 2f, ultTop + ultBox);
        Bitmap ultIcon = iconCache != null ? iconCache.getUlt(hero.id, this::invalidate) : null;
        boolean ultLocked = hero.level > 0 && hero.level < 4;
        boolean ultCooling = hero.ultCd > 0;
        if (ultIcon != null) {
            drawRoundBitmap(canvas, ultIcon, ultRect, dp(3) * s);
        } else {
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(hero.dead || ultLocked ? 0x88475569 : 0x992563eb);
            canvas.drawRoundRect(ultRect, dp(3) * s, dp(3) * s, paint);
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(dp(1));
            paint.setColor(0x99ffffff);
            canvas.drawRoundRect(ultRect, dp(3) * s, dp(3) * s, paint);
        }
        if (hero.dead || ultLocked || ultCooling) {
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(hero.dead || ultLocked ? 0xaa020617 : 0xbb334155);
            canvas.drawRoundRect(ultRect, dp(3) * s, dp(3) * s, paint);
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(dp(1) * s);
            paint.setColor(ultLocked ? 0xff94a3b8 : 0x99ffffff);
            canvas.drawRoundRect(ultRect, dp(3) * s, dp(3) * s, paint);
        }
        textPaint.setTextAlign(Paint.Align.CENTER);
        textPaint.setFakeBoldText(true);
        textPaint.setColor(hero.dead ? 0x99ffffff : 0xffffffff);
        textPaint.setTextSize(Math.max(dp(8) * s, ultBox * 0.5f));
        textPaint.setShadowLayer(dp(2) * s, 0, 0, 0xcc000000);
        if (!ultLocked && shouldDrawSkillCountdown(hero.ultCd)) {
            String ultText = String.valueOf(Math.max(0, Math.min(999, hero.ultCd)));
            canvas.drawText(ultText, ultRect.centerX(), ultRect.centerY() + textPaint.getTextSize() * 0.35f, textPaint);
        }
        textPaint.clearShadowLayer();
        Bitmap icon = iconCache != null ? iconCache.get(hero.id, this::invalidate) : null;
        if (icon != null) {
            drawCircularBitmap(canvas, icon, cx, cy, avatarR);
        } else {
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(hero.dead ? 0xff64748b : (hero.team == 1 ? 0xff38bdf8 : 0xfffb7185));
            canvas.drawCircle(cx, cy, avatarR, paint);
        }
        if (hero.dead) {
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(0xaa020617);
            canvas.drawCircle(cx, cy, avatarR, paint);
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(dp(1) * s);
            paint.setColor(0xff94a3b8);
            canvas.drawCircle(cx, cy, avatarR, paint);
        }
        float sumTop = cy + avatarR + dp(3) * s;
        RectF r = new RectF(cx - box / 2f, sumTop, cx + box / 2f, sumTop + box);
        Bitmap summoner = iconCache != null ? iconCache.getSummoner(hero.summonerSkillId, this::invalidate) : null;
        if (summoner == null && iconCache != null && hero.summonerCd >= 0) {
            summoner = iconCache.getSummonerPlaceholder();
        }
        if (summoner != null) {
            drawRoundBitmap(canvas, summoner, r, dp(3) * s);
        } else {
            paint.setStyle(Paint.Style.STROKE);
            paint.setStrokeWidth(dp(1));
            paint.setColor(0x99ffffff);
            canvas.drawRoundRect(r, dp(3) * s, dp(3) * s, paint);
        }
        if (hero.dead || hero.summonerCd > 0) {
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(hero.dead ? 0x99020617 : 0xbb334155);
            canvas.drawRoundRect(r, dp(3) * s, dp(3) * s, paint);
        }
        textPaint.setTextSize(Math.max(dp(8) * s, box * 0.5f));
        textPaint.setShadowLayer(dp(2) * s, 0, 0, 0xcc000000);
        if (shouldDrawSkillCountdown(hero.summonerCd)) {
            String cdText = String.valueOf(Math.max(0, Math.min(999, hero.summonerCd)));
            canvas.drawText(cdText, r.centerX(), r.centerY() + textPaint.getTextSize() * 0.35f, textPaint);
        }
        textPaint.clearShadowLayer();
    }

    private boolean shouldDrawSkillCountdown(int cd) {
        return cd > 0;
    }

    private void drawMinions(Canvas canvas, RectF map) {
        paint.setStyle(Paint.Style.FILL);
        for (RadarData.Minion minion : data.minions) {
            float[] fixed = fixMinionPoint(minion.x, minion.y, minion.team);
            float x = coordX(map, fixed[0] + minionOffsetX);
            float y = coordY(map, fixed[1] + minionOffsetY);
            paint.setColor(minion.team == 1 ? 0xff60a5fa : 0xfff87171);
            float r = clamp(minion.radius / 340f * map.width(), dp(1.5f), dp(3.2f)) * minionScale;
            canvas.drawCircle(x, y, r, paint);
        }
    }

    private float[] fixMinionPoint(float x, float y, int team) {
        float px = x;
        float py = y;
        float mapSize = 340f;
        if (team == 1) {
            px = mapSize - px;
            py = mapSize - py;
        }
        for (int i = 0; i < minionLaneRotationSteps; i++) {
            float nx = px - 170f;
            float ny = py - 170f;
            float tx = -ny;
            float ty = nx;
            px = tx + 170f;
            py = ty + 170f;
        }
        return new float[]{px, py};
    }

    private void drawMonsters(Canvas canvas, RectF map) {
        textPaint.setTextSize(dp(9));
        int cd1660221 = -1;
        int cd166009 = -1;
        int cd166022 = -1;
        for (RadarData.Monster monster : data.monsters) {
            if ("1660221".equals(monster.id)) {
                cd1660221 = monster.cd;
            } else if ("166009".equals(monster.id)) {
                cd166009 = monster.cd;
            } else if ("166022".equals(monster.id)) {
                cd166022 = monster.cd;
            }
        }

        for (RadarData.Monster monster : data.monsters) {
            float x = coordX(map, monster.x + monsterOffsetX);
            float y = coordY(map, monster.y + monsterOffsetY);
            paint.setStyle(Paint.Style.FILL);
            paint.setColor(0xfffacc15);
            canvas.drawCircle(x, y, clamp(map.width() * 0.012f, dp(2.2f), dp(4f)) * monsterScale, paint);
            if (shouldDrawMonsterCountdown(monster, cd1660221, cd166009, cd166022)) {
                textPaint.setColor(0xffffffff);
                Paint.Align prev = textPaint.getTextAlign();
                textPaint.setTextAlign(Paint.Align.CENTER);
                canvas.drawText(String.valueOf(monster.cd), x, y + textPaint.getTextSize() * 0.36f, textPaint);
                textPaint.setTextAlign(prev);
            }
        }
    }

    private boolean shouldDrawMonsterCountdown(RadarData.Monster monster, int cd1660221, int cd166009, int cd166022) {
        int cd = monster.cd;
        if (cd <= 0 || cd > 240) return false;
        if (isMonsterReadyCd(cd)) return false;
        if (shouldHideMonsterCountdown(monster.id, cd1660221, cd166009, cd166022)) return false;
        return true;
    }

    private boolean shouldHideMonsterCountdown(String id, int cd1660221, int cd166009, int cd166022) {
        if (hasActiveMonsterCd(cd1660221, 180)) {
            if ("166009".equals(id) || "166018".equals(id) || "166012".equals(id) || "166022".equals(id)) {
                return true;
            }
        } else if (hasActiveMonsterCd(cd166009, 210)) {
            if ("166018".equals(id)) {
                return true;
            }
        }
        return hasActiveMonsterCd(cd166022, 210) && "166012".equals(id);
    }

    private boolean isMonsterReadyCd(int cd) {
        for (int readyCd : MONSTER_READY_CD_VALUES) {
            if (readyCd == cd) return true;
        }
        return false;
    }

    private boolean hasActiveMonsterCd(int cd, int max) {
        return cd > 0 && cd <= max;
    }

    private void drawFooter(Canvas canvas, RectF map) {
        textPaint.setTextSize(dp(11));
        textPaint.setColor(0xff93c5fd);
        String text = roomId.length() > 0 ? "房间 " + roomId : "未填写房间号";
        text += "  英雄 " + data.heroes.size() + "  兵线 " + data.minions.size() + "  野怪 " + data.monsters.size() + "  " + Math.round(mapScale * 100) + "%";
        canvas.drawText(text, map.centerX(), map.bottom + dp(24), textPaint);
    }

    private float coordX(RectF map, float x) {
        return map.centerX() + ((x - 170f) * mapScale / 340f * map.width());
    }

    private float coordY(RectF map, float y) {
        return map.centerY() + ((y - 170f) * mapScale / 340f * map.height());
    }

    private void drawCircularBitmap(Canvas canvas, Bitmap bitmap, float cx, float cy, float radius) {
        BitmapShader shader = new BitmapShader(bitmap, Shader.TileMode.CLAMP, Shader.TileMode.CLAMP);
        Matrix matrix = new Matrix();
        float scale = Math.max((radius * 2f) / bitmap.getWidth(), (radius * 2f) / bitmap.getHeight());
        matrix.setScale(scale, scale);
        matrix.postTranslate(cx - bitmap.getWidth() * scale / 2f, cy - bitmap.getHeight() * scale / 2f);
        shader.setLocalMatrix(matrix);
        paint.setShader(shader);
        paint.setStyle(Paint.Style.FILL);
        canvas.drawCircle(cx, cy, radius, paint);
        paint.setShader(null);
    }

    private void drawRoundBitmap(Canvas canvas, Bitmap bitmap, RectF dst, float radius) {
        BitmapShader shader = new BitmapShader(bitmap, Shader.TileMode.CLAMP, Shader.TileMode.CLAMP);
        Matrix matrix = new Matrix();
        float scale = Math.max(dst.width() / bitmap.getWidth(), dst.height() / bitmap.getHeight());
        matrix.setScale(scale, scale);
        matrix.postTranslate(dst.centerX() - bitmap.getWidth() * scale / 2f, dst.centerY() - bitmap.getHeight() * scale / 2f);
        shader.setLocalMatrix(matrix);
        paint.setShader(shader);
        paint.setStyle(Paint.Style.FILL);
        canvas.drawRoundRect(dst, radius, radius, paint);
        paint.setShader(null);
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        if (!touchEditingEnabled) return false;
        if (event.getPointerCount() >= 2) {
            float d = pointerDistance(event);
            if (event.getActionMasked() == MotionEvent.ACTION_POINTER_DOWN) {
                lastPinchDistance = d;
            } else if (event.getActionMasked() == MotionEvent.ACTION_MOVE && lastPinchDistance > 0) {
                zoom(d / lastPinchDistance);
                lastPinchDistance = d;
            }
            return true;
        }
        if (event.getActionMasked() == MotionEvent.ACTION_UP || event.getActionMasked() == MotionEvent.ACTION_CANCEL) {
            lastPinchDistance = 0;
        }
        return true;
    }

    private float pointerDistance(MotionEvent event) {
        float dx = event.getX(0) - event.getX(1);
        float dy = event.getY(0) - event.getY(1);
        return (float) Math.sqrt(dx * dx + dy * dy);
    }

    private float clamp(float value, float min, float max) {
        return Math.max(min, Math.min(max, value));
    }

    private String fitText(String text, float maxWidth, Paint p) {
        if (text == null || text.length() == 0) return "?";
        if (p.measureText(text) <= maxWidth) return text;
        for (int i = text.length() - 1; i > 0; i--) {
            String next = text.substring(0, i) + ".";
            if (p.measureText(next) <= maxWidth) return next;
        }
        return text.substring(0, 1);
    }

    private String heroName(String id) {
        if (id == null || id.length() == 0) return "?";
        String name = heroNames.get(id);
        return name == null || name.length() == 0 ? shortId(id) : name;
    }

    private void loadHeroNames() {
        try (InputStream in = getContext().getAssets().open("herolist.json");
             ByteArrayOutputStream out = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int read;
            while ((read = in.read(buffer)) != -1) out.write(buffer, 0, read);
            JSONArray arr = new JSONArray(new String(out.toByteArray(), StandardCharsets.UTF_8));
            for (int i = 0; i < arr.length(); i++) {
                JSONObject row = arr.optJSONObject(i);
                if (row == null) continue;
                String id = String.valueOf(row.optInt("ename", 0));
                String name = row.optString("cname", "");
                if (!"0".equals(id) && name.length() > 0) heroNames.put(id, name);
            }
        } catch (Exception ignored) {
        }
    }

    private String shortId(String id) {
        if (id == null || id.length() == 0) return "?";
        return id.length() <= 2 ? id : id.substring(id.length() - 2);
    }

    private float dp(int value) {
        return value * getResources().getDisplayMetrics().density;
    }

    private float dp(float value) {
        return value * getResources().getDisplayMetrics().density;
    }
}
