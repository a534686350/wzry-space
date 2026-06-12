package com.qy.wzryoverlay;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class RadarParser {
    private static final Pattern SUMMONER_ID_PATTERN = Pattern.compile("(?<!\\d)(80\\d{3}|5555[1-5])(?!\\d)");
    private static final Pattern FRAME_STAMP_PATTERN = Pattern.compile("###ST:\\d{13}\\s*$");

    private RadarParser() {
    }

    public static RadarData parse(String payload) {
        RadarData data = new RadarData();
        data.updatedAt = System.currentTimeMillis();
        if (payload == null) return data;

        String clean = stripTimeline(payload);
        String[] parts = clean.split("---", -1);
        parseHeroes(parts.length > 0 ? parts[0] : "", data);
        parseMonsters(parts.length > 1 ? parts[1] : "", data);
        parseMinions(parts.length > 2 ? parts[2] : "", data);
        if (parts.length > 4) {
            data.rotate180 = toInt(parts[4], 0) == 1;
        }
        return data;
    }

    private static void parseHeroes(String raw, RadarData data) {
        if (raw == null || raw.trim().length() == 0) return;
        String[] rows = raw.split("==");
        for (String row : rows) {
            if (row == null || row.trim().length() == 0) continue;
            String[] f = row.split(",", -1);
            if (f.length < 9 || f[0].trim().length() == 0) continue;
            RadarData.Hero hero = new RadarData.Hero();
            hero.id = f[0].trim();
            hero.level = firstLevel(f);
            hero.ultCd = clampCd(toIntAt(f, 3, 0), 600);
            hero.skillCd = cdAt(f, 4, 600, -1);
            hero.summonerCd = firstSummonerCd(f);
            hero.summonerSkillId = firstSummonerSkillId(f);
            hero.x = toFloatAt(f, 5, -1);
            hero.y = toFloatAt(f, 6, -1);
            applyLifeState(hero, valueAt(f, 7));
            hero.team = toIntAt(f, 8, 0);
            data.heroes.add(hero);
        }
    }

    private static void parseMonsters(String raw, RadarData data) {
        if (raw == null || raw.trim().length() == 0) return;
        String[] rows = raw.split("==");
        for (String row : rows) {
            if (row == null || row.trim().length() == 0) continue;
            String[] f = row.split(",", -1);
            if (f.length < 5) continue;
            RadarData.Monster monster = new RadarData.Monster();
            monster.cd = clampCd(toIntAt(f, 1, 0), 240);
            monster.id = f[2].trim();
            monster.x = toFloatAt(f, 3, -1);
            monster.y = toFloatAt(f, 4, -1);
            if (monster.x == 108 && monster.y == 104) continue;
            if (monster.x >= 0 && monster.y >= 0) data.monsters.add(monster);
        }
    }

    private static void parseMinions(String raw, RadarData data) {
        if (raw == null || raw.trim().length() == 0) return;
        String[] rows = raw.split("==");
        for (String row : rows) {
            if (row == null || row.trim().length() == 0) continue;
            String[] f = row.split(",", -1);
            if (f.length < 3) continue;
            RadarData.Minion minion = new RadarData.Minion();
            minion.x = toFloatAt(f, 0, -1);
            minion.y = toFloatAt(f, 1, -1);
            minion.team = toIntAt(f, 2, 0);
            minion.radius = Math.max(2f, Math.min(8f, toFloatAt(f, 3, 4)));
            if (minion.x >= 0 && minion.y >= 0) data.minions.add(minion);
        }
    }

    private static String stripTimeline(String value) {
        String out = FRAME_STAMP_PATTERN.matcher(value).replaceFirst("");
        int idx = out.indexOf("|||");
        return idx >= 0 ? out.substring(0, idx) : out;
    }

    private static int firstLevel(String[] f) {
        int level = toIntAt(f, 1, 0);
        if (level >= 1 && level <= 30) return level;
        level = toIntAt(f, 2, 0);
        return level >= 1 && level <= 30 ? level : 0;
    }

    private static void applyLifeState(RadarData.Hero hero, String rawHpLike) {
        float value = toFloat(rawHpLike, Float.NaN);
        if (Float.isNaN(value)) {
            hero.hp = 100f;
            hero.dead = false;
            hero.deathCd = 0;
            return;
        }
        if (value > 100f && value <= 300f) {
            hero.hp = 0f;
            hero.dead = true;
            hero.deathCd = Math.round(value);
            return;
        }
        if (value <= 0f) {
            hero.hp = 0f;
            hero.dead = true;
            hero.deathCd = 0;
            return;
        }
        hero.hp = Math.max(0f, Math.min(100f, value));
        hero.dead = false;
        hero.deathCd = 0;
    }

    private static int firstSummonerCd(String[] f) {
        int main = cdAt(f, 4, 180, Integer.MIN_VALUE);
        if (main != Integer.MIN_VALUE) return main;
        int[] fallbacks = new int[]{9, 10, 11, 12};
        for (int index : fallbacks) {
            int value = cdAt(f, index, 180, Integer.MIN_VALUE);
            if (value != Integer.MIN_VALUE) return value;
        }
        return -1;
    }

    private static int cdAt(String[] f, int index, int max, int fallback) {
        if (index < 0 || index >= f.length) return fallback;
        int value = toInt(f[index], fallback);
        if (value < 0 || value > max) return fallback;
        return value;
    }

    private static int firstSummonerSkillId(String[] f) {
        int[] ids = summonerSkillIdsFromFields(f);
        if (ids.length == 0) return 0;
        for (int id : ids) {
            if (id == 80116) return id;
        }
        return ids[0];
    }

    private static int[] summonerSkillIdsFromFields(String[] f) {
        int[] ids = new int[Math.max(8, f.length * 2)];
        int count = 0;
        count = pushSummonerIdsFromField(ids, count, valueAt(f, 4));
        for (int i = 9; i < f.length; i++) {
            count = pushSummonerIdsFromField(ids, count, valueAt(f, i));
        }
        for (int i = 0; i < f.length; i++) {
            if (i == 3 || i == 4 || i == 5 || i == 6 || i >= 9) continue;
            count = pushSummonerIdsFromField(ids, count, valueAt(f, i));
        }
        count = pushSummonerIdsFromField(ids, count, valueAt(f, 5));
        count = pushSummonerIdsFromField(ids, count, valueAt(f, 6));
        for (int i = f.length - 1; i >= 0; i--) {
            if (i == 3) continue;
            count = pushSummonerIdsFromField(ids, count, valueAt(f, i));
        }
        StringBuilder joined = new StringBuilder();
        for (String field : f) {
            if (field != null) joined.append(field).append(' ');
        }
        count = pushSummonerIdsFromField(ids, count, joined.toString());
        int[] out = new int[count];
        System.arraycopy(ids, 0, out, 0, count);
        return out;
    }

    private static String valueAt(String[] f, int index) {
        return index >= 0 && index < f.length ? f[index] : "";
    }

    private static int pushSummonerIdsFromField(int[] ids, int count, String raw) {
        if (raw == null) return count;
        String value = raw.trim();
        if (value.length() == 0) return count;
        String[] tokens = value.split("[,|;:/@\\s\\x00]+");
        for (String token : tokens) {
            count = pushSummonerId(ids, count, token);
        }
        Matcher matcher = SUMMONER_ID_PATTERN.matcher(value);
        while (matcher.find()) {
            count = pushSummonerId(ids, count, matcher.group(1));
        }
        return count;
    }

    private static int pushSummonerId(int[] ids, int count, String raw) {
        if (raw == null) return count;
        String token = raw.trim();
        if (token.length() == 0 || token.indexOf('.') >= 0 || token.matches(".*[eE][+-]?\\d.*")) return count;
        int value = toInt(token, 0);
        if (!isSummonerSkillId(value) || contains(ids, count, value)) return count;
        if (count >= ids.length) return count;
        ids[count++] = value;
        return count;
    }

    private static boolean contains(int[] values, int count, int value) {
        for (int i = 0; i < count; i++) {
            if (values[i] == value) return true;
        }
        return false;
    }

    private static boolean isSummonerSkillId(int value) {
        return (value >= 80001 && value <= 81999) || (value >= 55551 && value <= 55555);
    }

    private static int clampCd(int value, int max) {
        return value < 0 || value > max ? 0 : value;
    }

    private static int toIntAt(String[] f, int index, int fallback) {
        if (index < 0 || index >= f.length) return fallback;
        return toInt(f[index], fallback);
    }

    private static int toInt(String value, int fallback) {
        try {
            return (int) Float.parseFloat(value.trim());
        } catch (Exception ignored) {
            return fallback;
        }
    }

    private static float toFloat(String value, float fallback) {
        try {
            return Float.parseFloat(value.trim());
        } catch (Exception ignored) {
            return fallback;
        }
    }

    private static float toFloatAt(String[] f, int index, float fallback) {
        if (index < 0 || index >= f.length) return fallback;
        try {
            return Float.parseFloat(f[index].trim());
        } catch (Exception ignored) {
            return fallback;
        }
    }
}
