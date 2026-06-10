'use strict';

const path = require('path');
const http = require('http');
const fs = require('fs');
const crypto = require('crypto');
const express = require('express');
const { Server: SocketIOServer } = require('socket.io');
const { Client } = require('ssh2');
const { runDeployment } = require('./deployer');

const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || '0.0.0.0';
const PAYLOAD_ROOT =
  process.env.PAYLOAD_DIR ||
  path.resolve(__dirname, '..', '\u7f51\u9875\u6e90\u7801');
const PAYLOAD_VARIANTS = {
  clean: {
    label: '\u7eaf\u51c0\u7248',
    dir: path.join(PAYLOAD_ROOT, '\u7eaf\u51c0\u7248'),
    required: ['wz.jar', 'index.html'],
  },
  card: {
    label: '\u5361\u5bc6\u7248',
    dir: path.join(PAYLOAD_ROOT, '\u5361\u5bc6\u7248'),
    required: [
      'wz.jar',
      'index.html',
      'auth_config.php',
      path.join('api', 'auth.php'),
      path.join('api', 'auth_lib.php'),
      path.join('admin', 'index.php'),
      path.join('layui', 'auth.js'),
      path.join('data', 'cards.db.php'),
      path.join('data', 'sessions.db.php'),
    ],
  },
};
const REMOTE_VARIANTS = {
  ops: {
    label: '运营版',
    fileCount: 0,
    remote: true,
  },
};
const OPS_STEPS = [
  { id: 'connect', label: '连接 SSH' },
  { id: 'detect', label: '检测系统环境' },
  { id: 'install-java', label: '安装 Java 运行环境' },
  { id: 'install-nginx', label: '安装 Nginx' },
  { id: 'install-php', label: '配置 PHP / 数据库' },
  { id: 'prepare-dir', label: '创建站点目录' },
  { id: 'upload', label: '远程拉取源码' },
  { id: 'nginx-config', label: '配置 Nginx' },
  { id: 'java-service', label: '启动 Java 服务' },
  { id: 'firewall', label: '放行防火墙端口' },
  { id: 'health', label: '健康检查' },
];
const OPS_INSTALL_CODE =
  (process.env.OPS_INSTALL_CODE || process.env.WZRY_INSTALL_CODE || 'hlin..00').trim();
// 旧 ACCESS_CODE 仅做兼容：部署入口使用一次性卡密，后台管理使用 ADMIN_PASSWORD。
const ACCESS_CODE = (process.env.ACCESS_CODE || '').trim();
const ACCESS_HINT = (process.env.ACCESS_HINT || '').trim();
const ADMIN_USERNAME = (process.env.ADMIN_USERNAME || 'admin').trim() || 'admin';
const ADMIN_PASSWORD =
  (process.env.ADMIN_PASSWORD || process.env.RECORD_ADMIN_PASSWORD || ACCESS_CODE || '').trim();
const ADMIN_SESSION_TTL_MS = Math.max(1800000, Number(process.env.ADMIN_SESSION_TTL_MS || 43200000) || 43200000);
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
const DEPLOY_RECORDS_FILE =
  process.env.DEPLOY_RECORDS_FILE || path.join(DATA_DIR, 'deploy-records.json');
const DEPLOY_CARDS_FILE =
  process.env.DEPLOY_CARDS_FILE || path.join(DATA_DIR, 'deploy-cards.json');
const MAX_DEPLOY_RECORDS = Math.max(50, Number(process.env.MAX_DEPLOY_RECORDS || 500) || 500);
const CARD_RUNNING_TTL_MS = Math.max(600000, Number(process.env.CARD_RUNNING_TTL_MS || 7200000) || 7200000);
const adminSessions = new Map();

// 启动时校验 payload 目录
if (!fs.existsSync(PAYLOAD_ROOT)) {
  console.error('[FATAL] 找不到源码目录:', PAYLOAD_ROOT);
  console.error('  请通过环境变量 PAYLOAD_DIR 指定正确路径，或把源码放到默认位置（上级目录的 "网页源码"）。');
  process.exit(1);
}
for (const [mode, variant] of Object.entries(PAYLOAD_VARIANTS)) {
  if (!fs.existsSync(variant.dir)) {
    console.error(`[FATAL] 缺少${variant.label}目录: ${variant.dir}`);
    process.exit(1);
  }
  for (const f of variant.required) {
    if (!fs.existsSync(path.join(variant.dir, f))) {
      console.error(`[FATAL] ${variant.label}缺少必须文件: ${f}`);
      process.exit(1);
    }
  }
}
console.log('[OK] 源码根目录:', PAYLOAD_ROOT);
for (const variant of Object.values(PAYLOAD_VARIANTS)) {
  console.log(`[OK] ${variant.label}:`, variant.dir);
}

const app = express();
app.use(express.json({ limit: '256kb' }));

app.get('/admin', (req, res) => {
  res.type('html').send(renderAdminPage());
});

app.post('/api/admin/login', (req, res) => {
  if (!ADMIN_PASSWORD) {
    res.status(503).json({ ok: false, message: '后台密码未配置，请在服务环境变量设置 ADMIN_PASSWORD' });
    return;
  }
  const username = String((req.body && req.body.username) || '').trim();
  const password = String((req.body && req.body.password) || '');
  if (!safeEqual(username, ADMIN_USERNAME) || !safeEqual(password, ADMIN_PASSWORD)) {
    res.status(401).json({ ok: false, message: '后台账号或密码不正确' });
    return;
  }
  res.json({ ok: true, token: createAdminSession(), ttlMs: ADMIN_SESSION_TTL_MS });
});

app.post('/api/admin/logout', (req, res) => {
  const token = adminTokenFromReq(req);
  if (token) adminSessions.delete(token);
  res.json({ ok: true });
});

app.get('/api/admin/summary', requireAdmin, (req, res) => {
  res.json({
    ok: true,
    adminConfigured: !!ADMIN_PASSWORD,
    records: loadDeployRecords(),
    cards: publicDeployCards(loadDeployCards()),
  });
});

app.post('/api/admin/cards', requireAdmin, (req, res) => {
  const quantity = Math.max(1, Math.min(100, Number(req.body && req.body.quantity) || 1));
  const note = String((req.body && req.body.note) || '').trim().slice(0, 120);
  const cards = createDeployCards(quantity, note);
  res.json({
    ok: true,
    cards,
  });
});

app.use(express.static(path.join(__dirname, 'public')));

app.get('/api/health', (req, res) => {
  res.json({ ok: true, payloadRoot: PAYLOAD_ROOT, variants: publicVariants() });
});

// 前端启动时读取公开元信息
app.get('/api/meta', (req, res) => {
  res.json({
    accessRequired: false,
    accessHint: '',
    cardRequired: true,
    adminPath: '/admin',
    version: '1.0.0',
    variants: publicVariants(),
  });
});

