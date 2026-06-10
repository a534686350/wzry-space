package com.qy.wzryoverlay;

public final class RadarParser {
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
            hero.skillCd = clampCd(toIntAt(f, 4, 0), 180);
            hero.summonerCd = hero.skillCd;
            hero.summonerSkillId = firstSummonerSkillId(f);
            hero.x = toFloatAt(f, 5, -1);
            hero.y = toFloatAt(f, 6, -1);
            hero.hp = toFloatAt(f, 7, 100);
            hero.team = toIntAt(f, 8, 0);
            if (hero.x >= 0 && hero.y >= 0) data.heroes.add(hero);
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
        int idx = value.indexOf("|||");
        return idx >= 0 ? value.substring(0, idx) : value;
    }

    private static int firstLevel(String[] f) {
        int level = toIntAt(f, 1, 0);
        if (level >= 1 && level <= 30) return level;
        level = toIntAt(f, 2, 0);
        return level >= 1 && level <= 30 ? level : 0;
    }

    private static int firstSummonerSkillId(String[] f) {
        for (int i = 9; i < f.length; i++) {
            int value = toIntAt(f, i, 0);
            if (value >= 80000 && value <= 89999) return value;
        }
        return 0;
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

    private static float toFloatAt(String[] f, int index, float fallback) {
        if (index < 0 || index >= f.length) return fallback;
        try {
            return Float.parseFloat(f[index].trim());
        } catch (Exception ignored) {
            return fallback;
        }
    }
}
