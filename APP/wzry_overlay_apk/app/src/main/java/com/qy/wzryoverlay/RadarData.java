package com.qy.wzryoverlay;

import java.util.ArrayList;
import java.util.List;

public class RadarData {
    public final List<Hero> heroes = new ArrayList<>();
    public final List<Minion> minions = new ArrayList<>();
    public final List<Monster> monsters = new ArrayList<>();
    public boolean rotate180;
    public long updatedAt;

    public static class Hero {
        public String id;
        public int level;
        public int ultCd;
        public int skillCd;
        public int summonerCd;
        public int summonerSkillId;
        public float x;
        public float y;
        public float hp;
        public int team;
        public boolean dead;
    }

    public static class Minion {
        public float x;
        public float y;
        public int team;
        public float radius;
    }

    public static class Monster {
        public String id;
        public int cd;
        public float x;
        public float y;
    }
}