const server = http.createServer(app);
const io = new SocketIOServer(server, {
  cors: { origin: '*' },
  maxHttpBufferSize: 1e6,
});

// 每个 socket 最多一个并行任务
const activeJobs = new Map(); // socketId -> { cancel: () => void }

io.on('connection', (socket) => {
  console.log(`[socket] connected ${socket.id}`);

  socket.on('deploy:start', async (payload) => {
    if (activeJobs.has(socket.id)) {
      socket.emit('deploy:error', { message: '已有部署任务在进行中，请稍候' });
      return;
    }

    const creds = sanitizeCreds(payload);
    const validation = validateCreds(creds);
    if (!validation.ok) {
      socket.emit('deploy:error', { message: validation.message });
      return;
    }

    const cardLock = acquireDeployCard(creds.deployCard, {
      socketId: socket.id,
      host: creds.host,
      deployMode: creds.deployMode,
    });
    if (!cardLock.ok) {
      socket.emit('deploy:error', { message: cardLock.message });
      return;
    }

    const jobState = { cancelled: false };
    activeJobs.set(socket.id, {
      cancel: () => {
        jobState.cancelled = true;
      },
    });

    const emit = makeEmitter(socket);
    emit.step('init', 'running', '开始部署任务');
    emit.log('info', `部署卡密验证通过，本次成功后将自动失效`);
    emit.log('info', `目标服务器信息已接收，SSH 端口: ${creds.port}  用户: ${creds.username}`);
    emit.log('info', `部署版本: ${variantLabel(creds.deployMode)}`);

    let completed = false;
    try {
      let deployMeta = {};
      if (creds.deployMode === 'ops') {
        deployMeta = await runOpsDeployment({
          creds,
          emit,
          shouldCancel: () => jobState.cancelled,
        }) || {};
      } else {
        const selectedPayloadDir = payloadDirForMode(creds.deployMode);
        await runDeployment({
          creds,
          payloadDir: selectedPayloadDir,
          emit,
          shouldCancel: () => jobState.cancelled,
        });
      }
      const urls = {
        static80: buildSiteUrl(creds.host, creds.sitePort),
        site: buildSiteUrl(creds.host, creds.sitePort),
      };
      const record = buildDeployRecord(creds, urls, deployMeta);
      consumeDeployCard(cardLock.card.id, cardLock.lockId, {
        recordId: record.id,
        host: creds.host,
        deployMode: creds.deployMode,
      });
      completed = true;
      try {
        saveDeployRecord(record);
        emit.log('success', '部署信息已保存到后台管理');
      } catch (recordErr) {
        emit.log('warn', `部署成功，但保存部署信息失败: ${recordErr.message || recordErr}`);
      }
      emit.step('done', 'success', '全部步骤完成');
      socket.emit('deploy:done', { urls });
    } catch (err) {
      const msg = (err && err.message) || String(err);
      emit.log('error', `部署失败: ${msg}`);
      emit.step('done', 'failed', msg);
      socket.emit('deploy:error', { message: msg });
    } finally {
      if (!completed) releaseDeployCard(cardLock.card.id, cardLock.lockId);
      activeJobs.delete(socket.id);
    }
  });

  socket.on('test:connect', async (payload) => {
    const creds = sanitizeCreds(payload);
    const validation = validateCreds(creds);
    if (!validation.ok) {
      socket.emit('test:result', { ok: false, error: validation.message });
      return;
    }
    const cardCheck = checkDeployCard(creds.deployCard);
    if (!cardCheck.ok) {
      socket.emit('test:result', { ok: false, error: cardCheck.message });
      return;
    }

    const { Client } = require('ssh2');
    const conn = new Client();
    let settled = false;

    const done = (result) => {
      if (settled) return;
      settled = true;
      try { conn.end(); } catch (_) {}
      socket.emit('test:result', result);
    };

    const timeout = setTimeout(() => {
      done({ ok: false, error: '连接超时（10秒）' });
    }, 10000);

    conn.on('ready', () => {
      clearTimeout(timeout);
      conn.exec('cat /etc/os-release 2>/dev/null || cat /etc/redhat-release 2>/dev/null || uname -a', (err, stream) => {
        if (err) {
          done({ ok: true, host: creds.host, osInfo: '无法获取系统信息' });
          return;
        }
        let output = '';
        stream.on('data', (d) => { output += d.toString(); });
        stream.stderr.on('data', (d) => { output += d.toString(); });
        stream.on('close', () => {
          const lines = output.trim().split('\n');
          let osName = '未知';
          let osVersion = '';
          for (const line of lines) {
            const nameMatch = line.match(/^NAME="?([^"\n]+)"?/m);
            const verMatch = line.match(/^VERSION="?([^"\n]+)"?/m);
            if (nameMatch) osName = nameMatch[1];
            if (verMatch) osVersion = verMatch[1];
            // RedHat-style fallback
            if (/CentOS|Red Hat|Rocky|AlmaLinux/i.test(line) && !nameMatch) {
              osName = line.trim();
            }
          }
          const osInfo = osVersion ? `${osName} ${osVersion}` : osName;
          done({ ok: true, host: creds.host, osInfo });
        });
      });
    });

    conn.on('error', (err) => {
      clearTimeout(timeout);
      done({ ok: false, error: err.message || '连接失败' });
    });

    conn.connect({
      host: creds.host,
      port: creds.port,
      username: creds.username,
      password: creds.password,
      readyTimeout: 8000,
    });
  });

  socket.on('deploy:cancel', () => {
    const job = activeJobs.get(socket.id);
    if (job) {
      job.cancel();
      socket.emit('deploy:log', { level: 'warn', message: '已请求取消，等待当前命令结束...' });
    }
  });

  socket.on('disconnect', () => {
    const job = activeJobs.get(socket.id);
    if (job) job.cancel();
    activeJobs.delete(socket.id);
    console.log(`[socket] disconnected ${socket.id}`);
  });
});

