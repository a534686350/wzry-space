<?php
require_once __DIR__ . '/../auth/bootstrap.php';
requireLogin('index.php?msg=relogin');
$username = $_SESSION['admin_username'] ?? 'admin';
$isAgent = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'agent');

// 当前站点紧急停止状态（按 HTTP_HOST 匹配数据库）
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$host = preg_replace('/:\d+$/', '', trim((string)$host));
if ($host === '') $host = 'unknown';
$emergencyActive = false;
try {
    $stmt = $pdo->prepare('SELECT active FROM emergency_stop_sites WHERE host = ? LIMIT 1');
    $stmt->execute([$host]);
    $row = $stmt->fetch();
    $emergencyActive = $row && (int)$row['active'] === 1;
} catch (Exception $e) {
    $emergencyActive = false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>卡密与用户管理 - 验证系统</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Microsoft YaHei", system-ui, -apple-system, sans-serif; background: #000000; min-height: 100vh; color: #f9fafb; padding: 0; font-size: 15px; line-height: 1.6; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(15,23,42,0.4); }
        ::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.4); border-radius: 999px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(99,102,241,0.7); }
        .layout-shell { max-width: 100%; margin: 0; min-height: 100vh; display: grid; grid-template-columns: 250px minmax(0, 1fr); background: #ffffff; border-radius: 0; box-shadow: 0 0 0 rgba(0,0,0,0); overflow: hidden; }
        .sidebar { background: linear-gradient(180deg, #4f46e5 0%, #3730a3 100%); color: #e5e7ff; padding: 28px 18px 22px; display: flex; flex-direction: column; gap: 24px; position: relative; overflow: hidden; }
        .sidebar::before { content: ''; position: absolute; top: -60px; right: -60px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(129,140,248,0.25), transparent 70%); pointer-events: none; }
        .sidebar::after { content: ''; position: absolute; bottom: -40px; left: -40px; width: 140px; height: 140px; background: radial-gradient(circle, rgba(99,102,241,0.2), transparent 70%); pointer-events: none; }
        .sidebar-logo { position: relative; z-index: 1; }
        .sidebar-logo-main { font-size: 20px; font-weight: 700; letter-spacing: 0.06em; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .sidebar-logo-sub { margin-top: 4px; font-size: 12px; opacity: 0.75; letter-spacing: 0.08em; text-transform: uppercase; }
        .sidebar-nav { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; position: relative; z-index: 1; }
        .sidebar-nav-item { text-align: left; padding: 10px 14px; border-radius: 8px; border: none; background: transparent; color: rgba(229,231,255,0.85); font-size: 14px; cursor: pointer; transition: all 0.2s ease; font-weight: 500; letter-spacing: 0.02em; }
        .sidebar-nav-item:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(3px); }
        .sidebar-nav-item.active { background: rgba(255,255,255,0.95); color: #3730a3; font-weight: 600; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .sidebar-footer { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.15); padding-top: 16px; font-size: 13px; display: flex; flex-direction: column; gap: 8px; position: relative; z-index: 1; }
        .sidebar-user-name { font-weight: 600; font-size: 14px; }
        .sidebar-links a { color: rgba(229,231,255,0.8); font-size: 13px; margin-right: 12px; text-decoration: none; transition: color 0.2s; }
        .sidebar-links a:hover { color: #fff; text-decoration: none; }
        .main { background: #0a0f1e; padding: 24px 32px 28px; min-height: 100vh; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .header h1 { font-size: 22px; color: #e2e8f0; font-weight: 700; letter-spacing: -0.01em; }
        .header .user { font-size: 14px; color: #64748b; }
        .header .user a { color: #818cf8; text-decoration: none; margin-left: 12px; transition: color 0.2s; }
        .header .user a:hover { color: #a5b4fc; }
        .section { margin-bottom: 36px; }
        .section h2 { font-size: 18px; color: #e2e8f0; margin-bottom: 16px; font-weight: 600; }
        .toolbar { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
        .toolbar input { padding: 8px 12px; border: 1px solid rgba(99,102,241,0.3); border-radius: 8px; background: rgba(15,23,42,0.8); color: #e2e8f0; font-size: 14px; transition: border-color 0.2s; }
        .toolbar input:focus { outline: none; border-color: rgba(99,102,241,0.6); box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        .btn { padding: 8px 16px; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; font-weight: 500; transition: all 0.2s ease; }
        .btn:hover { transform: translateY(-1px); filter: brightness(1.1); }
        .btn:active { transform: translateY(0); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; filter: none; }
        .btn-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; box-shadow: 0 2px 8px rgba(99,102,241,0.3); }
        .btn-primary:hover { box-shadow: 0 4px 12px rgba(99,102,241,0.4); }
        .btn-secondary { background: rgba(99,102,241,0.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.3); }
        .btn-secondary:hover { background: rgba(99,102,241,0.25); color: #fff; }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 2px 8px rgba(239,68,68,0.3); }
        .btn-small { padding: 5px 10px; font-size: 12px; border-radius: 6px; }
        .btn-link { background: rgba(99,102,241,0.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.3); text-decoration: none; display: inline-block; }
        .btn-link:hover { background: rgba(99,102,241,0.25); color: #fff; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; background: rgba(15,23,42,0.6); border-radius: 12px; overflow: hidden; font-size: 13px; border: 1px solid rgba(99,102,241,0.12); }
        th, td { padding: 11px 14px; text-align: left; border-bottom: 1px solid rgba(99,102,241,0.08); }
        th { background: rgba(99,102,241,0.1); color: #a5b4fc; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
        tr:hover td { background: rgba(99,102,241,0.05); }
        .status-0 { color: #ffd43b; }
        .status-1 { color: #51cf66; }
        .expired { color: #ff6b6b; }
        .paused { color: #ff922b; }
        .btn-warning { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; box-shadow: 0 2px 6px rgba(245,158,11,0.25); }
        .btn-success { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; box-shadow: 0 2px 6px rgba(34,197,94,0.25); }
        .btn-type { padding: 7px 13px; font-size: 13px; background: rgba(99,102,241,0.12); color: #a5b4fc; border: 1px solid rgba(99,102,241,0.25); border-radius: 8px; cursor: pointer; margin-right: 4px; transition: all 0.2s; }
        .btn-type:hover { background: rgba(99,102,241,0.25); color: #fff; }
        .btn-type.active { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border-color: transparent; box-shadow: 0 2px 8px rgba(99,102,241,0.35); }
        .batch-toolbar { display: flex; }
        .card-checkbox { cursor: pointer; }
        .ip { font-family: ui-monospace, 'Cascadia Code', monospace; font-size: 13px; color: #818cf8; }
        .msg { margin-bottom: 14px; font-size: 14px; min-height: 24px; padding: 0 4px; }
        .msg.err { color: #f87171; }
        .msg.ok { color: #4ade80; }
        .empty { text-align: center; padding: 40px; color: #64748b; font-size: 14px; }
        .muted { color: #64748b; font-size: 13px; margin-bottom: 12px; line-height: 1.5; }
        .invalid { color: #ff6b6b; }
        .user-status.status-ok { color: #51cf66; }
        .user-status.status-pause { color: #f59f00; }
        .user-status.status-invalid { color: #ff6b6b; }
        .status-online { color: #51cf66; }
        .status-offline { color: #888; }
        .dashboard { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        @media (max-width: 1200px) { .dashboard { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .dashboard { grid-template-columns: 1fr; } }
        .dash-card {
            background:
                radial-gradient(circle at top left, rgba(148,163,184,0.18), transparent 55%),
                #020617;
            border: 1px solid rgba(30,64,175,0.55);
            border-radius: 14px;
            padding: 22px 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 18px 40px rgba(15,23,42,0.85);
        }
        .dash-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .dash-card.card-users::before { background: linear-gradient(180deg, #4a9eff, #258DF2); }
        .dash-card.card-cards::before { background: linear-gradient(180deg, #51cf66, #37b24d); }
        .dash-card.card-unused::before { background: linear-gradient(180deg, #ffd43b, #f59f00); }
        .dash-card.card-used::before { background: linear-gradient(180deg, #74c0fc, #4a9eff); }
        .dash-card.card-expired::before { background: linear-gradient(180deg, #ff6b6b, #e03131); }
        .dash-card.card-today::before { background: linear-gradient(180deg, #69db7c, #51cf66); }
        .dash-card.card-week::before { background: linear-gradient(180deg, #a78bfa, #8b5cf6); }
        .dash-card .dash-label { font-size: 14px; color: #94a3b8; margin-bottom: 10px; letter-spacing: 0.02em; }
        .dash-card .dash-num { font-size: 18px; font-weight: 700; color: #f1f5f9; letter-spacing: -0.02em; line-height: 1.2; }
        .dash-card .dash-sub { font-size: 13px; color: #64748b; margin-top: 10px; }
        .dash-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { .dash-row { grid-template-columns: 1fr; } }
        .dash-group {
            background:
                radial-gradient(circle at top left, rgba(56,189,248,0.20), transparent 55%),
                #020617;
            border: 1px solid rgba(30,64,175,0.55);
            border-radius: 14px;
            padding: 20px 24px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.9);
        }
        .overview-row {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 24px;
            align-items: stretch;
        }
        .overview-left {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .overview-right {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .dash-group h3 { font-size: 15px; color: #94a3b8; font-weight: 600; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 0.05em; }
        .dash-group .dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .dash-group .dash-item { display: flex; flex-direction: column; gap: 4px; }
        .dash-group .dash-item .val { font-size: 24px; font-weight: 700; color: #e2e8f0; }
        .dash-group .dash-item .txt { font-size: 12px; color: #64748b; }
        /* 小型柱状图样式 */
        .mini-chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 16px;
            height: 150px;
            padding-top: 8px;
        }
        .mini-chart-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }
        .mini-chart-bar {
            width: 100%;
            max-width: 40px;
            height: 110px;
            border-radius: 999px;
            background: #e5e7eb;
            position: relative;
            overflow: hidden;
        }
        .mini-chart-bar-fill {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 10%;
            border-radius: inherit;
            background: linear-gradient(180deg, #4f46e5, #22c55e);
            transition: height 0.3s ease;
        }
        .mini-chart-bar-fill.secondary {
            background: linear-gradient(180deg, #fb923c, #f97316);
        }
        .mini-chart-bar-fill.muted {
            background: linear-gradient(180deg, #e5e7eb, #9ca3af);
        }
        .mini-chart-bar-fill.expired {
            background: linear-gradient(180deg, #fb7185, #ef4444);
            box-shadow: 0 0 8px rgba(239,68,68,0.5);
        }
        .mini-chart-bar-fill.deleted {
            background: linear-gradient(180deg, #a78bfa, #8b5cf6);
            box-shadow: 0 0 8px rgba(139,92,246,0.5);
        }
        .mini-chart-bar-fill.accent {
            background: linear-gradient(180deg, #22d3ee, #06b6d4);
            box-shadow: 0 0 8px rgba(6,182,212,0.5);
        }
        .mini-chart-value {
            font-size: 14px;
            font-weight: 700;
            color: #38bdf8;
        }
        .mini-chart-label {
            font-size: 13px;
            color: #e5e7eb;
            text-align: center;
            max-width: 80px;
            white-space: nowrap;
        }
        /* 地域分布面板 */
        .region-chart {
            height: 260px;
            display: flex;
            align-items: flex-end;
            padding: 18px 18px 18px 10px;
            gap: 14px;
            background:
                radial-gradient(circle at top left, rgba(59,130,246,0.18), transparent 55%),
                #020617;
            border-radius: 14px;
            border: 1px solid rgba(30,64,175,0.55);
        }
        .region-bar-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .region-bar {
            width: 100%;
            max-width: 40px;
            height: 170px;
            border-radius: 999px;
            background: #0f172a;
            position: relative;
            overflow: hidden;
        }
        .region-bar-fill {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 12%;
            border-radius: inherit;
            background: linear-gradient(180deg, #22c55e, #16a34a);
            box-shadow: 0 0 10px rgba(52,211,153,0.6);
            transition: height 0.3s ease;
        }
        .region-bar-fill.hot {
            background: linear-gradient(180deg, #fb7185, #ef4444);
            box-shadow: 0 0 10px rgba(248,113,113,0.6);
        }
        .region-bar-value {
            font-size: 14px;
            font-weight: 700;
            color: #facc15;
        }
        .region-bar-label {
            font-size: 12px;
            color: #e5e7eb;
            text-align: center;
            max-width: 70px;
            white-space: nowrap;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .region-list {
            background:
                radial-gradient(circle at top right, rgba(129,140,248,0.18), transparent 55%),
                #020617;
            border-radius: 14px;
            border: 1px solid rgba(30,64,175,0.55);
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 13px;
            color: #e5e7eb;
        }
        .region-list-title {
            font-size: 14px;
            font-weight: 600;
            color: #a5b4fc;
            margin-bottom: 4px;
        }
        .region-list-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .region-list-name {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .region-list-count {
            font-weight: 700;
            color: #38bdf8;
        }
        .region-list-rank {
            width: 18px;
            text-align: right;
            color: #9ca3af;
        }
        /* 单一多区域圆盘样式 */
        .summary-pie-layout {
            display: flex;
            gap: 24px;
            align-items: center;
            flex-wrap: wrap;
        }
        .summary-pie-circle-wrap {
            flex: 0 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .summary-pie-circle {
            --pie-unused: #facc15;
            --pie-used: #22c55e;
            --pie-expired: #ef4444;
            --pie-deleted: #a78bfa;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            position: relative;
            background:
                radial-gradient(circle at center, #020617 55%, transparent 57%),
                conic-gradient(#1e293b 0 100%);
            box-shadow: 0 0 0 1px rgba(15,23,42,0.6), 0 18px 35px rgba(15,23,42,0.9);
        }
        .summary-pie-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        .summary-pie-total {
            font-size: 22px;
            font-weight: 700;
            color: #e5e7eb;
        }
        .summary-pie-sub {
            margin-top: 4px;
            font-size: 12px;
            color: #9ca3af;
        }
        .summary-pie-legend {
            flex: 1 1 220px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 15px;
            color: #f9fafb;
            font-weight: 600;
        }
        .legend-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
        }
        .legend-unused { background: #facc15; }
        .legend-used { background: #22c55e; }
        .legend-expired { background: #ef4444; }
        .legend-deleted { background: #a78bfa; }
        .legend-label {
            flex: 0 0 60px;
            color: #facc15;
            font-weight: 700;
            font-size: 15px;
        }
        .legend-value {
            min-width: 48px;
            color: #38bdf8;
            font-weight: 700;
            font-size: 15px;
        }
        .legend-percent {
            min-width: 48px;
            color: #a5b4fc;
            font-weight: 600;
            font-size: 14px;
        }
        .legend-divider {
            margin: 4px 0;
            height: 1px;
            background: rgba(148,163,206,0.28);
        }
        .legend-extra {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 4px 16px;
            font-size: 14px;
            color: #e5e7eb;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            .summary-pie-layout { flex-direction: column; align-items: flex-start; }
            .summary-pie-circle-wrap { justify-content: flex-start; }
            .legend-extra { grid-template-columns: 1fr; }
        }
        .stats { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .stat-box { background: rgba(47,54,60,0.6); border: 1px solid rgba(74,158,255,0.3); border-radius: 10px; padding: 16px 20px; min-width: 120px; }
        .stat-box .num { font-size: 24px; font-weight: 600; color: #4a9eff; }
        .stat-box .label { font-size: 13px; color: #888; margin-top: 4px; }
        .filter-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
        .filter-row input { padding: 8px 12px; border: 1px solid rgba(99,102,241,0.25); border-radius: 8px; background: rgba(15,23,42,0.8); color: #e2e8f0; font-size: 13px; transition: border-color 0.2s; }
        .filter-row input:focus { outline: none; border-color: rgba(99,102,241,0.5); box-shadow: 0 0 0 3px rgba(99,102,241,0.08); }
        .filter-row select { padding: 8px 11px; border: 1px solid rgba(99,102,241,0.25); border-radius: 8px; background: rgba(15,23,42,0.8); color: #e2e8f0; font-size: 13px; transition: border-color 0.2s; }
        .filter-row select:focus { outline: none; border-color: rgba(99,102,241,0.5); }
        .pagination { display: flex; align-items: center; gap: 12px; margin-top: 14px; font-size: 13px; color: #64748b; }
        .pagination button { padding: 6px 14px; cursor: pointer; background: rgba(99,102,241,0.12); border: 1px solid rgba(99,102,241,0.25); color: #a5b4fc; border-radius: 8px; font-size: 13px; transition: all 0.2s; }
        .pagination button:hover { background: rgba(99,102,241,0.2); color: #fff; }
        .pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
        .page-screen { display: none; }
        .page-screen.active { display: block; }
        @media (max-width: 768px) {
            .layout-shell {
                grid-template-columns: 1fr;
                min-height: 100vh;
                overflow: visible;
            }
            .sidebar {
                flex-direction: row;
                align-items: flex-start;
                padding: 14px 12px;
                gap: 14px;
                overflow-x: auto;
            }
            .sidebar-logo {
                flex: 0 0 auto;
                margin-right: 8px;
            }
            .sidebar-nav {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 6px;
                margin-top: 0;
            }
            .sidebar-nav-item {
                padding: 8px 10px;
                font-size: 13px;
                white-space: nowrap;
            }
            .sidebar-footer {
                margin-top: 0;
                padding-top: 0;
                border-top: none;
                font-size: 12px;
            }
            .main {
                padding: 14px 10px 18px;
                min-width: 0;
                overflow-x: auto;
            }
            .header h1 {
                font-size: 18px;
            }
            table, .toolbar {
                font-size: 12px;
            }
            th, td {
                padding: 8px;
            }
            .section {
                margin-bottom: 24px;
            }
            .dashboard {
                grid-template-columns: 1fr;
            }
            .overview-row {
                grid-template-columns: 1fr;
            }
            .region-chart {
                height: auto;
                min-height: 220px;
            }
            .summary-pie-circle {
                width: 130px;
                height: 130px;
            }
            .summary-pie-legend {
                font-size: 13px;
            }
            .summary-pie-total {
                font-size: 18px;
            }
            /* 表格在小屏允许横向滚动 */
            .section table {
                display: block;
                width: 100%;
                overflow-x: auto;
                white-space: nowrap;
            }
            .toolbar input,
            .filter-row input,
            .filter-row select {
                max-width: 100%;
            }
            .btn {
                padding: 8px 12px;
                font-size: 13px;
            }
        }
        @media (max-width: 480px) {
            .sidebar {
                padding: 10px 8px;
                gap: 8px;
            }
            .sidebar-logo-main {
                font-size: 16px;
            }
            .sidebar-logo-sub {
                font-size: 11px;
            }
            .sidebar-nav-item {
                padding: 7px 9px;
                font-size: 12px;
            }
            .header {
                margin-bottom: 12px;
            }
            .header .user {
                font-size: 12px;
            }
            .section h2 {
                font-size: 17px;
                margin-bottom: 12px;
            }
            .dash-card {
                padding: 14px 12px;
            }
        }
    </style>
</head>
<body>
    <div class="layout-shell">
        <aside class="sidebar">
            <div class="sidebar-logo">
                <div class="sidebar-logo-main">验证后台</div>
                <div class="sidebar-logo-sub"><?php echo $isAgent ? '代理控制台' : '管理控制台'; ?></div>
            </div>
            <nav class="sidebar-nav">
                <button class="sidebar-nav-item active" data-page="overview">总览</button>
                <button class="sidebar-nav-item" data-page="users">用户管理</button>
                <button class="sidebar-nav-item" data-page="cards">卡密管理</button>
                <?php if (!$isAgent): ?>
                <button class="sidebar-nav-item" data-page="trial">试用卡管理</button>
                <button class="sidebar-nav-item" data-page="agents">代理</button>
                <button class="sidebar-nav-item" data-page="security">服务器 / 黑名单 / 清理</button>
                <button class="sidebar-nav-item" data-page="app">APP</button>
                <button class="sidebar-nav-item" data-page="logs">操作日志</button>
                <?php endif; ?>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <span class="sidebar-user-name"><?php echo htmlspecialchars($username); ?><?php echo $isAgent ? ' (代理)' : ''; ?></span>
                </div>
                <div class="sidebar-links">
                    <a href="index.php?action=change_password">修改密码</a>
                    <a href="index.php?action=logout">退出</a>
                </div>
            </div>
        </aside>
        <main class="main">
            <div class="header">
                <h1>控制面板</h1>
            </div>
            <div class="toolbar" style="justify-content:flex-end;">
                <a class="btn btn-link" href="emergency_stop_multi.php" style="margin-right:10px;">
                    多站点停机控制
                </a>
                <a class="btn btn-danger" href="emergency_stop_multi.php" style="margin-left:auto; display:inline-flex; align-items:center; justify-content:center;">
                    <?php echo $emergencyActive ? '恢复服务' : '紧急停止'; ?>
                </a>
            </div>
            <div id="msg" class="msg"></div>

            <section class="page-screen" data-page="overview">
    <!-- 数据统计 - 圆盘总览（代理仅显示自己卡密统计） + 迷你数据图 -->
    <div class="section">
        <h2 style="margin-bottom:20px;"><?php echo $isAgent ? '我的数据概览' : '数据概览'; ?></h2>
        <div id="statsBox" class="dashboard">
            <div class="dash-card card-cards" style="grid-column: 1 / -1;">
                <div class="overview-row">
                    <div class="overview-left">
                        <div class="dash-label">卡密分布概览</div>
                        <div class="summary-pie-layout">
                            <div class="summary-pie-circle-wrap">
                                <div class="summary-pie-circle" id="cardSummaryPie">
                                    <div class="summary-pie-center">
                                        <div class="summary-pie-total" id="statTotalCards">0</div>
                                        <div class="summary-pie-sub">总卡密</div>
                                    </div>
                                </div>
                            </div>
                            <div class="summary-pie-legend">
                                <div class="legend-row">
                                    <span class="legend-dot legend-unused"></span>
                                    <span class="legend-label">未使用</span>
                                    <span class="legend-value" id="statCardsUnused">0</span>
                                    <span class="legend-percent" id="statCardsUnusedPct">0%</span>
                                </div>
                                <div class="legend-row">
                                    <span class="legend-dot legend-used"></span>
                                    <span class="legend-label">已使用</span>
                                    <span class="legend-value" id="statCardsUsed">0</span>
                                    <span class="legend-percent" id="statCardsUsedPct">0%</span>
                                </div>
                                <div class="legend-row">
                                    <span class="legend-dot legend-expired"></span>
                                    <span class="legend-label">已过期</span>
                                    <span class="legend-value" id="statCardsExpired">0</span>
                                    <span class="legend-percent" id="statCardsExpiredPct">0%</span>
                                </div>
                                <div class="legend-row">
                                    <span class="legend-dot legend-deleted"></span>
                                    <span class="legend-label">已删除</span>
                                    <span class="legend-value" id="statCardsDeleted">0</span>
                                    <span class="legend-percent" id="statCardsDeletedPct">0%</span>
                                </div>
                                <div class="legend-divider"></div>
                                <div class="legend-extra">
                                    <div>总用户：<span id="statTotalUsers">0</span></div>
                                    <div>今日新增：<span id="statTodayUsers">0</span></div>
                                    <div>本周新增：<span id="statWeekUsers">0</span></div>
                                    <div>今日激活：<span id="statTodayActivations">0</span></div>
                                    <div>本周激活：<span id="statWeekActivations">0</span></div>
                                    <div>在线用户：<span id="statOnlineTotal">0</span></div>
                                    <div>网页在线：<span id="statOnlineWeb">0</span></div>
                                    <div>APP 在线：<span id="statOnlineApp">0</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="overview-right">
                        <h2 style="font-size:16px;margin-bottom:8px;color:#a5b4fc;">地域分布（最近登录 IP 段）</h2>
                        <div class="region-chart" id="regionChart"></div>
                    </div>
                </div>
            </div>
            <div class="dash-row" style="grid-column: 1 / -1;">
                <div class="dash-group">
                    <h3>卡密结构</h3>
                    <div class="mini-chart-bars" id="chartCardsBars">
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill" id="barCardsTotal"></div>
                            </div>
                            <div class="mini-chart-value" id="valCardsTotal">0</div>
                            <div class="mini-chart-label">总卡密</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill secondary" id="barCardsUnused"></div>
                            </div>
                            <div class="mini-chart-value" id="valCardsUnused">0</div>
                            <div class="mini-chart-label">未使用</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill" id="barCardsUsed"></div>
                            </div>
                            <div class="mini-chart-value" id="valCardsUsed">0</div>
                            <div class="mini-chart-label">已使用</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill expired" id="barCardsExpired"></div>
                            </div>
                            <div class="mini-chart-value" id="valCardsExpired">0</div>
                            <div class="mini-chart-label">已过期</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill deleted" id="barCardsDeleted"></div>
                            </div>
                            <div class="mini-chart-value" id="valCardsDeleted">0</div>
                            <div class="mini-chart-label">已删除</div>
                        </div>
                    </div>
                </div>
                <div class="dash-group">
                    <h3>用户与激活趋势</h3>
                    <div class="mini-chart-bars" id="chartUsersBars">
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill" id="barUsersTotal"></div>
                            </div>
                            <div class="mini-chart-value" id="valUsersTotal">0</div>
                            <div class="mini-chart-label">总用户</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill secondary" id="barUsersWeek"></div>
                            </div>
                            <div class="mini-chart-value" id="valUsersWeek">0</div>
                            <div class="mini-chart-label">近7天新增</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill" id="barUsersToday"></div>
                            </div>
                            <div class="mini-chart-value" id="valUsersToday">0</div>
                            <div class="mini-chart-label">今日新增</div>
                        </div>
                        <div class="mini-chart-col">
                            <div class="mini-chart-bar">
                                <div class="mini-chart-bar-fill accent" id="barActToday"></div>
                            </div>
                            <div class="mini-chart-value" id="valActToday">0</div>
                            <div class="mini-chart-label">今日激活</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
            </section>

            <section class="page-screen" data-page="users">
    <!-- 用户管理 -->
    <div class="section">
        <h2>用户管理</h2>
        <div class="filter-row">
            <input type="text" id="userKeyword" placeholder="用户名" style="width:140px;">
            <button type="button" class="btn btn-primary" id="btnUserSearch">查询</button>
            <a href="#" id="btnUserExport" class="btn btn-link" target="_blank">导出用户 CSV</a>
        </div>
        <div id="userBatchToolbar" style="display:none; margin-bottom:12px; padding:14px 18px; background:rgba(74,158,255,0.08); border:1px solid rgba(74,158,255,0.25); border-radius:10px; align-items:center; gap:12px; flex-wrap:wrap;">
            <span id="userSelectedCount" style="color:#94a3b8; font-size:14px; font-weight:600;">已选 0 个用户</span>
            <span style="color:rgba(255,255,255,0.15);">|</span>
            <label style="font-size:14px; color:#cbd5e1;">加时天数：</label>
            <input type="number" id="batchExtendDays" value="30" min="1" max="3650" style="width:80px; padding:7px 10px; border:1px solid rgba(74,158,255,0.4); border-radius:6px; background:rgba(15,23,42,0.8); color:#fff; font-size:14px;">
            <button type="button" class="btn btn-primary" id="btnBatchExtendSelected" style="background:linear-gradient(135deg,#22c55e,#16a34a);">为选中用户加时</button>
            <button type="button" class="btn btn-primary" id="btnBatchExtendAll" style="background:linear-gradient(135deg,#f59e0b,#d97706);">全部用户一键加时</button>
            <span id="batchExtendResult" style="font-size:13px; color:#51cf66;"></span>
        </div>
        <table id="userTable">
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="userSelectAll" title="全选"></th>
                    <th>ID</th>
                    <th>用户名</th>
                    <th>卡密</th>
                    <th>用户状态</th>
                    <th>卡密状态</th>
                    <th>到期时间</th>
                    <th>登录IP</th>
                    <th>注册服务器</th>
                    <th>注册时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="userListBody">
                <tr><td colspan="11" class="empty">加载中...</td></tr>
            </tbody>
        </table>
        <div id="userPagination" class="pagination" style="display:none;"></div>
    </div>
            </section>

            <section class="page-screen" data-page="cards">
    <!-- 卡密管理（代理仅显示自己生成的，同时在同一区块展示试用卡记录） -->
    <div class="section">
        <h2><?php echo $isAgent ? '我生成的卡密' : '卡密管理'; ?></h2>
        <div class="toolbar">
            <label>生成数量：</label>
            <input type="number" id="genCount" value="5" min="1" max="100" style="width:70px">
            <label>长度：</label>
            <input type="number" id="genLen" value="16" min="8" max="32" style="width:60px">
            <label>卡类型：</label>
            <button type="button" class="btn btn-type active" data-type="day" id="btnTypeDay">天卡(24h)</button>
            <button type="button" class="btn btn-type" data-type="week" id="btnTypeWeek">周卡(7天)</button>
            <button type="button" class="btn btn-type" data-type="month" id="btnTypeMonth">月卡(30天)</button>
            <button type="button" class="btn btn-primary" id="btnGenerate">生成卡密</button>
        </div>
        <div class="filter-row">
            <input type="text" id="cardKeyword" placeholder="卡密/备注" style="width:140px;">
            <select id="cardStatus"><option value="all">状态：全部</option><option value="0">未使用</option><option value="1">已使用</option></select>
            <select id="cardPaused"><option value="all">暂停：全部</option><option value="0">启用</option><option value="1">已暂停</option></select>
            <select id="cardType"><option value="all">类型：全部</option><option value="day">天卡</option><option value="week">周卡</option><option value="month">月卡</option><option value="trial">试用卡</option></select>
            <button type="button" class="btn btn-primary" id="btnCardSearch">查询</button>
            <a href="#" id="btnCardExport" class="btn btn-link" target="_blank">导出卡密 CSV</a>
        </div>
        <div class="batch-toolbar" id="cardBatchToolbar" style="display:none; margin-bottom:10px; align-items:center; gap:10px; flex-wrap:wrap;">
            <span id="cardSelectedCount" style="color:#888;">已选 0 条</span>
            <button type="button" class="btn btn-small btn-warning" id="btnBatchPause">批量暂停</button>
            <button type="button" class="btn btn-small btn-success" id="btnBatchEnable">批量启用</button>
            <button type="button" class="btn btn-small btn-danger" id="btnBatchDelete">批量删除</button>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="cardSelectAll" title="全选"></th>
                    <th>ID</th>
                    <th>卡密</th>
                    <?php if (!$isAgent): ?><th>生成者</th><?php endif; ?>
                    <th>类型</th>
                    <th>状态</th>
                    <th>暂停</th>
                    <th>到期时间</th>
                    <th>备注</th>
                    <th>注册IP</th>
                    <th>登录IP</th>
                    <th>使用时间</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="cardListBody">
                <tr><td colspan="<?php echo $isAgent ? 13 : 14; ?>" class="empty">加载中...</td></tr>
            </tbody>
        </table>
        <div id="cardPagination" class="pagination" style="display:none;"></div>
    </div>
            </section>

    <?php if (!$isAgent): ?>
            <section class="page-screen" data-page="trial">
    <!-- 试用卡管理（查看领取记录 + 解锁 7 天限制） -->
    <div class="section">
        <h2>试用卡管理</h2>
        <div class="filter-row">
            <input type="text" id="trialKeyword" placeholder="卡密 / 设备ID / 设备名 / 用户名" style="width:260px;">
            <button type="button" class="btn btn-primary" id="btnTrialSearch">查询</button>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>试用卡</th>
                    <th>设备ID</th>
                    <th>设备名称</th>
                    <th>领取IP</th>
                    <th>领取时间</th>
                    <th>卡密状态</th>
                    <th>绑定账号</th>
                    <th>卡密到期时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="trialListBody">
                <tr><td colspan="10" class="empty">加载中...</td></tr>
            </tbody>
        </table>
        <div id="trialPagination" class="pagination" style="display:none;"></div>
    </div>
            </section>
    <?php endif; ?>

    <?php if (!$isAgent): ?>
            <section class="page-screen" data-page="agents">
    <!-- 代理生成的卡密（仅总管理可见，显示每条卡密由哪个代理生成） -->
    <div class="section" id="sectionAgentCards">
        <h2>代理生成的卡密</h2>
        <div class="filter-row">
            <button type="button" class="btn btn-primary" id="btnAgentCardsRefresh">刷新</button>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>卡密</th>
                    <th>类型</th>
                    <th>状态</th>
                    <th>生成代理</th>
                    <th>到期时间</th>
                    <th>备注</th>
                    <th>使用时间</th>
                    <th>创建时间</th>
                </tr>
            </thead>
            <tbody id="agentCardListBody">
                <tr><td colspan="9" class="empty">加载中...</td></tr>
            </tbody>
        </table>
        <div id="agentCardPagination" class="pagination" style="display:none;"></div>
    </div>
    
    <!-- 代理管理 -->
    <div class="section" id="sectionAgents">
        <h2>代理管理</h2>
        <div class="toolbar" style="margin-bottom:12px;">
            <input type="text" id="agentUsername" placeholder="代理用户名" style="width:140px;">
            <input type="password" id="agentPassword" placeholder="密码（至少4位）" style="width:140px;">
            <button type="button" class="btn btn-primary" id="btnAgentAdd">添加代理</button>
        </div>
        <table>
            <thead>
                <tr><th>ID</th><th>用户名</th><th>创建时间</th><th>操作</th></tr>
            </thead>
            <tbody id="agentListBody">
                <tr><td colspan="4" class="empty">加载中...</td></tr>
            </tbody>
        </table>
    </div>
            </section>
    <?php endif; ?>

    <?php if (!$isAgent): ?>
            <section class="page-screen" data-page="security">
    <!-- 黑名单 -->
    <div class="section">
        <h2>黑名单</h2>
        <div class="filter-row">
            <select id="blacklistType"><option value="ip">IP</option><option value="user">用户</option></select>
            <input type="text" id="blacklistValue" placeholder="IP 或 用户名" style="width:180px;">
            <button type="button" class="btn btn-primary" id="btnBlacklistAdd">添加</button>
        </div>
        <table>
            <thead>
                <tr><th>类型</th><th>值</th><th>添加时间</th><th>操作</th></tr>
            </thead>
            <tbody id="blacklistBody">
                <tr><td colspan="4" class="empty">加载中...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 游戏服务器 -->
    <div class="section">
        <h2>游戏服务器</h2>
        <p class="muted">录入共享服务器 IP 或域名。前台会自动连接这些服务器的 8888 端口并汇总展示所有房间号。</p>
        <div class="filter-row">
            <input type="text" id="gameServerName" placeholder="备注名称（可选）" style="width:160px;">
            <input type="text" id="gameServerHost" placeholder="服务器 IP 或域名" style="width:220px;">
            <input type="number" id="gameServerPort" value="8888" min="1" max="65535" style="width:100px;">
            <button type="button" class="btn btn-primary" id="btnGameServerAdd">添加服务器</button>
            <button type="button" class="btn btn-secondary" id="btnGameServerTestAll">一键测试</button>
            <button type="button" class="btn btn-secondary" id="btnGameServerTestSelected">测试选中</button>
            <button type="button" class="btn btn-secondary" id="btnGameServerFetchRoomsAll">获取房间号</button>
            <button type="button" class="btn btn-secondary" id="btnGameServerFetchRoomsSelected">获取选中房间</button>
            <button type="button" class="btn btn-secondary" id="btnGameServerEnableSelected">启用选中</button>
            <button type="button" class="btn btn-secondary" id="btnGameServerDisableSelected">停用选中</button>
            <button type="button" class="btn btn-danger" id="btnGameServerDeleteSelected">删除选中</button>
            <input type="text" id="gameServerKeywordFilter" placeholder="筛选名称 / IP / 来源" style="width:180px;">
            <select id="gameServerCheckFilter" style="width:130px;">
                <option value="all">全部状态</option>
                <option value="online">仅连通</option>
                <option value="offline">仅不通</option>
                <option value="untested">未测试</option>
                <option value="rooms">有房间号</option>
            </select>
        </div>
        <table>
            <thead>
                <tr><th style="width:42px;"><input type="checkbox" id="gameServerSelectAll"></th><th>名称</th><th>服务器</th><th>端口</th><th>启用</th><th>测试结果</th><th>房间号</th><th>添加时间</th><th>操作</th></tr>
            </thead>
            <tbody id="gameServerBody">
                <tr><td colspan="9" class="empty">加载中...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 页面链接配置 -->
    <div class="section">
        <h2>登录/注册链接配置</h2>
        <p class="muted">配置登录页底部的「领取试用」「购买卡密」「入群」三个入口。领取试用链接留空时，APP 会使用系统内置自动领取试用卡。</p>
        <div class="filter-row">
            <input type="text" id="settingTrialUrl" placeholder="领取试用链接（可留空）" style="width:260px;">
            <input type="text" id="settingBuyCardUrl" placeholder="购买卡密链接" style="width:260px;">
            <input type="text" id="settingDownloadUrl" placeholder="下载客户端链接（兼容旧版）" style="width:260px;">
            <input type="text" id="settingGroupUrl" placeholder="入群链接" style="width:260px;">
            <button type="button" class="btn btn-primary" id="btnSaveAppSettings">保存链接</button>
        </div>
    </div>

    <!-- 过期数据清理：定期删除过期用户与卡密（仅总管理） -->
    <div class="section">
        <h2>过期数据清理</h2>
        <p class="muted">用于缩小数据库体积：删除所有 <code>expires_at</code> 已过期的卡密，并同步删除其关联用户与登录日志。建议先点「预览」，确认无误后再执行。</p>
        <div class="filter-row" style="align-items:center;">
            <label style="font-size:13px;color:#94a3b8;">单次最多处理</label>
            <input type="number" id="cleanupLimit" value="2000" min="1" max="20000" style="width:110px;">
            <label style="font-size:13px;color:#94a3b8;">条</label>
            <button type="button" class="btn btn-secondary" id="btnCleanupPreview">预览</button>
            <button type="button" class="btn btn-danger" id="btnCleanupRun">执行清理</button>
            <span id="cleanupResult" style="margin-left:8px;"></span>
        </div>
    </div>
            </section>

            <section class="page-screen" data-page="app">
    <div class="section">
        <h2>APP 远程管理</h2>
        <p class="muted">配置 APP 打开时的更新拦截、公告弹窗、入群入口，数据与网站共用当前数据库。</p>
        <div class="stats">
            <div class="stat-box"><div class="num" id="statOnlineTotalPanel">0</div><div class="label">在线用户</div></div>
            <div class="stat-box"><div class="num" id="statOnlineAppPanel">0</div><div class="label">APP 在线</div></div>
            <div class="stat-box"><div class="num" id="statOnlineWebPanel">0</div><div class="label">网页在线</div></div>
        </div>
        <table style="margin-bottom:16px;">
            <thead>
                <tr>
                    <th>客户端</th>
                    <th>用户名</th>
                    <th>IP</th>
                    <th>最后在线</th>
                </tr>
            </thead>
            <tbody id="onlineUserListBody">
                <tr><td colspan="4" class="empty">加载中...</td></tr>
            </tbody>
        </table>
        <div class="filter-row">
            <input type="number" id="appVersionCode" placeholder="版本号 version_code" style="width:180px;">
            <input type="text" id="appVersionName" placeholder="版本名" style="width:140px;">
            <input type="text" id="appApkUrl" placeholder="新版 APK 下载地址" style="width:360px;">
        </div>
        <div class="filter-row">
            <input type="text" id="appApkUrlGithub" placeholder="GitHub APK 直链（备用）" style="width:460px;">
            <input type="text" id="appApkUrlGitee" placeholder="Gitee APK 直链（备用）" style="width:460px;">
        </div>
        <div class="filter-row">
            <input type="text" id="appUpdateTitle" placeholder="更新标题" style="width:220px;">
            <input type="text" id="appUpdateMessage" placeholder="更新说明" style="width:420px;">
            <label style="color:#94a3b8;"><input type="checkbox" id="appForceUpdate"> 强制更新</label>
            <input type="text" id="appBuyCardUrl" placeholder="购买卡密链接（APP 买卡按钮）" style="width:320px;">
        </div>
        <div class="filter-row">
            <input type="text" id="appGroupUrl" placeholder="入群链接" style="width:320px;">
        </div>
        <div class="filter-row">
            <label style="color:#94a3b8;"><input type="checkbox" id="appLoginRequired" checked> APP 需要登录</label>
            <label style="color:#94a3b8;"><input type="checkbox" id="appLoginEnabled"> 启用 APP 公共账号</label>
            <input type="text" id="appLoginUsername" placeholder="APP 登录账号" style="width:180px;">
            <input type="text" id="appLoginPassword" placeholder="APP 登录密码（会在 APP 弹窗显示）" style="width:260px;">
            <input type="text" id="appLoginTitle" placeholder="公共账号弹窗标题" style="width:220px;">
        </div>
        <div class="filter-row">
            <input type="text" id="appLoginMessage" placeholder="公共账号弹窗说明" style="width:760px;">
        </div>
        <div class="filter-row">
            <label style="color:#94a3b8;"><input type="checkbox" id="appPopupEnabled"> 打开 APP 弹窗</label>
            <input type="text" id="appPopupTitle" placeholder="弹窗标题" style="width:220px;">
            <input type="text" id="appPopupUrl" placeholder="弹窗按钮链接（可选）" style="width:320px;">
        </div>
        <div class="filter-row">
            <input type="text" id="appPopupMessage" placeholder="弹窗内容" style="width:760px;">
            <button type="button" class="btn btn-primary" id="btnSaveAppRemote">保存 APP 配置</button>
        </div>
    </div>
            </section>

            <section class="page-screen" data-page="logs">
    <!-- 操作日志 -->
    <div class="section">
        <h2>操作日志</h2>
        <table>
            <thead>
                <tr><th>ID</th><th>管理员</th><th>操作</th><th>详情</th><th>时间</th></tr>
            </thead>
            <tbody id="logListBody">
                <tr><td colspan="5" class="empty">加载中...</td></tr>
            </tbody>
        </table>
        <div id="logPagination" class="pagination" style="display:none;"></div>
    </div>
            </section>

    <?php endif; ?>

    <script>
        var API_BASE = (function() {
            var p = window.location.pathname || '';
            var i = p.indexOf('/admin');
            return i >= 0 ? p.substring(0, i) : '';
        })();
        function showMsg(text, isErr) {
            var el = document.getElementById('msg');
            el.textContent = text;
            el.className = 'msg ' + (isErr ? 'err' : 'ok');
        }
        function escapeHtml(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        // 检测为未登录/账号失效时跳转登录页并提示请重新登录
        function redirectToLoginIfAuthFailed(e) {
            var msg = (e && e.message) ? String(e.message) : '';
            if (/401|请先登录|请重新登录|账号已失效|未登录|已过期/.test(msg)) {
                var base = (typeof API_BASE !== 'undefined' && API_BASE) ? API_BASE : '';
                window.location.href = base + '/admin/index.php?msg=relogin';
                return true;
            }
            return false;
        }
        // 带超时的 fetch（不依赖 AbortController），超时后拒绝，避免一直显示「加载中」
        function fetchWithTimeout(url, options, timeoutMs) {
            timeoutMs = timeoutMs || 15000;
            var timeoutPromise = new Promise(function(_, reject) {
                setTimeout(function() {
                    reject(new Error('请求超时（' + (timeoutMs / 1000) + ' 秒），请检查网络或 API 地址'));
                }, timeoutMs);
            });
            var fetchPromise = fetch(url, options || {}).then(function(r) { return r; });
            return Promise.race([fetchPromise, timeoutPromise]);
        }

        // 地域/IP 段分布
        function loadRegionStats() {
            var chart = document.getElementById('regionChart');
            var listBox = document.getElementById('regionList');
            if (!chart) return;
            chart.innerHTML = '';
            fetch(API_BASE + '/api/index.php?module=region_stats', { credentials: 'include' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || res.code !== 0 || !res.data || !Array.isArray(res.data.regions)) return;
                    var regions = res.data.regions;
                    if (!regions.length) {
                        chart.innerHTML = '<div class="empty" style="background:none;border:0;color:#9ca3af;">暂无数据</div>';
                        return;
                    }
                    var max = 0;
                    regions.forEach(function (r) { if (r.count > max) max = r.count; });
                    max = max || 1;
                    regions.forEach(function (r, idx) {
                        var wrap = document.createElement('div');
                        wrap.className = 'region-bar-wrap';
                        var bar = document.createElement('div');
                        bar.className = 'region-bar';
                        var fill = document.createElement('div');
                        fill.className = 'region-bar-fill' + (idx === 0 ? ' hot' : '');
                        var h = 10 + (r.count / max) * 80;
                        if (h > 100) h = 100;
                        fill.style.height = h.toFixed(1) + '%';
                        bar.appendChild(fill);
                        var val = document.createElement('div');
                        val.className = 'region-bar-value';
                        val.textContent = r.count;
                        var lab = document.createElement('div');
                        lab.className = 'region-bar-label';
                        lab.textContent = r.label_cn || r.label;
                        wrap.appendChild(bar);
                        wrap.appendChild(val);
                        wrap.appendChild(lab);
                        chart.appendChild(wrap);
                    });

                    // 不再渲染右侧 TOP IP 段列表
                })
                .catch(function () {});
        }

        // 数据统计
        function loadStats() {
            fetch(API_BASE + '/api/index.php?module=stats', { credentials: 'include' })
                .then(function(r) {
                    if (r.status === 401) return r.json().then(function(res) { res._authFailed = true; return res; });
                    return r.json();
                })
                .then(function(res) {
                    if (res && res._authFailed) { redirectToLoginIfAuthFailed(new Error(res.msg || '请重新登录')); return; }
                    if (res.code !== 0 || !res.data) return;
                    var d = res.data;
                    function setStat(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }
                    var totalCardsDisplay = (typeof d.total_cards !== 'undefined') ? d.total_cards : 0;
                    var totalCardsBase = (typeof d.total_cards_real !== 'undefined') ? d.total_cards_real : totalCardsDisplay;
                    setStat('statTotalUsers', d.total_users || 0);
                    setStat('statTotalCards', totalCardsDisplay);
                    setStat('statCardsUnused', d.cards_unused || 0);
                    setStat('statCardsUsed', d.cards_used || 0);
                    setStat('statCardsExpired', d.cards_expired || 0);
                    var deleted = d.deleted_cards || 0;
                    if (typeof d.deleted_cards !== 'undefined') {
                        setStat('statCardsDeleted', deleted);
                    }
                    setStat('statTodayUsers', d.today_users || 0);
                    setStat('statWeekUsers', d.week_users || 0);
                    setStat('statTodayActivations', d.today_activations || 0);
                    setStat('statWeekActivations', d.week_activations || 0);
                    setStat('statOnlineTotal', d.online_total || 0);
                    setStat('statOnlineWeb', d.online_web || 0);
                    setStat('statOnlineApp', d.online_app || 0);
                    setStat('statOnlineTotalPanel', d.online_total || 0);
                    setStat('statOnlineWebPanel', d.online_web || 0);
                    setStat('statOnlineAppPanel', d.online_app || 0);
                    // 根据统计数据更新单一圆盘的多区域百分比
                    try {
                        // 用「当前实际卡密数 + 已删除」作为百分比和圆盘的总基数，保证四个比例加起来约等于 100%
                        var realCount = totalCardsBase || 0;
                        var totalCards = realCount + deleted;
                        var unused = d.cards_unused || 0;
                        var used = d.cards_used || 0;
                        var expired = d.cards_expired || 0;
                        var pie = document.getElementById('cardSummaryPie');

                        var pUnused = 0, pUsed = 0, pExpired = 0, pDeleted = 0;
                        if (totalCards > 0) {
                            pUnused = Math.max(0, unused) / totalCards;
                            pUsed = Math.max(0, used) / totalCards;
                            pExpired = Math.max(0, expired) / totalCards;
                            pDeleted = Math.max(0, deleted) / totalCards;
                            var sum = pUnused + pUsed + pExpired + pDeleted;
                            if (sum > 0) {
                                pUnused /= sum;
                                pUsed /= sum;
                                pExpired /= sum;
                                pDeleted /= sum;
                            }
                        }
                        var stop1 = (pUnused * 100);
                        var stop2 = (pUnused + pUsed) * 100;
                        var stop3 = (pUnused + pUsed + pExpired) * 100;

                        // 更新圆盘背景为多色区域
                        if (pie) {
                            var bg = 'radial-gradient(circle at center, #020617 55%, transparent 57%),' +
                                'conic-gradient(' +
                                'var(--pie-unused) 0 ' + stop1.toFixed(2) + '%, ' +
                                'var(--pie-used) ' + stop1.toFixed(2) + '% ' + stop2.toFixed(2) + '%, ' +
                                'var(--pie-expired) ' + stop2.toFixed(2) + '% ' + stop3.toFixed(2) + '%, ' +
                                'var(--pie-deleted) ' + stop3.toFixed(2) + '% 100%)';
                            pie.style.background = bg;
                        }

                        function setPercentText(id, value) {
                            var el = document.getElementById(id);
                            if (!el) return;
                            el.textContent = value.toFixed(0) + '%';
                        }

                        // 百分比文本（四个状态总和约等于 100%）
                        if (totalCards > 0) {
                            setPercentText('statCardsUnusedPct', pUnused * 100);
                            setPercentText('statCardsUsedPct', pUsed * 100);
                            setPercentText('statCardsExpiredPct', pExpired * 100);
                            var delPctEl2 = document.getElementById('statCardsDeletedPct');
                            if (delPctEl2) {
                                delPctEl2.textContent = (pDeleted * 100).toFixed(0) + '%';
                            }
                        } else {
                            setPercentText('statCardsUnusedPct', 0);
                            setPercentText('statCardsUsedPct', 0);
                            setPercentText('statCardsExpiredPct', 0);
                            var delPctEl3 = document.getElementById('statCardsDeletedPct');
                            if (delPctEl3) delPctEl3.textContent = '0%';
                        }

                        // 迷你柱状图：卡密结构
                        function setBarHeight(id, value, max) {
                            var el = document.getElementById(id);
                            if (!el) return;
                            max = max || 1;
                            var ratio = value <= 0 ? 0 : (value / max);
                            // 保留一点最低高度，避免为 0 时完全不可见
                            var h = value <= 0 ? 6 : (10 + ratio * 80);
                            if (h > 100) h = 100;
                            el.style.height = h.toFixed(1) + '%';
                        }

                        var maxCards = Math.max(totalCards, unused, used, expired, deleted, 1);
                        setBarHeight('barCardsTotal', totalCards, maxCards);
                        setBarHeight('barCardsUnused', unused, maxCards);
                        setBarHeight('barCardsUsed', used, maxCards);
                        setBarHeight('barCardsExpired', expired, maxCards);
                        setBarHeight('barCardsDeleted', deleted, maxCards);

                        // 数字标签：卡密结构
                        setStat('valCardsTotal', totalCards || 0);
                        setStat('valCardsUnused', unused || 0);
                        setStat('valCardsUsed', used || 0);
                        setStat('valCardsExpired', expired || 0);
                        setStat('valCardsDeleted', deleted || 0);

                        // 迷你柱状图：用户与激活
                        var totalUsers = d.total_users || 0;
                        var todayUsers = d.today_users || 0;
                        var weekUsers = d.week_users || 0;
                        var todayAct = d.today_activations || 0;
                        var maxUsers = Math.max(totalUsers, weekUsers, todayUsers, todayAct, 1);
                        setBarHeight('barUsersTotal', totalUsers, maxUsers);
                        setBarHeight('barUsersWeek', weekUsers, maxUsers);
                        setBarHeight('barUsersToday', todayUsers, maxUsers);
                        setBarHeight('barActToday', todayAct, maxUsers);

                        // 数字标签：用户与激活
                        setStat('valUsersTotal', totalUsers || 0);
                        setStat('valUsersWeek', weekUsers || 0);
                        setStat('valUsersToday', todayUsers || 0);
                        setStat('valActToday', todayAct || 0);
                    } catch (e) {
                        console.warn('更新统计圆盘失败:', e);
                    }
                }).catch(function() {});
        }

        function loadOnlineUsers() {
            var tbody = document.getElementById('onlineUserListBody');
            if (!tbody) return;
            fetch(API_BASE + '/api/index.php?module=online_users', { credentials: 'include', cache: 'no-store' })
                .then(function(r) {
                    if (r.status === 401) return r.json().then(function(res) { res._authFailed = true; return res; });
                    return r.json();
                })
                .then(function(res) {
                    if (res && res._authFailed) { redirectToLoginIfAuthFailed(new Error(res.msg || '请重新登录')); return; }
                    if (!res || res.code !== 0 || !res.data || !Array.isArray(res.data.list)) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty">加载失败</td></tr>';
                        return;
                    }
                    var list = res.data.list;
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty">暂无在线用户</td></tr>';
                        return;
                    }
                    tbody.innerHTML = list.map(function(row) {
                        var client = row.client === 'app' ? 'APP' : '网页';
                        var username = row.username ? escapeHtml(row.username) : '-';
                        var ip = row.ip ? '<span class="ip">' + escapeHtml(row.ip) + '</span>' : '-';
                        var updated = row.updated_at ? escapeHtml(row.updated_at) : '-';
                        return '<tr><td>' + client + '</td><td>' + username + '</td><td>' + ip + '</td><td>' + updated.replace(' ', '<br>') + '</td></tr>';
                    }).join('');
                })
                .catch(function(e) {
                    tbody.innerHTML = '<tr><td colspan="4" class="empty">请求失败<br><small>' + escapeHtml(String(e && e.message)) + '</small></td></tr>';
                });
        }

        var cardPage = 1;
        var cardPageSize = 10;
        var cardTotal = 0;
        var trialPage = 1;
        var trialPageSize = 10;
        var trialTotal = 0;
        var userPage = 1;
        var userPageSize = 20;
        var userTotal = 0;

        // 卡密列表
        function loadCardList(page) {
            var cardKw = document.getElementById('cardKeyword');
            var cardBody = document.getElementById('cardListBody');
            if (!cardBody) return;
            if (page !== undefined) cardPage = page;
            var keyword = (cardKw && cardKw.value) ? String(cardKw.value).trim() : '';
            var statusEl = document.getElementById('cardStatus');
            var pausedEl = document.getElementById('cardPaused');
            var typeEl = document.getElementById('cardType');
            var status = statusEl ? statusEl.value : 'all';
            var paused = pausedEl ? pausedEl.value : 'all';
            var cardType = typeEl ? typeEl.value : 'all';
            var url = API_BASE + '/api/index.php?module=card&action=list&page=' + cardPage + '&page_size=' + cardPageSize;
            if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
            if (status && status !== 'all') url += '&status=' + encodeURIComponent(status);
            if (paused && paused !== 'all') url += '&paused=' + encodeURIComponent(paused);
            fetchWithTimeout(url, { credentials: 'include' }, 15000)
                .then(function(r) {
                    if (!r.ok) {
                        var statusMsg = r.status + ' ' + (r.statusText || '');
                        if (r.status === 401) throw new Error('未登录或已过期，请重新登录');
                        throw new Error(statusMsg);
                    }
                    return r.text();
                })
                .then(function(text) {
                    var res;
                    try { res = JSON.parse(text); } catch (e) {
                        var preview = (text && text.length > 500) ? text.slice(0, 500) + '...' : (text || '');
                        throw new Error('返回非 JSON。响应预览: ' + preview);
                    }
                    return res;
                })
                .then(function(res) {
                    var tbody = document.getElementById('cardListBody');
                    if (!tbody) return;
                    try {
                        var cardCols = IS_AGENT ? 13 : 14;
                        if (res.code !== 0) {
                            tbody.innerHTML = '<tr><td colspan="' + cardCols + '" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                            return;
                        }
                        var list = (res.data && Array.isArray(res.data.list)) ? res.data.list : [];
                        // 在卡密管理中也展示试用卡（trial），并在前端本地按筛选条件过滤，避免数据库排序规则冲突
                        list = list.filter(function(row) {
                            if (cardType && cardType !== 'all') {
                                return row.card_type === cardType;
                            }
                            return true;
                        });
                        cardTotal = (res.data && res.data.total !== undefined) ? (parseInt(res.data.total, 10) || 0) : 0;
                        var total = cardTotal;
                        if (!list.length) {
                            tbody.innerHTML = '<tr><td colspan="' + cardCols + '" class="empty">暂无卡密，请先生成</td></tr>';
                            var tb = document.getElementById('cardBatchToolbar');
                            var tp = document.getElementById('cardPagination');
                            if (tb) tb.style.display = 'none';
                            if (tp) tp.style.display = 'none';
                            return;
                        }
                        var tb = document.getElementById('cardBatchToolbar');
                        var tp = document.getElementById('cardPagination');
                        if (tb) tb.style.display = 'flex';
                        if (tp) tp.style.display = 'flex';
                        tbody.innerHTML = list.map(function(row) {
                            var boundAt = row.bound_at ? row.bound_at.replace(' ', '<br>') : '-';
                            var boundIp = row.bound_ip ? '<span class="ip">' + escapeHtml(row.bound_ip) + '</span>' : '-';
                            var loginIps = (row.login_ips && row.login_ips.length) ? row.login_ips.map(function(ip) { return '<span class="ip">' + escapeHtml(ip) + '</span>'; }).join('<br>') : '-';
                            var expiresAt = row.expires_at ? row.expires_at.replace(' ', '<br>') : (row.card_type === 'day' ? '激活后1天' : (row.card_type === 'week' ? '激活后7天' : (row.card_type === 'month' ? '激活后30天' : '永久')));
                            var typeText = row.card_type_text ? escapeHtml(row.card_type_text) : '-';
                            var statusCls = (row.status_text === '已过期') ? 'expired' : ('status-' + row.status);
                            var paused = row.paused ? 1 : 0;
                            var pauseText = paused ? '已暂停' : '启用';
                            var pauseCls = paused ? 'paused' : '';
                            var btnPause = paused ? '' : '<button type="button" class="btn btn-small btn-warning btn-pause" data-id="' + row.id + '">暂停</button> ';
                            var btnEnable = paused ? '<button type="button" class="btn btn-small btn-success btn-enable" data-id="' + row.id + '">启用</button> ' : '';
                            var btnDeduct = row.expires_at ? '<button type="button" class="btn btn-small btn-secondary btn-card-deduct" data-id="' + row.id + '">扣除时间</button> ' : '';
                            var remarkText = row.remark ? escapeHtml(row.remark) : '-';
                            var creatorCell = IS_AGENT ? '' : ('<td>' + escapeHtml(row.creator_username || '总管理') + '</td>');
                            return '<tr>' +
                                '<td><input type="checkbox" class="card-checkbox" value="' + row.id + '"></td>' +
                                '<td>' + row.id + '</td>' +
                                '<td><code>' + escapeHtml(row.card_code) + '</code></td>' +
                                creatorCell +
                                '<td>' + typeText + '</td>' +
                                '<td class="' + statusCls + '">' + escapeHtml(row.status_text) + '</td>' +
                                '<td class="' + pauseCls + '">' + pauseText + '</td>' +
                                '<td>' + expiresAt + '</td>' +
                                '<td><span class="card-remark" data-id="' + row.id + '" data-remark="' + escapeHtml((row.remark || '').replace(/"/g, '&quot;')) + '">' + remarkText + '</span> <button type="button" class="btn btn-small btn-link btn-remark-edit" data-id="' + row.id + '" data-remark="' + escapeHtml((row.remark || '').replace(/"/g, '&quot;')) + '" style="padding:2px 6px;font-size:12px;">编辑</button></td>' +
                                '<td>' + boundIp + '</td>' +
                                '<td>' + loginIps + '</td>' +
                                '<td>' + boundAt + '</td>' +
                                '<td>' + (row.created_at || '').replace(' ', '<br>') + '</td>' +
                                '<td>' + btnPause + btnEnable + btnDeduct + '<button type="button" class="btn btn-danger btn-small btn-card-del" data-id="' + row.id + '">删除</button></td>' +
                                '</tr>';
                        }).join('');
                    document.getElementById('cardSelectAll').checked = false;
                    tbody.querySelectorAll('.card-checkbox').forEach(function(cb) {
                        cb.onchange = updateCardSelectedCount;
                    });
                    document.getElementById('cardSelectAll').onchange = function() {
                        tbody.querySelectorAll('.card-checkbox').forEach(function(cb) { cb.checked = document.getElementById('cardSelectAll').checked; });
                        updateCardSelectedCount();
                    };
                    updateCardSelectedCount();
                    tbody.querySelectorAll('.btn-card-del').forEach(function(btn) {
                        btn.onclick = function() {
                            if (!confirm('确定删除卡密？')) return;
                            deleteCard(btn.getAttribute('data-id'));
                        };
                    });
                    tbody.querySelectorAll('.btn-pause').forEach(function(btn) {
                        btn.onclick = function() { pauseCard(btn.getAttribute('data-id')); };
                    });
                    tbody.querySelectorAll('.btn-enable').forEach(function(btn) {
                        btn.onclick = function() { enableCard(btn.getAttribute('data-id')); };
                    });
                    tbody.querySelectorAll('.btn-remark-edit').forEach(function(btn) {
                        btn.onclick = function() {
                            var id = btn.getAttribute('data-id');
                            var oldRemark = (btn.getAttribute('data-remark') || '').replace(/&quot;/g, '"');
                            var newRemark = prompt('编辑备注', oldRemark);
                            if (newRemark === null) return;
                            fetch(API_BASE + '/api/index.php?module=card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'update_remark', id: parseInt(id, 10), remark: newRemark }) })
                                .then(function(r) { return r.json(); }).then(function(res) {
                                if (res.code === 0) { showMsg('备注已更新'); loadCardList(); } else { showMsg(res.msg || '更新失败', true); }
                            }).catch(function() { showMsg('网络错误', true); });
                        };
                    });
                    tbody.querySelectorAll('.btn-card-deduct').forEach(function(btn) {
                        btn.onclick = function() {
                            var cid = btn.getAttribute('data-id');
                            if (!cid) return;
                            var daysStr = prompt('扣除天数（从当前到期时间往前减）', '1');
                            if (daysStr === null) return;
                            var days = parseInt(daysStr, 10);
                            if (isNaN(days) || days < 1) { showMsg('请输入正整数', true); return; }
                            fetch(API_BASE + '/api/index.php?module=card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'deduct_expire', id: parseInt(cid, 10), days: days }) })
                                .then(function(r) { return r.json(); }).then(function(res) {
                                if (res.code === 0) {
                                    showMsg('扣除成功，新到期时间：' + (res.data && res.data.new_expires_at ? res.data.new_expires_at : ''));
                                    loadCardList();
                                } else { showMsg(res.msg || '扣除失败', true); }
                            }).catch(function() { showMsg('网络错误', true); });
                        };
                    });
                    var totalPages = Math.max(1, Math.ceil(total / cardPageSize));
                    var pagEl = document.getElementById('cardPagination');
                    pagEl.innerHTML = '<span>共 ' + total + ' 条</span><button type="button" id="cardPrev">上一页</button><span>第 ' + cardPage + ' / ' + totalPages + ' 页</span><button type="button" id="cardNext">下一页</button>';
                    document.getElementById('cardPrev').disabled = cardPage <= 1;
                    document.getElementById('cardNext').disabled = cardPage >= totalPages;
                    document.getElementById('cardPrev').onclick = function() { if (cardPage > 1) loadCardList(cardPage - 1); };
                    document.getElementById('cardNext').onclick = function() { if (cardPage < totalPages) loadCardList(cardPage + 1); };
                    } catch (err) {
                        tbody.innerHTML = '<tr><td colspan="' + (IS_AGENT ? 13 : 14) + '" class="empty">加载失败<br><small>' + escapeHtml(String(err && err.message)) + '</small></td></tr>';
                        var pag = document.getElementById('cardPagination');
                        if (pag) pag.style.display = 'none';
                    }
                }).catch(function(e) {
                    if (redirectToLoginIfAuthFailed(e)) return;
                    console.error('[卡密列表]', e);
                    var tbody = document.getElementById('cardListBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="' + (IS_AGENT ? 13 : 14) + '" class="empty">请求失败<br><small>' + escapeHtml(String(e && e.message)) + '</small></td></tr>';
                    var pag = document.getElementById('cardPagination');
                    if (pag) pag.style.display = 'none';
                });
        }
        var btnCardSearch = document.getElementById('btnCardSearch');
        if (btnCardSearch) btnCardSearch.onclick = function() { loadCardList(1); };
        var btnCardExport = document.getElementById('btnCardExport');
        if (btnCardExport) btnCardExport.onclick = function(e) {
            e.preventDefault();
            var cardKw = document.getElementById('cardKeyword');
            var keyword = (cardKw && cardKw.value) ? String(cardKw.value).trim() : '';
            var statusEl = document.getElementById('cardStatus');
            var pausedEl = document.getElementById('cardPaused');
            var typeEl = document.getElementById('cardType');
            var status = statusEl ? statusEl.value : 'all';
            var paused = pausedEl ? pausedEl.value : 'all';
            var cardType = typeEl ? typeEl.value : 'all';
            var url = API_BASE + '/api/index.php?module=export&type=cards';
            if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
            if (status && status !== 'all') url += '&status=' + encodeURIComponent(status);
            if (paused && paused !== 'all') url += '&paused=' + encodeURIComponent(paused);
            if (cardType && cardType !== 'all') url += '&card_type=' + encodeURIComponent(cardType);
            window.open(url, '_blank');
        };
        function getSelectedCardIds() {
            var ids = [];
            document.querySelectorAll('.card-checkbox:checked').forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });
            return ids;
        }
        function updateCardSelectedCount() {
            var total = document.querySelectorAll('.card-checkbox').length;
            var n = getSelectedCardIds().length;
            var el = document.getElementById('cardSelectedCount');
            if (el) el.textContent = '已选 ' + n + ' 条';
            var allCb = document.getElementById('cardSelectAll');
            if (allCb) { allCb.checked = total > 0 && n === total; allCb.indeterminate = n > 0 && n < total; }
        }
        var btnBatchPause = document.getElementById('btnBatchPause');
        if (btnBatchPause) btnBatchPause.onclick = function() {
            var ids = getSelectedCardIds();
            if (ids.length === 0) { showMsg('请先勾选要操作的卡密', true); return; }
            if (!confirm('确定暂停选中的 ' + ids.length + ' 条卡密？')) return;
            fetch(API_BASE + '/api/index.php?module=card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'pause', ids: ids }) })
                .then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('已暂停 ' + (res.data && res.data.affected) + ' 条'); loadStats(); loadCardList(); loadUserList(); } else { showMsg(res.msg || '操作失败', true); }
            }).catch(function() { showMsg('网络错误', true); });
        };
        var btnBatchEnable = document.getElementById('btnBatchEnable');
        if (btnBatchEnable) btnBatchEnable.onclick = function() {
            var ids = getSelectedCardIds();
            if (ids.length === 0) { showMsg('请先勾选要操作的卡密', true); return; }
            if (!confirm('确定启用选中的 ' + ids.length + ' 条卡密？')) return;
            fetch(API_BASE + '/api/index.php?module=card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'enable', ids: ids }) })
                .then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('已启用 ' + (res.data && res.data.affected) + ' 条'); loadStats(); loadCardList(); loadUserList(); } else { showMsg(res.msg || '操作失败', true); }
            }).catch(function() { showMsg('网络错误', true); });
        };
        var btnBatchDelete = document.getElementById('btnBatchDelete');
        if (btnBatchDelete) btnBatchDelete.onclick = function() {
            var ids = getSelectedCardIds();
            if (ids.length === 0) { showMsg('请先勾选要删除的卡密', true); return; }
            if (!confirm('确定删除选中的 ' + ids.length + ' 条卡密？此操作不可恢复。')) return;
            fetch(API_BASE + '/api/index.php?module=card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'delete', ids: ids }) })
                .then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('已删除 ' + (res.data && res.data.affected) + ' 条'); loadStats(); loadCardList(); loadUserList(); } else { showMsg(res.msg || '删除失败', true); }
            }).catch(function() { showMsg('网络错误', true); });
        };
        var selectedCardType = 'day';
        document.querySelectorAll('.btn-type').forEach(function(btn) {
            btn.onclick = function() {
                document.querySelectorAll('.btn-type').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                selectedCardType = btn.getAttribute('data-type');
            };
        });
        function deleteCard(id) {
            fetch(API_BASE + '/api/index.php?module=card', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'delete', id: parseInt(id, 10) })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('卡密删除成功'); loadCardList(); loadUserList(); } else { showMsg(res.msg || '删除失败', true); }
            }).catch(function() { showMsg('网络错误', true); });
        }
        function pauseCard(id) {
            var cid = parseInt(id, 10);
            if (!cid) { showMsg('无法获取卡密ID', true); return; }
            var url = API_BASE + '/api/index.php?module=card&action=pause&id=' + cid;
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'pause', id: cid })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('已暂停'); loadCardList(); loadUserList(); } else { showMsg(res.msg || '操作失败', true); if (res.code === 404) loadUserList(); }
            }).catch(function() { showMsg('网络错误', true); });
        }
        function enableCard(id) {
            var cid = parseInt(id, 10);
            if (!cid) { showMsg('无法获取卡密ID', true); return; }
            var url = API_BASE + '/api/index.php?module=card&action=enable&id=' + cid;
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'enable', id: cid })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('已启用'); loadCardList(); loadUserList(); } else { showMsg(res.msg || '操作失败', true); if (res.code === 404) loadUserList(); }
            }).catch(function() { showMsg('网络错误', true); });
        }
        window.userPauseCard = pauseCard;
        window.userEnableCard = enableCard;
        var btnGenerate = document.getElementById('btnGenerate');
        if (btnGenerate) btnGenerate.onclick = function() {
            var genCount = document.getElementById('genCount');
            var genLen = document.getElementById('genLen');
            var count = (genCount && genCount.value) ? (parseInt(genCount.value, 10) || 5) : 5;
            var len = (genLen && genLen.value) ? (parseInt(genLen.value, 10) || 16) : 16;
            fetch(API_BASE + '/api/index.php?module=card', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'generate', count: count, length: len, type: selectedCardType })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) {
                    showMsg('成功生成 ' + (res.data && res.data.count) + ' 个' + (res.data && res.data.card_type_text ? res.data.card_type_text : '') + '卡密');
                    loadStats();
                    loadCardList(1);
                } else {
                    showMsg(res.msg || '生成失败', true);
                }
            }).catch(function() { showMsg('网络错误', true); });
        };

        // 试用卡领取记录列表
        function loadTrialList(page) {
            var trialKw = document.getElementById('trialKeyword');
            var body = document.getElementById('trialListBody');
            if (!body) return;
            if (page !== undefined) trialPage = page;
            var keyword = (trialKw && trialKw.value) ? String(trialKw.value).trim() : '';
            var url = API_BASE + '/api/index.php?module=trial_card_admin&action=list&page=' + trialPage + '&page_size=' + trialPageSize;
            if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
            fetchWithTimeout(url, { credentials: 'include' }, 15000)
                .then(function(r) {
                    if (!r.ok) {
                        if (r.status === 401) throw new Error('未登录或已过期，请重新登录');
                        throw new Error(r.status + ' ' + (r.statusText || ''));
                    }
                    return r.text();
                })
                .then(function(text) {
                    var res;
                    try { res = JSON.parse(text); } catch (e) {
                        var preview = (text && text.length > 500) ? text.slice(0, 500) + '...' : (text || '');
                        throw new Error('返回非 JSON。响应预览: ' + preview);
                    }
                    return res;
                })
                .then(function(res) {
                    var tbody = document.getElementById('trialListBody');
                    if (!tbody) return;
                    try {
                        if (res.code !== 0) {
                            tbody.innerHTML = '<tr><td colspan="10" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                            var pag = document.getElementById('trialPagination');
                            if (pag) pag.style.display = 'none';
                            return;
                        }
                        var list = (res.data && Array.isArray(res.data.list)) ? res.data.list : [];
                        trialTotal = (res.data && res.data.total !== undefined) ? (parseInt(res.data.total, 10) || 0) : 0;
                        var total = trialTotal;
                        if (!list.length) {
                            tbody.innerHTML = '<tr><td colspan="10" class="empty">暂无试用卡领取记录</td></tr>';
                            var pag = document.getElementById('trialPagination');
                            if (pag) pag.style.display = 'none';
                            return;
                        }
                        var pag = document.getElementById('trialPagination');
                        if (pag) pag.style.display = 'flex';
                        tbody.innerHTML = list.map(function(row) {
                            var cardCode = row.card_code ? '<code>' + escapeHtml(row.card_code) + '</code>' : '-';
                            var devId = row.device_id ? escapeHtml(row.device_id) : '-';
                            var devName = row.device_name ? escapeHtml(row.device_name) : '-';
                            var ip = row.ip ? '<span class="ip">' + escapeHtml(row.ip) + '</span>' : '-';
                            var createdAt = row.created_at ? row.created_at.replace(' ', '<br>') : '-';
                            var statusText = row.card_status_text ? escapeHtml(row.card_status_text) : '-';
                            var username = row.card_username ? escapeHtml(row.card_username) : '-';
                            var expAt = row.card_expires_at ? row.card_expires_at.replace(' ', '<br>') : '-';
                            var rawDeviceId = row.device_id ? String(row.device_id) : '';
                            var dataDevice = rawDeviceId.replace(/"/g, '&quot;');
                            return '<tr>' +
                                '<td>' + row.id + '</td>' +
                                '<td>' + cardCode + '</td>' +
                                '<td>' + devId + '</td>' +
                                '<td>' + devName + '</td>' +
                                '<td>' + ip + '</td>' +
                                '<td>' + createdAt + '</td>' +
                                '<td>' + statusText + '</td>' +
                                '<td>' + username + '</td>' +
                                '<td>' + expAt + '</td>' +
                                '<td><button type="button" class="btn btn-small btn-warning btn-trial-unlock" data-id="' + row.id + '" data-device="' + dataDevice + '">解锁</button></td>' +
                                '</tr>';
                        }).join('');
                        var totalPages = Math.max(1, Math.ceil(total / trialPageSize));
                        var pagEl = document.getElementById('trialPagination');
                        if (!pagEl) return;
                        pagEl.innerHTML = '<span>共 ' + total + ' 条</span><button type="button" id="trialPrev">上一页</button><span>第 ' + trialPage + ' / ' + totalPages + ' 页</span><button type="button" id="trialNext">下一页</button>';
                        var prev = document.getElementById('trialPrev');
                        var next = document.getElementById('trialNext');
                        if (prev) { prev.disabled = trialPage <= 1; prev.onclick = function() { if (trialPage > 1) loadTrialList(trialPage - 1); }; }
                        if (next) { next.disabled = trialPage >= totalPages; next.onclick = function() { if (trialPage < totalPages) loadTrialList(trialPage + 1); }; }
                        // 绑定解锁按钮事件
                        tbody.querySelectorAll('.btn-trial-unlock').forEach(function(btn) {
                            btn.onclick = function() {
                                var id = btn.getAttribute('data-id');
                                var deviceId = btn.getAttribute('data-device') || '';
                                if (!id) return;
                                var msg = '确定为该设备解锁试用卡领取限制？';
                                if (deviceId) {
                                    msg += '\n设备ID：' + deviceId;
                                }
                                if (!confirm(msg)) return;
                                fetch(API_BASE + '/api/index.php?module=trial_card_admin', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    credentials: 'include',
                                    body: JSON.stringify({ action: 'unlock', id: parseInt(id, 10) })
                                }).then(function(r) { return r.json(); }).then(function(res) {
                                    if (res.code === 0) {
                                        showMsg(res.msg || '解锁成功');
                                        loadTrialList(trialPage);
                                    } else {
                                        showMsg(res.msg || '解锁失败', true);
                                    }
                                }).catch(function() {
                                    showMsg('网络错误', true);
                                });
                            };
                        });
                    } catch (err) {
                        tbody.innerHTML = '<tr><td colspan="10" class="empty">加载失败<br><small>' + escapeHtml(String(err && err.message)) + '</small></td></tr>';
                        var pag = document.getElementById('trialPagination');
                        if (pag) pag.style.display = 'none';
                    }
                })
                .catch(function(e) {
                    if (redirectToLoginIfAuthFailed(e)) return;
                    console.error('[试用卡记录]', e);
                    var tbody = document.getElementById('trialListBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="empty">请求失败<br><small>' + escapeHtml(String(e && e.message)) + '</small></td></tr>';
                    var pag = document.getElementById('trialPagination');
                    if (pag) pag.style.display = 'none';
                });
        }
        var btnTrialSearch = document.getElementById('btnTrialSearch');
        if (btnTrialSearch) btnTrialSearch.onclick = function() { loadTrialList(1); };

        // 用户列表
        function loadUserList(page) {
            var userKw = document.getElementById('userKeyword');
            var userBody = document.getElementById('userListBody');
            if (!userBody) return;
            if (page !== undefined) userPage = page;
            var keyword = (userKw && userKw.value) ? String(userKw.value).trim() : '';
            var url = API_BASE + '/api/index.php?module=admin_users&action=list&page=' + userPage + '&page_size=' + userPageSize;
            if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
            fetchWithTimeout(url, { credentials: 'include' }, 15000)
                .then(function(r) {
                    if (!r.ok) {
                        if (r.status === 401) throw new Error('未登录或已过期，请重新登录');
                        throw new Error(r.status + ' ' + (r.statusText || ''));
                    }
                    return r.text();
                })
                .then(function(text) {
                    var res;
                    try { res = JSON.parse(text); } catch (e) {
                        var preview = (text && text.length > 500) ? text.slice(0, 500) + '...' : (text || '');
                        throw new Error('返回非 JSON。响应预览: ' + preview);
                    }
                    return res;
                })
                .then(function(res) {
                    var tbody = document.getElementById('userListBody');
                    if (!tbody) return;
                    try {
                        if (res.code !== 0) {
                            tbody.innerHTML = '<tr><td colspan="11" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                            return;
                        }
                        var list = (res.data && Array.isArray(res.data.list)) ? res.data.list : [];
                        userTotal = (res.data && res.data.total !== undefined) ? (parseInt(res.data.total, 10) || 0) : 0;
                        var total = userTotal;
                        if (!list.length) {
                            tbody.innerHTML = '<tr><td colspan="11" class="empty">暂无注册用户</td></tr>';
                            var up = document.getElementById('userPagination');
                            if (up) up.style.display = 'none';
                            var ubt = document.getElementById('userBatchToolbar');
                            if (ubt) ubt.style.display = 'none';
                            return;
                        }
                        var up = document.getElementById('userPagination');
                        if (up) up.style.display = 'flex';
                        var ubt = document.getElementById('userBatchToolbar');
                        if (ubt) ubt.style.display = 'flex';
                        tbody.innerHTML = list.map(function(row) {
                        var loginIps = (row.login_ips && row.login_ips.length) ? row.login_ips.map(function(ip) { return '<span class="ip">' + escapeHtml(ip) + '</span>'; }).join('<br>') : '-';
                        var cardStatus = row.card_valid ? '<span style="color:#51cf66">有效</span>' : '<span class="invalid">已删除/已过期</span>';
                        var expiresAt = row.expires_at ? row.expires_at.replace(' ', '<br>') : (row.card_key_id ? '永久' : '-');
                        var cardId = row.card_key_id ? row.card_key_id : '';
                        var statusText = row.user_status || '-';
                        var statusClass = statusText === '正常' ? 'status-ok' : (statusText === '已暂停' ? 'status-pause' : (statusText === '已过期' ? 'status-invalid' : ''));
                        var registerServer = (row.register_server && String(row.register_server).trim()) ? escapeHtml(row.register_server) : '-';
                        return '<tr>' +
                            '<td><input type="checkbox" class="user-checkbox" value="' + row.id + '"></td>' +
                            '<td>' + row.id + '</td>' +
                            '<td>' + escapeHtml(row.username) + '</td>' +
                            '<td><code>' + escapeHtml(row.card_code || '-') + '</code></td>' +
                            '<td><span class="user-status ' + statusClass + '">' + escapeHtml(statusText) + '</span></td>' +
                            '<td>' + cardStatus + '</td>' +
                            '<td>' + expiresAt + '</td>' +
                            '<td>' + loginIps + '</td>' +
                            '<td>' + registerServer + '</td>' +
                            '<td>' + (row.created_at || '').replace(' ', '<br>') + '</td>' +
                            '<td><button type="button" class="btn btn-small btn-primary btn-user-extend" data-id="' + row.id + '">加时</button> ' +
                                '<button type="button" class="btn btn-danger btn-small btn-user-del" data-id="' + row.id + '" data-name="' + escapeHtml(row.username) + '">删除</button></td>' +
                            '</tr>';
                    }).join('');
                    // Bind user checkboxes
                    var userSelectAllCb = document.getElementById('userSelectAll');
                    if (userSelectAllCb) userSelectAllCb.checked = false;
                    tbody.querySelectorAll('.user-checkbox').forEach(function(cb) {
                        cb.onchange = updateUserSelectedCount;
                    });
                    if (userSelectAllCb) {
                        userSelectAllCb.onchange = function() {
                            tbody.querySelectorAll('.user-checkbox').forEach(function(cb) { cb.checked = userSelectAllCb.checked; });
                            updateUserSelectedCount();
                        };
                    }
                    updateUserSelectedCount();
                    var totalPages = Math.max(1, Math.ceil(total / userPageSize));
                    var pagEl = document.getElementById('userPagination');
                    pagEl.innerHTML = '<span>共 ' + total + ' 条</span><button type="button" id="userPrev">上一页</button><span>第 ' + userPage + ' / ' + totalPages + ' 页</span><button type="button" id="userNext">下一页</button>';
                    document.getElementById('userPrev').disabled = userPage <= 1;
                    document.getElementById('userNext').disabled = userPage >= totalPages;
                    document.getElementById('userPrev').onclick = function() { if (userPage > 1) loadUserList(userPage - 1); };
                    document.getElementById('userNext').onclick = function() { if (userPage < totalPages) loadUserList(userPage + 1); };
                    } catch (err) {
                        tbody.innerHTML = '<tr><td colspan="11" class="empty">加载失败<br><small>' + escapeHtml(String(err && err.message)) + '</small></td></tr>';
                        var up = document.getElementById('userPagination');
                        if (up) up.style.display = 'none';
                    }
                }).catch(function(e) {
                    if (redirectToLoginIfAuthFailed(e)) return;
                    console.error('[用户列表]', e);
                    var tbody = document.getElementById('userListBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="11" class="empty">请求失败<br><small>' + escapeHtml(String(e && e.message)) + '</small></td></tr>';
                    var pag = document.getElementById('userPagination');
                    if (pag) pag.style.display = 'none';
                });
        }
        // 用户列表：事件委托仅处理删除（暂停/启用已用内联 onclick）
        document.getElementById('userTable').addEventListener('click', function(e) {
            var btn = e.target;
            var tableEl = document.getElementById('userTable');
            while (btn && btn !== tableEl) {
                if (btn.classList) {
                    // 删除用户
                    if (btn.classList.contains('btn-user-del')) {
                        e.preventDefault();
                        if (!confirm('确定删除用户「' + (btn.getAttribute('data-name') || '') + '」？')) return;
                        var delId = btn.getAttribute('data-id');
                        if (!delId) return;
                        fetch(API_BASE + '/api/index.php?module=admin_users', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'include',
                            body: JSON.stringify({ action: 'delete', id: parseInt(delId, 10) })
                        }).then(function(r) { return r.json(); }).then(function(res) {
                            if (res.code === 0) { showMsg('用户删除成功'); loadStats(); loadUserList(); } else { showMsg(res.msg || '删除失败', true); }
                        }).catch(function() { showMsg('网络错误', true); });
                        return;
                    }
                    // 用户加时（以天为单位）
                    if (btn.classList.contains('btn-user-extend')) {
                        e.preventDefault();
                        var uid = btn.getAttribute('data-id');
                        if (!uid) return;
                        var input = prompt('请输入要为该用户增加的天数（正整数，例如 1、7、30）', '1');
                        if (input === null) return;
                        input = input.trim();
                        if (!input) {
                            showMsg('请输入加时天数', true);
                            return;
                        }
                        var days = parseInt(input, 10);
                        if (!days || days <= 0) {
                            showMsg('加时天数必须为正整数', true);
                            return;
                        }
                        fetch(API_BASE + '/api/index.php?module=admin_users', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'include',
                            body: JSON.stringify({ action: 'extend_expire', user_id: parseInt(uid, 10), days: days })
                        }).then(function(r) { return r.json(); }).then(function(res) {
                            if (res.code === 0) {
                                var newExp = res.data && res.data.new_expires_at ? res.data.new_expires_at : '';
                                showMsg('加时成功，新的到期时间：' + newExp);
                                alert('加时成功！新的到期时间：\n' + (newExp || '未知'));
                                loadStats();
                                loadUserList();
                                loadCardList();
                            } else {
                                showMsg(res.msg || '加时失败', true);
                                alert('加时失败：' + (res.msg || '未知错误'));
                            }
                        }).catch(function() {
                            showMsg('网络错误', true);
                            alert('加时失败：网络错误或服务器无响应');
                        });
                        return;
                    }
                }
                btn = btn.parentNode;
            }
        });
        // 用户复选框：选中计数
        function getSelectedUserIds() {
            var ids = [];
            document.querySelectorAll('.user-checkbox:checked').forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });
            return ids;
        }
        function updateUserSelectedCount() {
            var total = document.querySelectorAll('.user-checkbox').length;
            var n = getSelectedUserIds().length;
            var el = document.getElementById('userSelectedCount');
            if (el) el.textContent = '已选 ' + n + ' 个用户';
            var allCb = document.getElementById('userSelectAll');
            if (allCb) { allCb.checked = total > 0 && n === total; allCb.indeterminate = n > 0 && n < total; }
        }
        // 批量加时：为选中用户
        var btnBatchExtendSelected = document.getElementById('btnBatchExtendSelected');
        if (btnBatchExtendSelected) btnBatchExtendSelected.onclick = function() {
            var ids = getSelectedUserIds();
            if (ids.length === 0) { showMsg('请先勾选要加时的用户', true); return; }
            var daysEl = document.getElementById('batchExtendDays');
            var days = daysEl ? parseInt(daysEl.value, 10) : 0;
            if (!days || days <= 0) { showMsg('请输入正整数天数', true); return; }
            if (!confirm('确定为选中的 ' + ids.length + ' 个用户各加 ' + days + ' 天？')) return;
            btnBatchExtendSelected.disabled = true;
            var resultEl = document.getElementById('batchExtendResult');
            if (resultEl) resultEl.textContent = '处理中...';
            fetch(API_BASE + '/api/index.php?module=admin_users', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'batch_extend_expire', user_ids: ids, days: days })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) {
                    var msg = '批量加时成功！更新了 ' + (res.data && res.data.affected || 0) + ' 个卡密，每个加 ' + days + ' 天';
                    showMsg(msg);
                    if (resultEl) { resultEl.style.color = '#51cf66'; resultEl.textContent = msg; }
                    loadStats(); loadUserList(); loadCardList();
                } else {
                    showMsg(res.msg || '批量加时失败', true);
                    if (resultEl) { resultEl.style.color = '#ff6b6b'; resultEl.textContent = res.msg || '失败'; }
                }
            }).catch(function() {
                showMsg('网络错误', true);
                if (resultEl) { resultEl.style.color = '#ff6b6b'; resultEl.textContent = '网络错误'; }
            }).then(function() { btnBatchExtendSelected.disabled = false; });
        };
        // 批量加时：全部用户一键加时
        var btnBatchExtendAll = document.getElementById('btnBatchExtendAll');
        if (btnBatchExtendAll) btnBatchExtendAll.onclick = function() {
            var daysEl = document.getElementById('batchExtendDays');
            var days = daysEl ? parseInt(daysEl.value, 10) : 0;
            if (!days || days <= 0) { showMsg('请输入正整数天数', true); return; }
            if (!confirm('⚠️ 确定为【全部用户】一键加 ' + days + ' 天？\n\n此操作将影响所有已绑定卡密的用户，请确认！')) return;
            if (!confirm('再次确认：全部用户加 ' + days + ' 天，是否继续？')) return;
            btnBatchExtendAll.disabled = true;
            var resultEl = document.getElementById('batchExtendResult');
            if (resultEl) resultEl.textContent = '处理中...';
            fetch(API_BASE + '/api/index.php?module=admin_users', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'batch_extend_expire', select_all: true, days: days })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) {
                    var msg = '全部加时成功！更新了 ' + (res.data && res.data.affected || 0) + ' 个卡密，每个加 ' + days + ' 天';
                    showMsg(msg);
                    if (resultEl) { resultEl.style.color = '#51cf66'; resultEl.textContent = msg; }
                    loadStats(); loadUserList(); loadCardList();
                } else {
                    showMsg(res.msg || '批量加时失败', true);
                    if (resultEl) { resultEl.style.color = '#ff6b6b'; resultEl.textContent = res.msg || '失败'; }
                }
            }).catch(function() {
                showMsg('网络错误', true);
                if (resultEl) { resultEl.style.color = '#ff6b6b'; resultEl.textContent = '网络错误'; }
            }).then(function() { btnBatchExtendAll.disabled = false; });
        };

        var btnUserSearch = document.getElementById('btnUserSearch');
        if (btnUserSearch) btnUserSearch.onclick = function() { loadUserList(1); };
        var btnUserExport = document.getElementById('btnUserExport');
        if (btnUserExport) btnUserExport.onclick = function(e) {
            e.preventDefault();
            var userKw = document.getElementById('userKeyword');
            var keyword = (userKw && userKw.value) ? String(userKw.value).trim() : '';
            var url = API_BASE + '/api/index.php?module=export&type=users';
            if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
            window.open(url, '_blank');
        };

        // 黑名单
        function loadBlacklist() {
            fetch(API_BASE + '/api/index.php?module=blacklist&action=list', { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var tbody = document.getElementById('blacklistBody');
                    if (res.code !== 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                        return;
                    }
                    var list = res.data && res.data.list ? res.data.list : [];
                    if (list.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty">暂无黑名单</td></tr>';
                        return;
                    }
                    tbody.innerHTML = list.map(function(row) {
                        var typeText = row.type === 'ip' ? 'IP' : '用户';
                        return '<tr><td>' + escapeHtml(typeText) + '</td><td><code>' + escapeHtml(row.value) + '</code></td><td>' + (row.created_at || '').replace(' ', '<br>') + '</td><td><button type="button" class="btn btn-danger btn-small btn-blacklist-del" data-id="' + row.id + '">删除</button></td></tr>';
                    }).join('');
                    tbody.querySelectorAll('.btn-blacklist-del').forEach(function(btn) {
                        btn.onclick = function() {
                            if (!confirm('确定移出黑名单？')) return;
                            var id = btn.getAttribute('data-id');
                            fetch(API_BASE + '/api/index.php?module=blacklist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'delete', id: parseInt(id, 10) }) })
                                .then(function(r) { return r.json(); }).then(function(res) {
                                if (res.code === 0) { showMsg('已移除'); loadBlacklist(); } else { showMsg(res.msg || '操作失败', true); }
                            }).catch(function() { showMsg('网络错误', true); });
                        };
                    });
                }).catch(function() {
                    document.getElementById('blacklistBody').innerHTML = '<tr><td colspan="4" class="empty">请求失败</td></tr>';
                });
        }
        var btnBlacklistAdd = document.getElementById('btnBlacklistAdd');
        if (btnBlacklistAdd) btnBlacklistAdd.onclick = function() {
            var typeEl = document.getElementById('blacklistType');
            var valueEl = document.getElementById('blacklistValue');
            var type = typeEl ? typeEl.value : 'ip';
            var value = (valueEl && valueEl.value) ? String(valueEl.value).trim() : '';
            if (!value) { showMsg(type === 'ip' ? '请输入IP' : '请输入用户名', true); return; }
            fetch(API_BASE + '/api/index.php?module=blacklist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ action: 'add', type: type, value: value }) })
                .then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) { showMsg('添加成功'); if (valueEl) valueEl.value = ''; loadBlacklist(); } else { showMsg(res.msg || '添加失败', true); }
            }).catch(function() { showMsg('网络错误', true); });
        };

        // 游戏服务器
        var gameServerAllList = [];
        var gameServerRooms = {};
        function getGameServerCheckFilter() {
            var el = document.getElementById('gameServerCheckFilter');
            return el && el.value ? el.value : 'all';
        }
        function getGameServerKeywordFilter() {
            var el = document.getElementById('gameServerKeywordFilter');
            return el && el.value ? String(el.value).trim().toLowerCase() : '';
        }
        function getSelectedGameServerIds() {
            var ids = [];
            document.querySelectorAll('#gameServerBody .game-server-checkbox:checked').forEach(function(box) {
                var id = parseInt(box.value, 10);
                if (id > 0) ids.push(id);
            });
            return ids;
        }
        function renderGameServers(list) {
            var tbody = document.getElementById('gameServerBody');
            if (!tbody) return;
            var filter = getGameServerCheckFilter();
            var keyword = getGameServerKeywordFilter();
            var filtered = list.filter(function(row) {
                var status = row.last_check_status || '';
                if (filter === 'online') return status === 'online';
                if (filter === 'offline') return status === 'offline';
                if (filter === 'untested') return status !== 'online' && status !== 'offline';
                if (filter === 'rooms') return (gameServerRooms[row.id] && gameServerRooms[row.id].length > 0);
                return true;
            }).filter(function(row) {
                if (!keyword) return true;
                var haystack = [
                    row.name || '',
                    row.host || '',
                    row.port || '',
                    row.source || '',
                    row.reported_username || '',
                    row.last_check_status || '',
                    row.last_check_error || '',
                    (gameServerRooms[row.id] || []).join(',')
                ].join(' ').toLowerCase();
                return haystack.indexOf(keyword) !== -1;
            });
            if (!filtered.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="empty">暂无匹配服务器</td></tr>';
                return;
            }
            tbody.innerHTML = filtered.map(function(row) {
                var enabled = parseInt(row.enabled, 10) === 1;
                var publicVisible = parseInt(row.public_account_visible, 10) !== 0;
                var checkStatus = row.last_check_status || '';
                var checkHtml = '<span class="muted">未测试</span>';
                if (checkStatus === 'online') {
                    checkHtml = '<span class="status-ok">连通</span>' + (row.last_check_ms ? ' <span class="muted">' + escapeHtml(String(row.last_check_ms)) + 'ms</span>' : '');
                } else if (checkStatus === 'offline') {
                    checkHtml = '<span class="status-invalid">不通</span>' + (row.last_check_error ? '<br><span class="muted">' + escapeHtml(row.last_check_error) + '</span>' : '');
                }
                if (row.last_check_at) checkHtml += '<br><span class="muted">' + escapeHtml(row.last_check_at) + '</span>';
                var roomList = gameServerRooms[row.id] || [];
                var roomsHtml = roomList.length
                    ? '<span class="status-ok">' + roomList.length + ' 个</span><br><code>' + escapeHtml(roomList.join(', ')) + '</code>'
                    : '<span class="muted">未获取</span>';
                var sourceText = row.source === 'app' ? 'APP上传' : '管理员添加';
                var sourceHtml = '<br><span class="muted">' + sourceText + (row.reported_username ? ' · ' + escapeHtml(row.reported_username) : '') + '</span>';
                return '<tr>' +
                    '<td><input type="checkbox" class="game-server-checkbox" value="' + row.id + '"></td>' +
                    '<td>' + escapeHtml(row.name || '-') + sourceHtml + '</td>' +
                    '<td><code>' + escapeHtml(row.host || '') + '</code></td>' +
                    '<td>' + escapeHtml(String(row.port || 8888)) + '</td>' +
                    '<td>' + (enabled ? '<span class="status-ok">启用</span>' : '<span class="status-invalid">停用</span>') + '</td>' +
                    '<td>' + checkHtml + '</td>' +
                    '<td>' + roomsHtml + '</td>' +
                    '<td>' + (row.created_at || '').replace(' ', '<br>') + '</td>' +
                    '<td><button type="button" class="btn btn-secondary btn-small btn-server-test" data-id="' + row.id + '">测试</button> ' +
                    '<button type="button" class="btn btn-secondary btn-small btn-server-toggle" data-id="' + row.id + '" data-enabled="' + (enabled ? 0 : 1) + '">' + (enabled ? '停用' : '启用') + '</button> ' +
                    '<button type="button" class="btn btn-secondary btn-small btn-server-public" data-id="' + row.id + '" data-visible="' + (publicVisible ? 0 : 1) + '">' + (publicVisible ? '公共隐藏' : '公共可见') + '</button> ' +
                    '<button type="button" class="btn btn-danger btn-small btn-server-del" data-id="' + row.id + '">删除</button></td>' +
                    '</tr>';
            }).join('');
            bindGameServerRowActions();
            var selectAll = document.getElementById('gameServerSelectAll');
            if (selectAll) {
                selectAll.checked = false;
                selectAll.onchange = function() {
                    tbody.querySelectorAll('.game-server-checkbox').forEach(function(box) {
                        box.checked = selectAll.checked;
                    });
                };
            }
        }
        function bindGameServerRowActions() {
            var tbody = document.getElementById('gameServerBody');
            if (!tbody) return;
            tbody.querySelectorAll('.btn-server-test').forEach(function(btn) {
                btn.onclick = function() {
                    btn.disabled = true;
                    btn.textContent = '测试中';
                    fetch(API_BASE + '/api/index.php?module=game_servers', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ action: 'test', id: parseInt(btn.getAttribute('data-id'), 10) })
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.code === 0) { showMsg('测试完成'); loadGameServers(); } else { showMsg(res.msg || '测试失败', true); btn.disabled = false; btn.textContent = '测试'; }
                    }).catch(function() { showMsg('网络错误', true); btn.disabled = false; btn.textContent = '测试'; });
                };
            });
            tbody.querySelectorAll('.btn-server-toggle').forEach(function(btn) {
                btn.onclick = function() {
                    fetch(API_BASE + '/api/index.php?module=game_servers', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ action: 'toggle', id: parseInt(btn.getAttribute('data-id'), 10), enabled: parseInt(btn.getAttribute('data-enabled'), 10) })
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.code === 0) { showMsg('已更新'); loadGameServers(); } else { showMsg(res.msg || '更新失败', true); }
                    }).catch(function() { showMsg('网络错误', true); });
                };
            });
            tbody.querySelectorAll('.btn-server-public').forEach(function(btn) {
                btn.onclick = function() {
                    fetch(API_BASE + '/api/index.php?module=game_servers', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ action: 'toggle_public', id: parseInt(btn.getAttribute('data-id'), 10), visible: parseInt(btn.getAttribute('data-visible'), 10) })
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.code === 0) { showMsg('公共账号权限已更新'); loadGameServers(); } else { showMsg(res.msg || '更新失败', true); }
                    }).catch(function() { showMsg('网络错误', true); });
                };
            });
            tbody.querySelectorAll('.btn-server-del').forEach(function(btn) {
                btn.onclick = function() {
                    if (!confirm('确定删除该服务器？')) return;
                    fetch(API_BASE + '/api/index.php?module=game_servers', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ action: 'delete', id: parseInt(btn.getAttribute('data-id'), 10) })
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.code === 0) { showMsg('已删除'); loadGameServers(); } else { showMsg(res.msg || '删除失败', true); }
                    }).catch(function() { showMsg('网络错误', true); });
                };
            });
        }
        function loadGameServers() {
            var tbody = document.getElementById('gameServerBody');
            if (!tbody) return;
            fetch(API_BASE + '/api/index.php?module=game_servers&action=list', { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.code !== 0) {
                        tbody.innerHTML = '<tr><td colspan="9" class="empty">' + escapeHtml(res.msg || '加载失败') + '</td></tr>';
                        return;
                    }
                    var list = res.data && res.data.list ? res.data.list : [];
                    gameServerAllList = list;
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="9" class="empty">暂无服务器，APP 暂无可刷新的房间服务器 IP</td></tr>';
                        return;
                    }
                    renderGameServers(list);
                })
                .catch(function() {
                    tbody.innerHTML = '<tr><td colspan="9" class="empty">请求失败</td></tr>';
                });
        }
        var gameServerCheckFilter = document.getElementById('gameServerCheckFilter');
        if (gameServerCheckFilter) gameServerCheckFilter.onchange = function() { renderGameServers(gameServerAllList); };
        var gameServerKeywordFilter = document.getElementById('gameServerKeywordFilter');
        if (gameServerKeywordFilter) gameServerKeywordFilter.oninput = function() { renderGameServers(gameServerAllList); };
        var btnGameServerTestAll = document.getElementById('btnGameServerTestAll');
        if (btnGameServerTestAll) btnGameServerTestAll.onclick = function() {
            btnGameServerTestAll.disabled = true;
            btnGameServerTestAll.textContent = '测试中';
            fetch(API_BASE + '/api/index.php?module=game_servers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'test_all' })
            }).then(function(r) { return r.json(); }).then(function(res) {
                btnGameServerTestAll.disabled = false;
                btnGameServerTestAll.textContent = '一键测试';
                if (res.code === 0) {
                    var d = res.data || {};
                    showMsg('测试完成：连通 ' + (d.online || 0) + '，不通 ' + (d.offline || 0));
                    loadGameServers();
                } else {
                    showMsg(res.msg || '测试失败', true);
                }
            }).catch(function() {
                btnGameServerTestAll.disabled = false;
                btnGameServerTestAll.textContent = '一键测试';
                showMsg('网络错误', true);
            });
        };
        var btnGameServerTestSelected = document.getElementById('btnGameServerTestSelected');
        if (btnGameServerTestSelected) btnGameServerTestSelected.onclick = function() {
            var ids = getSelectedGameServerIds();
            if (!ids.length) { showMsg('请先勾选要测试的服务器', true); return; }
            btnGameServerTestSelected.disabled = true;
            btnGameServerTestSelected.textContent = '测试中';
            fetch(API_BASE + '/api/index.php?module=game_servers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'test', ids: ids })
            }).then(function(r) { return r.json(); }).then(function(res) {
                btnGameServerTestSelected.disabled = false;
                btnGameServerTestSelected.textContent = '测试选中';
                if (res.code === 0) {
                    var d = res.data || {};
                    showMsg('选中测试完成：连通 ' + (d.online || 0) + '，不通 ' + (d.offline || 0));
                    loadGameServers();
                } else {
                    showMsg(res.msg || '测试失败', true);
                }
            }).catch(function() {
                btnGameServerTestSelected.disabled = false;
                btnGameServerTestSelected.textContent = '测试选中';
                showMsg('网络错误', true);
            });
        };
        function parseHomeRoomsMessage(text) {
            if (!text || text.indexOf('homeData##') === -1) return [];
            var payload = text.slice(text.indexOf('homeData##') + 'homeData##'.length);
            var rooms = [];
            payload.split(',').forEach(function(item) {
                var room = String(item || '').trim();
                if (room && rooms.indexOf(room) === -1) rooms.push(room);
            });
            return rooms;
        }
        function fetchGameServerRooms(row) {
            return new Promise(function(resolve) {
                if (!row || !row.host) { resolve({ id: row && row.id, rooms: [], error: 'empty host' }); return; }
                var scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
                var url = scheme + row.host + ':' + (row.port || 8888) + '/ws';
                var done = false;
                var ws;
                var timer = setTimeout(function() {
                    if (done) return;
                    done = true;
                    try { if (ws) ws.close(); } catch (e) {}
                    resolve({ id: row.id, rooms: [], error: 'timeout' });
                }, 6500);
                try {
                    ws = new WebSocket(url);
                    ws.onopen = function() { try { ws.send('getHome'); } catch (e) {} };
                    ws.onmessage = function(ev) {
                        if (done) return;
                        var rooms = parseHomeRoomsMessage(String(ev.data || ''));
                        if (!rooms.length && String(ev.data || '').indexOf('homeData##') === -1) return;
                        done = true;
                        clearTimeout(timer);
                        try { ws.close(); } catch (e) {}
                        resolve({ id: row.id, rooms: rooms, error: '' });
                    };
                    ws.onerror = function() {
                        if (done) return;
                        done = true;
                        clearTimeout(timer);
                        resolve({ id: row.id, rooms: [], error: 'connect failed' });
                    };
                    ws.onclose = function() {
                        if (done) return;
                        done = true;
                        clearTimeout(timer);
                        resolve({ id: row.id, rooms: [], error: 'closed' });
                    };
                } catch (e) {
                    done = true;
                    clearTimeout(timer);
                    resolve({ id: row.id, rooms: [], error: e.message || 'error' });
                }
            });
        }
        function rowsBySelectedIds(ids) {
            var map = {};
            ids.forEach(function(id) { map[id] = true; });
            return gameServerAllList.filter(function(row) { return map[row.id]; });
        }
        function fetchRoomsForRows(rows, button) {
            rows = rows || [];
            if (!rows.length) { showMsg('没有可获取房间号的服务器', true); return; }
            var oldText = button ? button.textContent : '';
            if (button) { button.disabled = true; button.textContent = '获取中'; }
            showMsg('正在获取房间号...');
            Promise.all(rows.map(fetchGameServerRooms)).then(function(results) {
                var withRooms = 0;
                var totalRooms = 0;
                results.forEach(function(result) {
                    gameServerRooms[result.id] = result.rooms || [];
                    if (result.rooms && result.rooms.length) {
                        withRooms++;
                        totalRooms += result.rooms.length;
                    }
                });
                if (button) { button.disabled = false; button.textContent = oldText; }
                renderGameServers(gameServerAllList);
                showMsg('房间号获取完成：' + withRooms + ' 台有房间，共 ' + totalRooms + ' 个房间');
            }).catch(function() {
                if (button) { button.disabled = false; button.textContent = oldText; }
                showMsg('获取房间号失败', true);
            });
        }
        var btnGameServerFetchRoomsAll = document.getElementById('btnGameServerFetchRoomsAll');
        if (btnGameServerFetchRoomsAll) btnGameServerFetchRoomsAll.onclick = function() {
            fetchRoomsForRows(gameServerAllList, btnGameServerFetchRoomsAll);
        };
        var btnGameServerFetchRoomsSelected = document.getElementById('btnGameServerFetchRoomsSelected');
        if (btnGameServerFetchRoomsSelected) btnGameServerFetchRoomsSelected.onclick = function() {
            var ids = getSelectedGameServerIds();
            if (!ids.length) { showMsg('请先勾选服务器', true); return; }
            fetchRoomsForRows(rowsBySelectedIds(ids), btnGameServerFetchRoomsSelected);
        };
        function batchToggleGameServers(enabled) {
            var ids = getSelectedGameServerIds();
            if (!ids.length) { showMsg('请先勾选服务器', true); return; }
            fetch(API_BASE + '/api/index.php?module=game_servers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'batch_toggle', ids: ids, enabled: enabled ? 1 : 0 })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) {
                    var d = res.data || {};
                    showMsg((enabled ? '已启用 ' : '已停用 ') + (d.updated || ids.length) + ' 个服务器');
                    loadGameServers();
                } else {
                    showMsg(res.msg || '更新失败', true);
                }
            }).catch(function() { showMsg('网络错误', true); });
        }
        var btnGameServerEnableSelected = document.getElementById('btnGameServerEnableSelected');
        if (btnGameServerEnableSelected) btnGameServerEnableSelected.onclick = function() { batchToggleGameServers(true); };
        var btnGameServerDisableSelected = document.getElementById('btnGameServerDisableSelected');
        if (btnGameServerDisableSelected) btnGameServerDisableSelected.onclick = function() { batchToggleGameServers(false); };
        var btnGameServerDeleteSelected = document.getElementById('btnGameServerDeleteSelected');
        if (btnGameServerDeleteSelected) btnGameServerDeleteSelected.onclick = function() {
            var ids = getSelectedGameServerIds();
            if (!ids.length) {
                showMsg('请先勾选要删除的服务器', true);
                return;
            }
            if (!confirm('确定删除选中的 ' + ids.length + ' 个服务器？')) return;
            btnGameServerDeleteSelected.disabled = true;
            fetch(API_BASE + '/api/index.php?module=game_servers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'delete', ids: ids })
            }).then(function(r) { return r.json(); }).then(function(res) {
                btnGameServerDeleteSelected.disabled = false;
                if (res.code === 0) {
                    var d = res.data || {};
                    showMsg('已删除 ' + (d.deleted || ids.length) + ' 个服务器');
                    loadGameServers();
                } else {
                    showMsg(res.msg || '删除失败', true);
                }
            }).catch(function() {
                btnGameServerDeleteSelected.disabled = false;
                showMsg('网络错误', true);
            });
        };
        var btnGameServerAdd = document.getElementById('btnGameServerAdd');
        if (btnGameServerAdd) btnGameServerAdd.onclick = function() {
            var nameEl = document.getElementById('gameServerName');
            var hostEl = document.getElementById('gameServerHost');
            var portEl = document.getElementById('gameServerPort');
            var name = nameEl && nameEl.value ? nameEl.value.trim() : '';
            var host = hostEl && hostEl.value ? hostEl.value.trim() : '';
            var port = portEl && portEl.value ? parseInt(portEl.value, 10) : 8888;
            if (!host) { showMsg('请输入服务器 IP 或域名', true); return; }
            fetch(API_BASE + '/api/index.php?module=game_servers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'add', name: name, host: host, port: port })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) {
                    showMsg('服务器已添加');
                    if (nameEl) nameEl.value = '';
                    if (hostEl) hostEl.value = '';
                    if (portEl) portEl.value = '8888';
                    loadGameServers();
                } else {
                    showMsg(res.msg || '添加失败', true);
                }
            }).catch(function() { showMsg('网络错误', true); });
        };

        function loadAppSettings() {
            fetch(API_BASE + '/api/index.php?module=app_settings&action=get', { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.code !== 0) return;
                    var d = res.data || {};
                    var trial = document.getElementById('settingTrialUrl');
                    var buy = document.getElementById('settingBuyCardUrl');
                    var down = document.getElementById('settingDownloadUrl');
                    var group = document.getElementById('settingGroupUrl');
                    if (trial) trial.value = d.trial_url || '';
                    if (buy) buy.value = d.buy_card_url || '';
                    if (down) down.value = d.download_url || '';
                    if (group) group.value = d.group_url || d.download_url || '';
                }).catch(function() {});
        }
        var btnSaveAppSettings = document.getElementById('btnSaveAppSettings');
        if (btnSaveAppSettings) btnSaveAppSettings.onclick = function() {
            var trial = document.getElementById('settingTrialUrl');
            var buy = document.getElementById('settingBuyCardUrl');
            var down = document.getElementById('settingDownloadUrl');
            var group = document.getElementById('settingGroupUrl');
            fetch(API_BASE + '/api/index.php?module=app_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'save',
                    trial_url: trial && trial.value ? trial.value.trim() : '',
                    buy_card_url: buy && buy.value ? buy.value.trim() : '',
                    download_url: down && down.value ? down.value.trim() : '',
                    group_url: group && group.value ? group.value.trim() : ''
                })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (res.code === 0) showMsg('链接配置已保存');
                else showMsg(res.msg || '保存失败', true);
            }).catch(function() { showMsg('网络错误', true); });
        };

        // 过期数据清理（删除过期卡密 + 用户 + 登录日志）
        var btnCleanupPreview = document.getElementById('btnCleanupPreview');
        var btnCleanupRun = document.getElementById('btnCleanupRun');
        var cleanupLimitEl = document.getElementById('cleanupLimit');
        var cleanupResultEl = document.getElementById('cleanupResult');

        function setCleanupResult(text, isErr) {
            if (!cleanupResultEl) return;
            cleanupResultEl.style.color = isErr ? '#ff6b6b' : '#51cf66';
            cleanupResultEl.textContent = text || '';
        }

        function getCleanupLimit() {
            var v = cleanupLimitEl && cleanupLimitEl.value ? parseInt(cleanupLimitEl.value, 10) : 2000;
            if (!v || v < 1) v = 2000;
            if (v > 20000) v = 20000;
            return v;
        }

        function callCleanup(action) {
            var limit = getCleanupLimit();
            setCleanupResult('处理中...', false);
            if (btnCleanupPreview) btnCleanupPreview.disabled = true;
            if (btnCleanupRun) btnCleanupRun.disabled = true;
            return fetch(API_BASE + '/api/index.php?module=cleanup_expired', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: action, limit: limit })
            }).then(function(r) { return r.json(); }).then(function(res) {
                if (!res || res.code !== 0) {
                    var m = (res && res.msg) ? res.msg : '失败';
                    setCleanupResult(m, true);
                    showMsg(m, true);
                    return;
                }
                var d = res.data || {};
                if (action === 'preview') {
                    setCleanupResult('预览：发现过期卡密 ' + (d.expired_cards_scanned || 0) + ' 条（不会删除）', false);
                } else {
                    setCleanupResult('已删除：卡密 ' + (d.expired_cards_deleted || 0) + '、用户 ' + (d.users_deleted || 0) + '、登录日志 ' + (d.login_logs_deleted || 0), false);
                    loadStats();
                    loadUserList();
                    loadCardList();
                }
            }).catch(function(e) {
                if (redirectToLoginIfAuthFailed(e)) return;
                setCleanupResult('请求失败', true);
                showMsg('网络错误', true);
            }).then(function() {
                if (btnCleanupPreview) btnCleanupPreview.disabled = false;
                if (btnCleanupRun) btnCleanupRun.disabled = false;
            });
        }

        if (btnCleanupPreview) btnCleanupPreview.onclick = function() {
            callCleanup('preview');
        };
        if (btnCleanupRun) btnCleanupRun.onclick = function() {
            if (!confirm('确定执行清理？\n\n将删除所有已过期卡密，并同步删除关联用户与登录日志。\n该操作不可恢复。')) return;
            callCleanup('run');
        };

        // 操作日志
        var logPage = 1;
        var logPageSize = 30;
        function loadOperationLog(page) {
            if (page !== undefined) logPage = page;
            var url = API_BASE + '/api/index.php?module=operation_log&action=list&page=' + logPage + '&page_size=' + logPageSize;
            fetch(url, { credentials: 'include' })
                .then(function(r) { return r.json(); }).then(function(res) {
                    var tbody = document.getElementById('logListBody');
                    if (res.code !== 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                        return;
                    }
                    var list = res.data && res.data.list ? res.data.list : [];
                    var total = res.data && res.data.total !== undefined ? res.data.total : 0;
                    if (list.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="empty">暂无操作记录</td></tr>';
                        document.getElementById('logPagination').style.display = 'none';
                        return;
                    }
                    document.getElementById('logPagination').style.display = 'flex';
                    tbody.innerHTML = list.map(function(row) {
                        return '<tr><td>' + row.id + '</td><td>' + escapeHtml(row.admin_username) + '</td><td>' + escapeHtml(row.action) + '</td><td>' + escapeHtml(row.detail || '-') + '</td><td>' + (row.created_at || '').replace(' ', '<br>') + '</td></tr>';
                    }).join('');
                    var totalPages = Math.max(1, Math.ceil(total / logPageSize));
                    var pagEl = document.getElementById('logPagination');
                    if (!pagEl) return;
                    pagEl.innerHTML = '<span>共 ' + total + ' 条</span><button type="button" id="logPrev">上一页</button><span>第 ' + logPage + ' / ' + totalPages + ' 页</span><button type="button" id="logNext">下一页</button>';
                    var logPrev = document.getElementById('logPrev');
                    var logNext = document.getElementById('logNext');
                    if (logPrev) { logPrev.disabled = logPage <= 1; logPrev.onclick = function() { if (logPage > 1) loadOperationLog(logPage - 1); }; }
                    if (logNext) { logNext.disabled = logPage >= totalPages; logNext.onclick = function() { if (logPage < totalPages) loadOperationLog(logPage + 1); }; }
                }).catch(function() {
                    document.getElementById('logListBody').innerHTML = '<tr><td colspan="5" class="empty">请求失败</td></tr>';
                });
        }

        var agentCardPage = 1;
        var agentCardPageSize = 10;
        function loadAgentCardsList(page) {
            if (page !== undefined) agentCardPage = page;
            var url = API_BASE + '/api/index.php?module=card&action=list_agent_cards&page=' + agentCardPage + '&page_size=' + agentCardPageSize;
            fetch(url, { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var tbody = document.getElementById('agentCardListBody');
                    if (!tbody) return;
                    if (res.code !== 0) {
                        tbody.innerHTML = '<tr><td colspan="9" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                        return;
                    }
                    var list = res.data && res.data.list ? res.data.list : [];
                    var total = res.data && res.data.total !== undefined ? res.data.total : 0;
                    if (list.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="9" class="empty">暂无代理生成的卡密</td></tr>';
                        document.getElementById('agentCardPagination').style.display = 'none';
                        return;
                    }
                    document.getElementById('agentCardPagination').style.display = 'flex';
                    tbody.innerHTML = list.map(function(row) {
                        var boundAt = row.bound_at ? row.bound_at.replace(' ', '<br>') : '-';
                        var expiresAt = row.expires_at ? row.expires_at.replace(' ', '<br>') : (row.card_type === 'day' ? '激活后1天' : (row.card_type === 'week' ? '激活后7天' : (row.card_type === 'month' ? '激活后30天' : '永久')));
                        var typeText = row.card_type_text ? escapeHtml(row.card_type_text) : '-';
                        var statusCls = (row.status_text === '已过期') ? 'expired' : ('status-' + row.status);
                        var creatorName = escapeHtml(row.creator_username || '-');
                        var remarkText = row.remark ? escapeHtml(row.remark) : '-';
                        return '<tr><td>' + row.id + '</td><td><code>' + escapeHtml(row.card_code) + '</code></td><td>' + typeText + '</td><td class="' + statusCls + '">' + escapeHtml(row.status_text) + '</td><td>' + creatorName + '</td><td>' + expiresAt + '</td><td>' + remarkText + '</td><td>' + boundAt + '</td><td>' + (row.created_at || '').replace(' ', '<br>') + '</td></tr>';
                    }).join('');
                    var totalPages = Math.max(1, Math.ceil(total / agentCardPageSize));
                    var pagEl = document.getElementById('agentCardPagination');
                    pagEl.innerHTML = '<span>共 ' + total + ' 条</span><button type="button" id="agentCardPrev">上一页</button><span>第 ' + agentCardPage + ' / ' + totalPages + ' 页</span><button type="button" id="agentCardNext">下一页</button>';
                    document.getElementById('agentCardPrev').disabled = agentCardPage <= 1;
                    document.getElementById('agentCardNext').disabled = agentCardPage >= totalPages;
                    document.getElementById('agentCardPrev').onclick = function() { if (agentCardPage > 1) loadAgentCardsList(agentCardPage - 1); };
                    document.getElementById('agentCardNext').onclick = function() { if (agentCardPage < totalPages) loadAgentCardsList(agentCardPage + 1); };
                }).catch(function() {
                    var tbody = document.getElementById('agentCardListBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="empty">请求失败</td></tr>';
                });
        }
        function loadAgentsList() {
            fetch(API_BASE + '/api/index.php?module=agents&action=list', { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var tbody = document.getElementById('agentListBody');
                    if (!tbody) return;
                    if (res.code !== 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty">' + (res.msg || '加载失败') + '</td></tr>';
                        return;
                    }
                    var list = res.data && res.data.list ? res.data.list : [];
                    if (list.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty">暂无代理，请添加</td></tr>';
                        return;
                    }
                    tbody.innerHTML = list.map(function(row) {
                        return '<tr><td>' + row.id + '</td><td>' + escapeHtml(row.username) + '</td><td>' + (row.created_at || '').replace(' ', '<br>') + '</td><td><button type="button" class="btn btn-danger btn-small btn-agent-del" data-id="' + row.id + '" data-name="' + escapeHtml(row.username) + '">删除</button></td></tr>';
                    }).join('');
                    tbody.querySelectorAll('.btn-agent-del').forEach(function(btn) {
                        btn.onclick = function() {
                            var name = btn.getAttribute('data-name') || '';
                            if (!confirm('确定删除代理「' + name + '」？\n\n该代理已生成的卡密及对应用户将被全部删除，此操作不可恢复。')) return;
                            var id = btn.getAttribute('data-id');
                            fetch(API_BASE + '/api/index.php?module=agents', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                credentials: 'include',
                                body: JSON.stringify({ action: 'delete', id: parseInt(id, 10) })
                            }).then(function(r) { return r.json(); }).then(function(res) {
                                if (res.code === 0) { showMsg('已删除'); loadAgentsList(); loadAgentCardsList(1); } else { showMsg(res.msg || '删除失败', true); }
                            }).catch(function() { showMsg('网络错误', true); });
                        };
                    });
                }).catch(function() {
                    var tbody = document.getElementById('agentListBody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="empty">请求失败</td></tr>';
                });
        }
        if (document.getElementById('btnAgentCardsRefresh')) {
            document.getElementById('btnAgentCardsRefresh').onclick = function() { loadAgentCardsList(1); };
        }
        if (document.getElementById('btnAgentAdd')) {
            document.getElementById('btnAgentAdd').onclick = function() {
                var username = (document.getElementById('agentUsername') && document.getElementById('agentUsername').value) ? document.getElementById('agentUsername').value.trim() : '';
                var password = (document.getElementById('agentPassword') && document.getElementById('agentPassword').value) ? document.getElementById('agentPassword').value : '';
                if (!username || username.length < 2) { showMsg('用户名至少2位', true); return; }
                if (!password || password.length < 4) { showMsg('密码至少4位', true); return; }
                fetch(API_BASE + '/api/index.php?module=agents', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ action: 'add', username: username, password: password })
                }).then(function(r) { return r.json(); }).then(function(res) {
                    if (res.code === 0) {
                        showMsg('代理添加成功');
                        if (document.getElementById('agentUsername')) document.getElementById('agentUsername').value = '';
                        if (document.getElementById('agentPassword')) document.getElementById('agentPassword').value = '';
                        loadAgentsList();
                    } else {
                        showMsg(res.msg || '添加失败', true);
                    }
                }).catch(function() { showMsg('网络错误', true); });
            };
        }

        function loadAppRemoteConfig() {
            fetch(API_BASE + '/api/index.php?module=app_remote_config&action=get', { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.code !== 0) return;
                    var d = res.data || {}, u = d.update || {}, p = d.popup || {}, links = d.links || {}, appLogin = d.app_login || {};
                    var set = function(id, value) { var el = document.getElementById(id); if (el) el.value = value || ''; };
                    set('appVersionCode', u.version_code || 2);
                    set('appVersionName', u.version_name || '');
                    set('appApkUrl', u.apk_url || '');
                    set('appApkUrlGithub', u.apk_url_github || '');
                    set('appApkUrlGitee', u.apk_url_gitee || '');
                    set('appUpdateTitle', u.title || '');
                    set('appUpdateMessage', u.message || '');
                    set('appBuyCardUrl', links.buy_card_url || '');
                    set('appGroupUrl', links.group_url || links.download_url || '');
                    set('appLoginUsername', appLogin.username || '');
                    set('appLoginPassword', appLogin.password || '');
                    set('appLoginTitle', appLogin.title || '');
                    set('appLoginMessage', appLogin.message || '');
                    set('appPopupTitle', p.title || '');
                    set('appPopupMessage', p.message || '');
                    set('appPopupUrl', p.url || '');
                    var force = document.getElementById('appForceUpdate');
                    var popup = document.getElementById('appPopupEnabled');
                    var appLoginRequired = document.getElementById('appLoginRequired');
                    var appLoginEnabled = document.getElementById('appLoginEnabled');
                    if (force) force.checked = !!u.force_update;
                    if (popup) popup.checked = !!p.enabled;
                    if (appLoginRequired) appLoginRequired.checked = d.login_required !== false;
                    if (appLoginEnabled) appLoginEnabled.checked = !!appLogin.enabled;
                }).catch(function() {});
        }
        var btnSaveAppRemote = document.getElementById('btnSaveAppRemote');
        if (btnSaveAppRemote) btnSaveAppRemote.onclick = function() {
            var val = function(id) { var el = document.getElementById(id); return el && el.value ? el.value.trim() : ''; };
            fetch(API_BASE + '/api/index.php?module=app_remote_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'save',
                    version_code: val('appVersionCode'),
                    version_name: val('appVersionName'),
                    apk_url: val('appApkUrl'),
                    apk_url_github: val('appApkUrlGithub'),
                    apk_url_gitee: val('appApkUrlGitee'),
                    update_title: val('appUpdateTitle'),
                    update_message: val('appUpdateMessage'),
                    buy_card_url: val('appBuyCardUrl'),
                    group_url: val('appGroupUrl'),
                    app_login_required: document.getElementById('appLoginRequired') && document.getElementById('appLoginRequired').checked ? 1 : 0,
                    app_login_enabled: document.getElementById('appLoginEnabled') && document.getElementById('appLoginEnabled').checked ? 1 : 0,
                    app_login_username: val('appLoginUsername'),
                    app_login_password: val('appLoginPassword'),
                    app_login_title: val('appLoginTitle'),
                    app_login_message: val('appLoginMessage'),
                    force_update: document.getElementById('appForceUpdate') && document.getElementById('appForceUpdate').checked ? 1 : 0,
                    popup_enabled: document.getElementById('appPopupEnabled') && document.getElementById('appPopupEnabled').checked ? 1 : 0,
                    popup_title: val('appPopupTitle'),
                    popup_message: val('appPopupMessage'),
                    popup_url: val('appPopupUrl')
                })
            }).then(function(r) { return r.json(); }).then(function(res) {
                showMsg(res.msg || (res.code === 0 ? '保存成功' : '保存失败'), res.code !== 0);
            }).catch(function() { showMsg('网络错误', true); });
        };

        var IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;


        loadStats();
        loadOnlineUsers();
        setInterval(function() {
            loadStats();
            loadOnlineUsers();
        }, 30000);
        // 卡密/用户列表：DOM 就绪后再请求，并做两次延迟重试（避免登录后 session 未带上或首请求被取消）
        function runInitialLists() {
            loadCardList();
            loadUserList();
            loadTrialList();
        }
        function scheduleInitialLists() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function once() {
                    document.removeEventListener('DOMContentLoaded', once);
                    runInitialLists();
                    setTimeout(runInitialLists, 350);
                    setTimeout(runInitialLists, 900);
                });
            } else {
                runInitialLists();
                setTimeout(runInitialLists, 350);
                setTimeout(runInitialLists, 900);
            }
        }
        scheduleInitialLists();
        loadRegionStats();
        if (!IS_AGENT) {
            loadBlacklist();
            loadGameServers();
            loadAppSettings();
            loadAppRemoteConfig();
            loadOperationLog();
            loadAgentCardsList();
            loadAgentsList();
        }

        // 左侧导航切换不同功能页面
        (function() {
            var pages = Array.prototype.slice.call(document.querySelectorAll('.page-screen'));
            var navItems = Array.prototype.slice.call(document.querySelectorAll('.sidebar-nav-item'));
            if (!pages.length || !navItems.length) return;
            function showPage(id) {
                pages.forEach(function(p) {
                    var match = p.getAttribute('data-page') === id;
                    p.classList.toggle('active', match);
                });
                navItems.forEach(function(btn) {
                    var match = btn.getAttribute('data-page') === id;
                    if (match) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                });
            }
            navItems.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = btn.getAttribute('data-page') || 'overview';
                    showPage(id);
                });
            });
            showPage('overview');
        })();

    </script>
        </main>
    </div>
</body>
</html>