async function runOpsDeployment({ creds, emit, shouldCancel }) {
  const installCode = creds.opsInstallCode || OPS_INSTALL_CODE;
  if (!installCode) {
    throw new Error('运营版安装授权码不能为空');
  }

  emit.log('info', '==============================');
  emit.log('info', '运营版远程仓库部署开始');
  emit.log('info', '优先下载 GitHub 源码，网络不通时自动切换 Gitee');
  emit.log('info', '==============================');
  for (const s of OPS_STEPS) emit.step(s.id, 'pending', s.label);

  const conn = new Client();
  try {
    emit.step('connect', 'running', '正在连接目标服务器');
    await connectRemote(conn, creds);
    ensureNotCancelled(shouldCancel);
    emit.step('connect', 'success', 'SSH 已连接');
    emit.progress(8, 'SSH 已连接');

    emit.step('detect', 'running', '检测系统并准备下载工具');
    const osInfo = await runRemoteCommand(conn, 'cat /etc/os-release 2>/dev/null || uname -a', {
      silent: true,
      shouldCancel,
    });
    const summary = summarizeOs(osInfo.stdout || '');
    if (summary) emit.log('info', `系统信息: ${summary}`);
    await runRemoteCommand(conn, buildEnsureCurlCommand(), { emit, shouldCancel });
    emit.step('detect', 'success', '系统检测完成');
    emit.progress(18, '系统检测完成');

    emit.step('upload', 'running', '正在远程下载安装脚本与源码');
    emit.step('prepare-dir', 'running', '远程脚本将准备站点目录');
    emit.progress(24, '正在远程拉取 GitHub/Gitee 源码');

    const trackStage = createOpsStageTracker(emit);
    await runRemoteCommand(conn, buildOpsInstallCommand(creds, installCode), {
      emit,
      shouldCancel,
      onOutput: trackStage,
    });

    const successMessages = {
      'install-java': 'Java 运行环境已处理',
      'install-nginx': 'Nginx 已处理',
      'install-php': 'PHP 与数据库已处理',
      'prepare-dir': '站点目录已准备',
      upload: '远程源码已拉取完成',
      'nginx-config': 'Nginx 配置已完成',
      'java-service': 'Java 服务已启动',
      firewall: '端口已尝试放行',
      health: '健康检查完成',
    };
    for (const step of OPS_STEPS) {
      if (step.id === 'connect' || step.id === 'detect') continue;
      emit.step(step.id, 'success', successMessages[step.id] || '已完成');
    }
    const opsReceipt = await readOpsInstallReceipt(conn, emit, shouldCancel);
    if (Object.keys(opsReceipt).length) {
      emit.log('success', '已读取目标服务器安装记录');
    }
    emit.progress(96, '运营版远程脚本执行完成');
    return { opsReceipt };
  } finally {
    try { conn.end(); } catch (_) {}
  }
}

async function readOpsInstallReceipt(conn, emit, shouldCancel) {
  try {
    const receiptResult = await runRemoteCommand(
      conn,
      'test -f /root/wzry-space-install.env && cat /root/wzry-space-install.env || true',
      { silent: true, shouldCancel, allowFail: true }
    );
    const receipt = parseEnvText(receiptResult.stdout || '');
    if (receipt.SITE_DIR) {
      const siteDir = shQuote(receipt.SITE_DIR);
      const apkResult = await runRemoteCommand(
        conn,
        `find ${siteDir}/apk -maxdepth 1 -type f -name 'ALinRadar-v*.apk' -printf '%f\\n' 2>/dev/null | sort -V | tail -n 1`,
        { silent: true, shouldCancel, allowFail: true }
      );
      const appFile = String(apkResult.stdout || '').trim().split(/\r?\n/).filter(Boolean).pop();
      if (appFile) receipt.APP_FILE = appFile;
    }
    return receipt;
  } catch (err) {
    if (emit) emit.log('warn', `读取安装记录失败: ${err.message || err}`);
    return {};
  }
}

function sanitizeCreds(payload) {
  const p = payload || {};
  const deployMode = ['clean', 'card', 'ops'].includes(p.deployMode) ? p.deployMode : 'clean';
  return {
    deployCard: String(p.deployCard || '').trim(),
    host: String(p.host || '').trim(),
    port: Number(p.port || 22),
    username: String(p.username || '').trim(),
    password: typeof p.password === 'string' ? p.password : '',
    // 可选参数
    sitePath: String(p.sitePath || '').trim(), // 默认用 host
    sitePort: Number(p.sitePort || 80),
    deployMode,
    cardAdminPassword: String(p.cardAdminPassword || '').trim(),
    opsInstallCode: String(p.opsInstallCode || '').trim(),
    opsServerName: String(p.opsServerName || '_').trim() || '_',
    opsDbRootPassword: typeof p.opsDbRootPassword === 'string' ? p.opsDbRootPassword : '',
    opsDbPassword: typeof p.opsDbPassword === 'string' ? p.opsDbPassword : '',
    opsAdminUser: String(p.opsAdminUser || 'admin').trim() || 'admin',
    opsAdminPassword: typeof p.opsAdminPassword === 'string' ? p.opsAdminPassword : '',
    installJava: p.installJava !== false,
    installNginx: p.installNginx !== false,
  };
}

function validateCreds(c) {
  if (!c.deployCard) return { ok: false, message: '请先填写部署卡密' };
  if (c.deployCard.length > 80) return { ok: false, message: '部署卡密格式不合法' };
  if (!c.host) return { ok: false, message: '服务器地址不能为空' };
  if (!/^[a-zA-Z0-9\.\-\_]+$/.test(c.host)) return { ok: false, message: '服务器地址格式不合法' };
  if (!Number.isInteger(c.port) || c.port < 1 || c.port > 65535) return { ok: false, message: 'SSH 端口不合法' };
  if (!Number.isInteger(c.sitePort) || c.sitePort < 1 || c.sitePort > 65535) return { ok: false, message: '网站访问端口不合法' };
  if ([8888, 9999].includes(c.sitePort)) return { ok: false, message: '网站访问端口不能使用 8888 或 9999' };
  if (!['clean', 'card', 'ops'].includes(c.deployMode)) return { ok: false, message: '部署版本不合法' };
  if (c.deployMode === 'card') {
    if (!c.cardAdminPassword) return { ok: false, message: '卡密版需要设置后台管理密码' };
    if (c.cardAdminPassword.length < 6) return { ok: false, message: '后台管理密码至少 6 位' };
    if (c.cardAdminPassword.length > 128) return { ok: false, message: '后台管理密码不能超过 128 个字符' };
  }
  if (c.deployMode === 'ops') {
    if (!c.opsInstallCode && !OPS_INSTALL_CODE) return { ok: false, message: '运营版需要填写安装授权码' };
    if (!c.opsAdminUser) return { ok: false, message: '运营版后台用户名不能为空' };
    if (c.opsAdminUser.length > 64) return { ok: false, message: '运营版后台用户名不能超过 64 个字符' };
    if (c.opsAdminPassword && c.opsAdminPassword.length < 6) return { ok: false, message: '运营版后台密码至少 6 位，或留空自动生成' };
    if (c.opsAdminPassword.length > 128) return { ok: false, message: '运营版后台密码不能超过 128 个字符' };
    if (c.opsDbPassword.length > 128 || c.opsDbRootPassword.length > 128) return { ok: false, message: '数据库密码不能超过 128 个字符' };
    if (!/^[a-zA-Z0-9._-]+$/.test(c.opsServerName)) return { ok: false, message: '绑定域名格式不合法，不绑定请填 _' };
  }
  if (!c.username) return { ok: false, message: '用户名不能为空' };
  if (!c.password) return { ok: false, message: '密码不能为空' };
  return { ok: true };
}

function requireAdmin(req, res, next) {
  const token = adminTokenFromReq(req);
  if (!token || !isAdminSessionValid(token)) {
    res.status(401).json({ ok: false, message: '请先登录后台' });
    return;
  }
  next();
}

function adminTokenFromReq(req) {
  const auth = String(req.get('authorization') || '');
  const bearer = auth.match(/^Bearer\s+(.+)$/i);
  return (
    (bearer && bearer[1]) ||
    req.get('x-admin-token') ||
    (req.query && req.query.adminToken) ||
    (req.body && req.body.adminToken) ||
    ''
  );
}

function createAdminSession() {
  cleanupAdminSessions();
  const token = crypto.randomBytes(32).toString('hex');
  adminSessions.set(token, Date.now() + ADMIN_SESSION_TTL_MS);
  return token;
}

function isAdminSessionValid(token) {
  cleanupAdminSessions();
  const expiresAt = adminSessions.get(token);
  if (!expiresAt || expiresAt < Date.now()) {
    adminSessions.delete(token);
    return false;
  }
  return true;
}

function cleanupAdminSessions() {
  const now = Date.now();
  for (const [token, expiresAt] of adminSessions.entries()) {
    if (expiresAt < now) adminSessions.delete(token);
  }
}

function safeEqual(a, b) {
  const av = Buffer.from(String(a || ''), 'utf8');
  const bv = Buffer.from(String(b || ''), 'utf8');
  if (av.length !== bv.length) return false;
  return crypto.timingSafeEqual(av, bv);
}

function loadJsonArray(file) {
  try {
    if (!fs.existsSync(file)) return [];
    const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
    return Array.isArray(parsed) ? parsed : [];
  } catch (err) {
    console.error(`[data] 读取失败 ${file}:`, err.message || err);
    return [];
  }
}

function saveJsonArray(file, rows) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.writeFileSync(file, JSON.stringify(rows, null, 2), 'utf8');
  try { fs.chmodSync(file, 0o600); } catch (_) {}
}

function loadDeployCards() {
  return cleanupDeployCards(loadJsonArray(DEPLOY_CARDS_FILE));
}

function saveDeployCards(cards) {
  saveJsonArray(DEPLOY_CARDS_FILE, cards);
}

function cleanupDeployCards(cards) {
  const now = Date.now();
  let changed = false;
  for (const card of cards) {
    if (card.status === 'running' && card.lockedAt && now - Date.parse(card.lockedAt) > CARD_RUNNING_TTL_MS) {
      card.status = 'unused';
      delete card.lockId;
      delete card.lockedAt;
      delete card.lockedBy;
      delete card.pendingTarget;
      changed = true;
    }
  }
  if (changed) saveDeployCards(cards);
  return cards;
}

function createDeployCards(quantity, note) {
  const cards = loadDeployCards();
  const created = [];
  for (let i = 0; i < quantity; i += 1) {
    let code;
    do {
      code = generateDeployCardCode();
    } while (cards.some((c) => c.code === code));
    const card = {
      id: crypto.randomBytes(12).toString('hex'),
      code,
      status: 'unused',
      note: note || '',
      createdAt: new Date().toISOString(),
    };
    cards.unshift(card);
    created.push(card);
  }
  saveDeployCards(cards);
  return publicDeployCards(created);
}

function publicDeployCards(cards) {
  return cards.map((card) => ({
    id: card.id,
    code: card.code,
    status: card.status || 'unused',
    note: card.note || '',
    createdAt: card.createdAt || '',
    lockedAt: card.lockedAt || '',
    usedAt: card.usedAt || '',
    usedRecordId: card.usedRecordId || '',
    usedHost: card.usedHost || '',
    deployMode: card.deployMode || '',
  }));
}

function generateDeployCardCode() {
  const a = crypto.randomBytes(3).toString('hex').toUpperCase();
  const b = crypto.randomBytes(3).toString('hex').toUpperCase();
  const c = crypto.randomBytes(2).toString('hex').toUpperCase();
  return `DEP-${a}-${b}-${c}`;
}

function checkDeployCard(code) {
  const value = String(code || '').trim();
  if (!value) return { ok: false, message: '请先填写部署卡密' };
  const cards = loadDeployCards();
  const card = cards.find((c) => c.code === value);
  if (!card) return { ok: false, message: '部署卡密不存在' };
  if (card.status === 'used') return { ok: false, message: '部署卡密已使用' };
  if (card.status === 'running') return { ok: false, message: '部署卡密正在使用中，请等待当前任务结束' };
  return { ok: true, card };
}

function acquireDeployCard(code, meta) {
  const value = String(code || '').trim();
  if (!value) return { ok: false, message: '请先填写部署卡密' };
  const cards = loadDeployCards();
  const card = cards.find((c) => c.code === value);
  if (!card) return { ok: false, message: '部署卡密不存在' };
  if (card.status === 'used') return { ok: false, message: '部署卡密已使用' };
  if (card.status === 'running') return { ok: false, message: '部署卡密正在使用中，请等待当前任务结束' };
  const lockId = crypto.randomBytes(16).toString('hex');
  card.status = 'running';
  card.lockId = lockId;
  card.lockedAt = new Date().toISOString();
  card.lockedBy = meta.socketId || '';
  card.pendingTarget = maskHost(meta.host || '');
  card.deployMode = meta.deployMode || '';
  saveDeployCards(cards);
  return { ok: true, card: { id: card.id, code: card.code }, lockId };
}

function consumeDeployCard(cardId, lockId, meta) {
  const cards = loadDeployCards();
  const card = cards.find((c) => c.id === cardId);
  if (!card || card.lockId !== lockId) return false;
  card.status = 'used';
  card.usedAt = new Date().toISOString();
  card.usedRecordId = meta.recordId || '';
  card.usedHost = meta.host || '';
  card.deployMode = meta.deployMode || card.deployMode || '';
  delete card.lockId;
  delete card.lockedAt;
  delete card.lockedBy;
  delete card.pendingTarget;
  saveDeployCards(cards);
  return true;
}

function releaseDeployCard(cardId, lockId) {
  const cards = loadDeployCards();
  const card = cards.find((c) => c.id === cardId);
  if (!card || card.lockId !== lockId || card.status !== 'running') return false;
  card.status = 'unused';
  delete card.lockId;
  delete card.lockedAt;
  delete card.lockedBy;
  delete card.pendingTarget;
  saveDeployCards(cards);
  return true;
}

function maskHost(host) {
  const value = String(host || '');
  if (!value) return '';
  if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(value)) {
    const parts = value.split('.');
    return `${parts[0]}.${parts[1]}.*.*`;
  }
  return value.replace(/^(.{2}).+(.{2})$/, '$1***$2');
}

function loadDeployRecords() {
  try {
    if (!fs.existsSync(DEPLOY_RECORDS_FILE)) return [];
    const parsed = JSON.parse(fs.readFileSync(DEPLOY_RECORDS_FILE, 'utf8'));
    return Array.isArray(parsed) ? parsed : [];
  } catch (err) {
    console.error('[records] 读取部署记录失败:', err.message || err);
    return [];
  }
}

function saveDeployRecord(record) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const records = loadDeployRecords();
  const next = [record, ...records].slice(0, MAX_DEPLOY_RECORDS);
  fs.writeFileSync(DEPLOY_RECORDS_FILE, JSON.stringify(next, null, 2), 'utf8');
  try { fs.chmodSync(DEPLOY_RECORDS_FILE, 0o600); } catch (_) {}
}

function buildDeployRecord(creds, urls, deployMeta = {}) {
  const now = new Date();
  const receipt = deployMeta.opsReceipt || {};
  const isOps = creds.deployMode === 'ops';
  const domain =
    isOps && creds.opsServerName && creds.opsServerName !== '_' ? creds.opsServerName : creds.host;
  const siteUrl = isOps ? `http://${domain}/` : (urls.site || urls.static80 || buildSiteUrl(creds.host, creds.sitePort));
  const backendUrl =
    isOps || creds.deployMode === 'card' ? joinUrl(siteUrl, 'admin/') : '';
  const generatedHint = '自动生成，见目标服务器 /root/wzry-space-install.env';
  const appDownloadPath =
    isOps && receipt.APP_FILE ? joinUrl(siteUrl, `apk/${receipt.APP_FILE}`) :
    isOps ? joinUrl(siteUrl, 'apk/') : '';

  return {
    id: `${now.toISOString().replace(/[-:T.Z]/g, '').slice(0, 14)}-${creds.host}`,
    createdAt: now.toISOString(),
    mode: creds.deployMode,
    modeLabel: variantLabel(creds.deployMode),
    ssh: {
      host: creds.host,
      port: creds.port,
      username: creds.username,
      password: creds.password,
    },
    site: {
      url: siteUrl,
      port: isOps ? 80 : creds.sitePort,
      domain,
      path: isOps ? (receipt.SITE_DIR || '') : `/www/wwwroot/${creds.sitePath || creds.host}`,
    },
    backend: {
      url: backendUrl,
      username: isOps ? (receipt.ADMIN_USER || creds.opsAdminUser || 'admin') : (creds.deployMode === 'card' ? 'admin' : ''),
      password: isOps
        ? (receipt.ADMIN_PASSWORD || creds.opsAdminPassword || generatedHint)
        : (creds.deployMode === 'card' ? creds.cardAdminPassword : ''),
    },
    database: {
      name: isOps ? (receipt.DB_NAME || 'wzry_space') : '',
      username: isOps ? (receipt.DB_USER || 'wzry_space') : '',
      password: isOps ? (receipt.DB_PASSWORD || creds.opsDbPassword || generatedHint) : '',
      rootPassword: isOps ? (creds.opsDbRootPassword || '') : '',
    },
    app: {
      downloadPath: appDownloadPath,
    },
    notes: buildRecordNotes(creds, receipt),
  };
}

function buildRecordNotes(creds, receipt) {
  const notes = [];
  if (creds.deployMode === 'clean') notes.push('纯净版无后台和数据库。');
  if (creds.deployMode === 'card') notes.push('卡密版后台为文件型卡密后台，数据文件在站点 data 目录。');
  if (creds.deployMode === 'ops') {
    notes.push('运营版安装记录同时保存在目标服务器 /root/wzry-space-install.env。');
    if (receipt.SRC_DIR) notes.push(`源码目录: ${receipt.SRC_DIR}`);
    if (receipt.SOURCE) notes.push(`源码线路: ${receipt.SOURCE}`);
  }
  return notes;
}

function joinUrl(base, suffix) {
  return `${String(base || '').replace(/\/+$/, '')}/${String(suffix || '').replace(/^\/+/, '')}`;
}

function parseEnvText(raw) {
  const out = {};
  for (const line of String(raw || '').split(/\r?\n/)) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}

function renderAdminPage() {
  return `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>部署后台</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#0b1020;color:#e8eefc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
    .wrap{max-width:1320px;margin:0 auto;padding:28px 18px 48px}.top{display:flex;gap:16px;align-items:flex-end;justify-content:space-between;margin-bottom:18px}
    h1{margin:0;font-size:26px;letter-spacing:0}.muted{color:#93a4bd;font-size:13px}.hidden{display:none!important}
    .panel{background:#111a2e;border:1px solid #22304c;border-radius:8px;padding:14px;margin-bottom:14px}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    input{height:38px;min-width:180px;border:1px solid #314568;background:#070b16;color:#e8eefc;border-radius:6px;padding:0 12px}
    input[type=number]{min-width:90px}button,a.btn{height:38px;border:0;border-radius:6px;background:#38bdf8;color:#06111f;font-weight:700;padding:0 14px;cursor:pointer;display:inline-flex;align-items:center;text-decoration:none}
    .ghost{background:#1e293b!important;color:#d8e5f8!important}.danger{background:#ef4444!important;color:white!important}.status{min-height:20px;margin-top:8px;color:#fbbf24;font-size:13px}
    .stats{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin:12px 0}.stat{background:#0f172a;border:1px solid #22304c;border-radius:8px;padding:12px}.stat strong{display:block;font-size:22px;color:#bae6fd}
    .table-wrap{overflow:auto;border:1px solid #22304c;border-radius:8px;background:#0f172a;margin-top:10px}table{width:100%;border-collapse:collapse;min-width:1180px}
    th,td{padding:10px 12px;border-bottom:1px solid #22304c;text-align:left;vertical-align:top;font-size:13px}th{position:sticky;top:0;background:#16233a;color:#bfdbfe;z-index:1}
    code{color:#bae6fd;word-break:break-all}.secret{color:#fef3c7}.empty{padding:34px;text-align:center;color:#93a4bd}.note{max-width:260px;color:#a7b6ce;line-height:1.5}
    .copy{height:28px;padding:0 10px;font-size:12px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:18px}.section-title h2{font-size:18px;margin:0}
    .new-cards{white-space:pre-wrap;line-height:1.7;background:#07111f;border:1px solid #22304c;border-radius:8px;padding:12px;color:#dbeafe;max-height:220px;overflow:auto}
    @media(max-width:760px){.top{align-items:flex-start;flex-direction:column}.stats{grid-template-columns:1fr 1fr}table{min-width:980px}}
  </style>
</head>
<body>
  <main class="wrap">
    <div class="top">
      <div>
        <h1>一键部署后台</h1>
        <div class="muted">生成一次性部署卡密，查看已部署成功的服务器、数据库、后台和 APP 信息。</div>
      </div>
      <div class="row">
        <a class="btn ghost" href="/">返回部署页</a>
        <button class="ghost hidden" id="refreshBtn">刷新</button>
        <button class="danger hidden" id="logoutBtn">退出</button>
      </div>
    </div>

    <section class="panel" id="loginPanel">
      <form class="row" id="loginForm">
        <label for="adminUser">后台账号</label>
        <input id="adminUser" autocomplete="username" value="${ADMIN_USERNAME.replace(/"/g, '&quot;')}">
        <label for="adminPass">后台密码</label>
        <input id="adminPass" type="password" autocomplete="current-password">
        <button type="submit">登录后台</button>
      </form>
      <div class="status" id="status"></div>
    </section>

    <section id="adminPanel" class="hidden">
      <div class="stats">
        <div class="stat"><span class="muted">部署记录</span><strong id="recordCount">0</strong></div>
        <div class="stat"><span class="muted">未使用卡密</span><strong id="unusedCount">0</strong></div>
        <div class="stat"><span class="muted">已使用卡密</span><strong id="usedCount">0</strong></div>
        <div class="stat"><span class="muted">进行中</span><strong id="runningCount">0</strong></div>
      </div>

      <section class="panel">
        <div class="section-title">
          <h2>生成部署卡密</h2>
          <button class="ghost" id="copyNewCards">复制新卡密</button>
        </div>
        <form class="row" id="cardForm">
          <label for="cardQty">数量</label>
          <input id="cardQty" type="number" min="1" max="100" value="1">
          <label for="cardNote">备注</label>
          <input id="cardNote" placeholder="客户/用途，可留空">
          <button type="submit">生成卡密</button>
        </form>
        <div class="status" id="cardStatus"></div>
        <pre class="new-cards hidden" id="newCards"></pre>
      </section>

      <section>
        <div class="section-title">
          <h2>卡密列表</h2>
          <span class="muted">成功部署后自动失效</span>
        </div>
        <div class="table-wrap" id="cards"></div>
      </section>

      <section>
        <div class="section-title">
          <h2>已部署服务器信息</h2>
          <button class="ghost" id="copyAll">复制全部部署记录</button>
        </div>
        <div class="table-wrap" id="records"></div>
      </section>
    </section>
  </main>
  <script>
    const els = {
      loginPanel: document.getElementById('loginPanel'),
      adminPanel: document.getElementById('adminPanel'),
      loginForm: document.getElementById('loginForm'),
      adminUser: document.getElementById('adminUser'),
      adminPass: document.getElementById('adminPass'),
      status: document.getElementById('status'),
      cardForm: document.getElementById('cardForm'),
      cardQty: document.getElementById('cardQty'),
      cardNote: document.getElementById('cardNote'),
      cardStatus: document.getElementById('cardStatus'),
      newCards: document.getElementById('newCards'),
      cards: document.getElementById('cards'),
      records: document.getElementById('records'),
      refresh: document.getElementById('refreshBtn'),
      logout: document.getElementById('logoutBtn'),
      copyAll: document.getElementById('copyAll'),
      copyNewCards: document.getElementById('copyNewCards'),
      recordCount: document.getElementById('recordCount'),
      unusedCount: document.getElementById('unusedCount'),
      usedCount: document.getElementById('usedCount'),
      runningCount: document.getElementById('runningCount'),
    };
    let latestRecords = [];
    let latestCards = [];
    let latestNewCards = [];
    let token = sessionStorage.getItem('radar.adminToken') || '';
    function esc(v){return String(v ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));}
    function value(v){return v ? '<code>'+esc(v)+'</code>' : '<span class="muted">-</span>';}
    function secret(v){return v ? '<code class="secret">'+esc(v)+'</code>' : '<span class="muted">-</span>';}
    function fmtTime(v){try{return new Date(v).toLocaleString('zh-CN',{hour12:false});}catch(_){return v || '';}}
    function authHeaders(){return {'content-type':'application/json','x-admin-token':token};}
    function showAuthed(ok){
      els.loginPanel.classList.toggle('hidden', ok);
      els.adminPanel.classList.toggle('hidden', !ok);
      els.refresh.classList.toggle('hidden', !ok);
      els.logout.classList.toggle('hidden', !ok);
    }
    async function login(){
      els.status.textContent = '正在登录...';
      const res = await fetch('/api/admin/login', {
        method:'POST',
        headers:{'content-type':'application/json'},
        body: JSON.stringify({username: els.adminUser.value.trim(), password: els.adminPass.value})
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.status.textContent = data.message || '登录失败';
        return;
      }
      token = data.token;
      sessionStorage.setItem('radar.adminToken', token);
      els.adminPass.value = '';
      showAuthed(true);
      await loadSummary();
    }
    async function loadSummary(){
      const res = await fetch('/api/admin/summary', {headers:authHeaders(), cache:'no-store'});
      if (res.status === 401) {
        sessionStorage.removeItem('radar.adminToken');
        token = '';
        showAuthed(false);
        els.status.textContent = '请重新登录后台。';
        return;
      }
      const data = await res.json();
      latestRecords = data.records || [];
      latestCards = data.cards || [];
      renderCards(latestCards);
      renderRecords(latestRecords);
      renderStats();
    }
    async function createCards(){
      els.cardStatus.textContent = '正在生成...';
      const res = await fetch('/api/admin/cards', {
        method:'POST',
        headers:authHeaders(),
        body: JSON.stringify({quantity: Number(els.cardQty.value || 1), note: els.cardNote.value.trim()})
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.cardStatus.textContent = data.message || '生成失败';
        return;
      }
      latestNewCards = data.cards || [];
      els.newCards.textContent = latestNewCards.map(c => c.code).join('\\n');
      els.newCards.classList.toggle('hidden', !latestNewCards.length);
      els.cardStatus.textContent = '已生成 ' + latestNewCards.length + ' 张部署卡密';
      await loadSummary();
    }
    function renderStats(){
      els.recordCount.textContent = latestRecords.length;
      els.unusedCount.textContent = latestCards.filter(c => c.status === 'unused').length;
      els.usedCount.textContent = latestCards.filter(c => c.status === 'used').length;
      els.runningCount.textContent = latestCards.filter(c => c.status === 'running').length;
    }
    function renderCards(cards){
      if (!cards.length) {
        els.cards.innerHTML = '<div class="empty">暂无卡密，请先生成</div>';
        return;
      }
      const statusText = {unused:'未使用', running:'部署中', used:'已使用'};
      els.cards.innerHTML = '<table><thead><tr><th>卡密</th><th>状态</th><th>备注</th><th>生成时间</th><th>使用时间</th><th>部署版本</th><th>操作</th></tr></thead><tbody>' +
        cards.map((c, i) => '<tr>' +
          '<td>'+secret(c.code)+'</td>' +
          '<td>'+esc(statusText[c.status] || c.status || '')+'</td>' +
          '<td>'+esc(c.note || '')+'</td>' +
          '<td>'+esc(fmtTime(c.createdAt))+'</td>' +
          '<td>'+esc(fmtTime(c.usedAt || c.lockedAt || ''))+'</td>' +
          '<td>'+esc(c.deployMode || '')+'</td>' +
          '<td><button class="copy" data-card="'+i+'">复制</button></td>' +
        '</tr>').join('') + '</tbody></table>';
    }
    function renderRecords(records){
      if (!records.length) {
        els.records.innerHTML = '<div class="empty">暂无部署成功记录</div>';
        return;
      }
      els.records.innerHTML = '<table><thead><tr>' +
        '<th>时间</th><th>版本</th><th>前台</th><th>SSH</th><th>后台</th><th>数据库</th><th>APP</th><th>备注</th><th>操作</th>' +
        '</tr></thead><tbody>' + records.map((r, i) => {
          const ssh = (r.ssh || {}), site = (r.site || {}), backend = (r.backend || {}), db = (r.database || {}), app = (r.app || {});
          const notes = Array.isArray(r.notes) ? r.notes.join('\n') : '';
          return '<tr>' +
            '<td>'+esc(fmtTime(r.createdAt))+'</td>' +
            '<td>'+esc(r.modeLabel || r.mode || '')+'</td>' +
            '<td>'+value(site.url)+'<br><span class="muted">端口 '+esc(site.port || '')+'</span><br>'+value(site.path)+'</td>' +
            '<td>'+value(ssh.username ? ssh.username + '@' + ssh.host + ':' + ssh.port : '')+'<br>密码：'+secret(ssh.password)+'</td>' +
            '<td>'+value(backend.url)+'<br>用户：'+value(backend.username)+'<br>密码：'+secret(backend.password)+'</td>' +
            '<td>库名：'+value(db.name)+'<br>用户：'+value(db.username)+'<br>密码：'+secret(db.password)+'<br>root：'+secret(db.rootPassword)+'</td>' +
            '<td>'+value(app.downloadPath)+'</td>' +
            '<td class="note">'+esc(notes).replace(/\\n/g,'<br>')+'</td>' +
            '<td><button class="copy" data-copy="'+i+'">复制</button></td>' +
          '</tr>';
        }).join('') + '</tbody></table>';
    }
    function recordText(r){
      const ssh = r.ssh || {}, site = r.site || {}, backend = r.backend || {}, db = r.database || {}, app = r.app || {};
      return [
        '时间：' + fmtTime(r.createdAt),
        '版本：' + (r.modeLabel || r.mode || ''),
        '前台地址：' + (site.url || ''),
        '站点目录：' + (site.path || ''),
        'SSH：' + (ssh.username || '') + '@' + (ssh.host || '') + ':' + (ssh.port || ''),
        'SSH密码：' + (ssh.password || ''),
        '后台地址：' + (backend.url || ''),
        '后台用户名：' + (backend.username || ''),
        '后台密码：' + (backend.password || ''),
        '数据库名：' + (db.name || ''),
        '数据库用户名：' + (db.username || ''),
        '数据库密码：' + (db.password || ''),
        'MySQL root密码：' + (db.rootPassword || ''),
        'APP下载路径：' + (app.downloadPath || ''),
        '备注：' + ((r.notes || []).join('；') || ''),
      ].join('\\n');
    }
    async function copyText(text){try{await navigator.clipboard.writeText(text);}catch(_){const t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();document.execCommand('copy');t.remove();}}
    document.addEventListener('click', e => {
      const recordBtn = e.target.closest('[data-copy]');
      const cardBtn = e.target.closest('[data-card]');
      if (!recordBtn && !cardBtn) return;
      const btn = recordBtn || cardBtn;
      const text = recordBtn
        ? recordText(latestRecords[Number(recordBtn.dataset.copy)] || {})
        : ((latestCards[Number(cardBtn.dataset.card)] || {}).code || '');
      copyText(text);
      btn.textContent = '已复制';
      setTimeout(() => btn.textContent = '复制', 1200);
    });
    els.loginForm.addEventListener('submit', e => {e.preventDefault(); login().catch(err => els.status.textContent = err.message || '登录失败');});
    els.cardForm.addEventListener('submit', e => {e.preventDefault(); createCards().catch(err => els.cardStatus.textContent = err.message || '生成失败');});
    els.refresh.addEventListener('click', () => loadSummary().catch(err => els.status.textContent = err.message || '读取失败'));
    els.logout.addEventListener('click', async () => {
      try { await fetch('/api/admin/logout', {method:'POST', headers:authHeaders()}); } catch (_) {}
      sessionStorage.removeItem('radar.adminToken');
      token = '';
      showAuthed(false);
    });
    els.copyAll.addEventListener('click', () => copyText(latestRecords.map(recordText).join('\\n\\n---\\n\\n')));
    els.copyNewCards.addEventListener('click', () => copyText(latestNewCards.map(c => c.code).join('\\n')));
    if (token) {
      showAuthed(true);
      loadSummary().catch(() => showAuthed(false));
    } else {
      showAuthed(false);
    }
  </script>
</body>
</html>`;
}

function buildSiteUrl(host, port) {
  const suffix = Number(port) === 80 ? '' : `:${port}`;
  return `http://${host}${suffix}/`;
}

function payloadDirForMode(mode) {
  const key = mode === 'card' ? 'card' : 'clean';
  return PAYLOAD_VARIANTS[key].dir;
}

function publicVariants() {
  const localVariants = Object.fromEntries(
    Object.entries(PAYLOAD_VARIANTS).map(([key, variant]) => [
      key,
      {
        label: variant.label,
        fileCount: countFiles(variant.dir),
      },
    ])
  );
  return { ...localVariants, ...REMOTE_VARIANTS };
}

function variantLabel(mode) {
  const variant = PAYLOAD_VARIANTS[mode] || REMOTE_VARIANTS[mode];
  return variant ? variant.label : mode;
}

function connectRemote(conn, creds) {
  return new Promise((resolve, reject) => {
    let settled = false;
    const done = (err) => {
      if (settled) return;
      settled = true;
      if (err) reject(err);
      else resolve();
    };
    conn.once('ready', () => done());
    conn.once('error', (err) => done(err));
    conn.connect({
      host: creds.host,
      port: creds.port,
      username: creds.username,
      password: creds.password,
      readyTimeout: 12000,
    });
  });
}

function runRemoteCommand(conn, command, opts = {}) {
  const { emit, silent = false, shouldCancel, onOutput, allowFail = false } = opts;
  ensureNotCancelled(shouldCancel);
  return new Promise((resolve, reject) => {
    let settled = false;
    let stdout = '';
    let stderr = '';
    const finish = (err, result) => {
      if (settled) return;
      settled = true;
      if (err) reject(err);
      else resolve(result);
    };

    conn.exec(command, { pty: true }, (err, stream) => {
      if (err) return finish(err);

      const handleChunk = (chunk, level) => {
        const text = chunk.toString('utf8');
        if (level === 'error') stderr += text;
        else stdout += text;
        if (!silent && emit) emitRemoteLines(emit, text, level);
        if (onOutput) onOutput(text);
        if (shouldCancel && shouldCancel()) {
          try { stream.close(); } catch (_) {}
          finish(new Error('部署已取消'));
        }
      };

      stream.on('data', (d) => handleChunk(d, 'info'));
      stream.stderr.on('data', (d) => handleChunk(d, 'info'));
      stream.on('close', (code) => {
        if (settled) return;
        if (code && !allowFail) {
          finish(new Error(`远程命令执行失败，退出码 ${code}`));
          return;
        }
        finish(null, { stdout, stderr, code: code || 0 });
      });
      stream.on('error', (streamErr) => finish(streamErr));
    });
  });
}

function buildEnsureCurlCommand() {
  const body = [
    'set -e',
    'if ! command -v curl >/dev/null 2>&1; then',
    'echo "正在安装 curl 下载工具"',
    'if command -v apt-get >/dev/null 2>&1; then apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y curl;',
    'elif command -v dnf >/dev/null 2>&1; then dnf install -y curl;',
    'elif command -v yum >/dev/null 2>&1; then yum install -y curl;',
    'else echo "未找到可用包管理器安装 curl"; exit 1; fi',
    'fi',
  ].join(' ');
  return `bash -lc ${shQuote(body)}`;
}

function buildOpsInstallCommand(creds, installCode) {
  const githubUrl = 'https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-install.sh';
  const giteeUrl = 'https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh';
  const args = [
    'bash /tmp/wzry-install.sh',
    '--source "$SRC"',
    `--install-code ${shQuote(installCode)}`,
    `--server-name ${shQuote(creds.opsServerName || '_')}`,
    `--admin-user ${shQuote(creds.opsAdminUser || 'admin')}`,
  ];
  if (creds.opsDbRootPassword) args.push(`--db-root-password ${shQuote(creds.opsDbRootPassword)}`);
  if (creds.opsDbPassword) args.push(`--db-password ${shQuote(creds.opsDbPassword)}`);
  if (creds.opsAdminPassword) args.push(`--admin-password ${shQuote(creds.opsAdminPassword)}`);
  args.push('-y');
  const body = [
    'set -e',
    'SRC=github',
    `(curl -fsSL --connect-timeout 8 --max-time 25 ${shQuote(githubUrl)} -o /tmp/wzry-install.sh || { SRC=gitee; curl -fsSL --connect-timeout 8 --max-time 25 ${shQuote(giteeUrl)} -o /tmp/wzry-install.sh; })`,
    'chmod +x /tmp/wzry-install.sh',
    args.join(' '),
  ].join('; ');
  return `bash -lc ${shQuote(body)}`;
}

function createOpsStageTracker(emit) {
  const seen = new Set();
  const hints = [
    { id: 'upload', re: /GitHub|Gitee|源码|下载|clone|curl/i, message: '正在远程拉取源码' },
    { id: 'install-java', re: /Java|JDK|OpenJDK|8888/i, message: '正在处理 Java 服务' },
    { id: 'install-nginx', re: /Nginx|nginx/i, message: '正在处理 Nginx' },
    { id: 'install-php', re: /PHP|php|MySQL|MariaDB|数据库|后台/i, message: '正在处理 PHP / 数据库 / 后台' },
    { id: 'prepare-dir', re: /站点|目录|wwwroot|SITE_DIR/i, message: '正在准备站点目录' },
    { id: 'nginx-config', re: /server_name|配置 Nginx|nginx -t/i, message: '正在写入 Nginx 配置' },
    { id: 'java-service', re: /systemctl|service|WebSocket|wzry/i, message: '正在启动服务' },
    { id: 'firewall', re: /firewall|ufw|iptables|端口|8888|9999/i, message: '正在开放端口' },
    { id: 'health', re: /健康|检查|完成|成功/i, message: '正在做健康检查' },
  ];
  return (chunk) => {
    const text = stripAnsi(chunk);
    for (const hint of hints) {
      if (!seen.has(hint.id) && hint.re.test(text)) {
        seen.add(hint.id);
        emit.step(hint.id, 'running', hint.message);
      }
    }
  };
}

function emitRemoteLines(emit, text, level) {
  const clean = stripAnsi(text);
  for (const line of clean.split(/\r?\n/)) {
    const msg = line.trim();
    if (msg) emit.log(level, msg);
  }
}

function summarizeOs(output) {
  const text = String(output || '');
  const name = (text.match(/^PRETTY_NAME="?([^"\n]+)"?/m) || text.match(/^NAME="?([^"\n]+)"?/m) || [])[1];
  return (name || text.split(/\r?\n/).find(Boolean) || '').trim();
}

function ensureNotCancelled(shouldCancel) {
  if (shouldCancel && shouldCancel()) throw new Error('部署已取消');
}

function stripAnsi(text) {
  return String(text || '').replace(/\x1B\[[0-?]*[ -/]*[@-~]/g, '');
}

function shQuote(s) {
  return `'${String(s).replace(/'/g, `'\\''`)}'`;
}

function countFiles(dir) {
  let count = 0;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      count += countFiles(path.join(dir, entry.name));
    } else if (entry.isFile()) {
      count += 1;
    }
  }
  return count;
}

function makeEmitter(socket) {
  return {
    log(level, message) {
      socket.emit('deploy:log', { level, message, ts: Date.now() });
    },
    step(id, status, message) {
      socket.emit('deploy:step', { id, status, message, ts: Date.now() });
    },
    progress(percent, message) {
      socket.emit('deploy:progress', { percent, message, ts: Date.now() });
    },
  };
}

server.listen(PORT, HOST, () => {
  console.log(`[OK] 一键部署器已启动: http://${HOST === '0.0.0.0' ? 'localhost' : HOST}:${PORT}`);
  console.log('    在浏览器里打开上面地址，输入一次性部署卡密和 SSH 信息即可部署');
  if (ADMIN_PASSWORD) {
    console.log(`[SEC] 后台管理已启用，账号: ${ADMIN_USERNAME}`);
  } else {
    console.log('[SEC] 后台管理密码未配置，请设置 ADMIN_PASSWORD');
  }
});
