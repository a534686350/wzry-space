'use strict';

const path = require('path');
const http = require('http');
const fs = require('fs');
const crypto = require('crypto');
const { execFile } = require('child_process');
const express = require('express');
const { Server: SocketIOServer } = require('socket.io');
const { Client } = require('ssh2');
const { runDeployment } = require('./deployer');

const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || '0.0.0.0';
const TRUST_PROXY = /^(1|true|yes)$/i.test(String(process.env.TRUST_PROXY || ''));
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
  { id: 'upload', label: '准备项目文件' },
  { id: 'nginx-config', label: '配置 Nginx' },
  { id: 'java-service', label: '启动 Java 服务' },
  { id: 'firewall', label: '放行防火墙端口' },
  { id: 'health', label: '健康检查' },
];
const OPS_INSTALL_CODE =
  (process.env.OPS_INSTALL_CODE || process.env.WZRY_INSTALL_CODE || '').trim();
// 旧 ACCESS_CODE 仅做兼容：部署入口使用一次性卡密，后台管理使用 ADMIN_PASSWORD。
const ACCESS_CODE = (process.env.ACCESS_CODE || '').trim();
const ACCESS_HINT = (process.env.ACCESS_HINT || '').trim();
const ADMIN_USERNAME = (process.env.ADMIN_USERNAME || 'admin').trim() || 'admin';
const ADMIN_PASSWORD =
  (process.env.ADMIN_PASSWORD || process.env.RECORD_ADMIN_PASSWORD || ACCESS_CODE || '').trim();
const ADMIN_SESSION_TTL_MS = Math.max(1800000, Number(process.env.ADMIN_SESSION_TTL_MS || 43200000) || 43200000);
const ADMIN_LOGIN_WINDOW_MS = Math.max(60000, Number(process.env.ADMIN_LOGIN_WINDOW_MS || 900000) || 900000);
const ADMIN_LOGIN_MAX_FAILURES = Math.max(3, Number(process.env.ADMIN_LOGIN_MAX_FAILURES || 10) || 10);
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
const OPS_SOURCE_PACKAGE_FILE =
  process.env.OPS_SOURCE_PACKAGE_FILE || path.join(DATA_DIR, 'ops-source.tar.gz');
const DEPLOY_RECORDS_FILE =
  process.env.DEPLOY_RECORDS_FILE || path.join(DATA_DIR, 'deploy-records.json');
const DEPLOY_CARDS_FILE =
  process.env.DEPLOY_CARDS_FILE || path.join(DATA_DIR, 'deploy-cards.json');
const SERVER_AUTHORIZATIONS_FILE =
  process.env.SERVER_AUTHORIZATIONS_FILE || path.join(DATA_DIR, 'server-authorizations.json');
const SERVER_AUTH_CODES_FILE =
  process.env.SERVER_AUTH_CODES_FILE || path.join(DATA_DIR, 'server-auth-codes.json');
const MAX_DEPLOY_RECORDS = Math.max(50, Number(process.env.MAX_DEPLOY_RECORDS || 500) || 500);
const CARD_RUNNING_TTL_MS = Math.max(600000, Number(process.env.CARD_RUNNING_TTL_MS || 7200000) || 7200000);
const DEFAULT_DEPLOY_CARD_MAX_USES = Math.max(1, Math.min(50, Number(process.env.DEPLOY_CARD_MAX_USES || 5) || 5));
const LICENSE_SERVER_URL =
  String(process.env.LICENSE_SERVER_URL || process.env.PUBLIC_LICENSE_SERVER_URL || 'http://101.200.36.103:3000').replace(/\/+$/, '');
const AUTH_GROUP_URL =
  String(process.env.AUTH_GROUP_URL || 'https://qm.qq.com/q/VcaTE1qumQ').trim();
const OPS_SOURCE_TOKEN =
  (process.env.OPS_SOURCE_TOKEN || (ADMIN_PASSWORD ? crypto.createHash('sha256').update(`ops-source:${ADMIN_PASSWORD}`).digest('hex') : '')).trim();
const SOURCE_VERSION = buildSourceVersion();
const adminSessions = new Map();
const adminLoginFailures = new Map();

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
if (TRUST_PROXY) app.set('trust proxy', true);
app.use(express.json({ limit: '256kb' }));
ensureOpsSourcePackage().catch((err) => {
  console.error('[ops-source] prepare failed:', err.message || err);
});

app.use((req, res, next) => {
  if (
    req.path === '/' ||
    req.path === '/index.html' ||
    req.path === '/admin' ||
    req.path === '/app.js' ||
    req.path.endsWith('.html')
  ) {
    res.set('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
    res.set('Pragma', 'no-cache');
    res.set('Expires', '0');
  }
  next();
});

app.get('/admin', (req, res) => {
  res.type('html').send(renderAdminPage());
});

app.get('/', (req, res) => {
  res.type('html').send(renderPortalPage());
});

app.get('/deploy', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'deploy.html'));
});

app.get('/source', (req, res) => {
  res.type('html').send(renderSourceDownloadPage());
});

app.post('/api/admin/login', (req, res) => {
  if (!ADMIN_PASSWORD) {
    res.status(503).json({ ok: false, message: '后台密码未配置，请在服务环境变量设置 ADMIN_PASSWORD' });
    return;
  }
  const username = String((req.body && req.body.username) || '').trim();
  const password = String((req.body && req.body.password) || '');
  const loginKey = adminLoginKey(req, username);
  if (isAdminLoginLimited(loginKey)) {
    res.status(429).json({ ok: false, message: '登录失败次数过多，请稍后再试' });
    return;
  }
  if (!safeEqual(username, ADMIN_USERNAME) || !safeEqual(password, ADMIN_PASSWORD)) {
    recordAdminLoginFailure(loginKey);
    res.status(401).json({ ok: false, message: '后台账号或密码不正确' });
    return;
  }
  clearAdminLoginFailure(loginKey);
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
    authorizations: publicServerAuthorizations(loadServerAuthorizations()),
    authCodes: publicServerAuthCodes(loadServerAuthCodes()),
  });
});

app.post('/api/admin/cards', requireAdmin, (req, res) => {
  const quantity = Math.max(1, Math.min(100, Number(req.body && req.body.quantity) || 1));
  const maxUses = Math.max(1, Math.min(999, Number(req.body && req.body.maxUses) || DEFAULT_DEPLOY_CARD_MAX_USES));
  const note = String((req.body && req.body.note) || '').trim().slice(0, 120);
  const cards = createDeployCards(quantity, note, maxUses);
  res.json({
    ok: true,
    cards,
  });
});

app.delete('/api/admin/cards/:id', requireAdmin, (req, res) => {
  const result = deleteDeployCard(req.params.id);
  if (!result.ok) {
    res.status(404).json(result);
    return;
  }
  res.json({ ok: true, cards: publicDeployCards(loadDeployCards()) });
});

app.post('/api/admin/server-authorizations', requireAdmin, (req, res) => {
  const result = upsertServerAuthorization(req.body || {});
  if (!result.ok) {
    res.status(400).json(result);
    return;
  }
  res.json({
    ok: true,
    authorization: publicServerAuthorizations([result.authorization])[0],
    authorizations: publicServerAuthorizations(loadServerAuthorizations()),
  });
});

app.delete('/api/admin/server-authorizations/:id', requireAdmin, (req, res) => {
  const result = deleteServerAuthorization(req.params.id);
  if (!result.ok) {
    res.status(404).json(result);
    return;
  }
  res.json({ ok: true, authorizations: publicServerAuthorizations(loadServerAuthorizations()) });
});

app.post('/api/admin/server-auth-codes', requireAdmin, (req, res) => {
  const result = createServerAuthCodes(req.body || {});
  if (!result.ok) {
    res.status(400).json(result);
    return;
  }
  res.json({ ok: true, codes: publicServerAuthCodes(result.codes), authCodes: publicServerAuthCodes(loadServerAuthCodes()) });
});

app.delete('/api/admin/server-auth-codes/:id', requireAdmin, (req, res) => {
  const result = deleteServerAuthCode(req.params.id);
  if (!result.ok) {
    res.status(404).json(result);
    return;
  }
  res.json({ ok: true, authCodes: publicServerAuthCodes(loadServerAuthCodes()) });
});

app.options('/api/license/check', (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  res.set('Access-Control-Allow-Methods', 'GET,OPTIONS');
  res.set('Access-Control-Allow-Headers', 'Content-Type');
  res.status(204).end();
});

app.options('/api/license/redeem', (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  res.set('Access-Control-Allow-Methods', 'POST,OPTIONS');
  res.set('Access-Control-Allow-Headers', 'Content-Type');
  res.status(204).end();
});

app.options('/api/source/version', (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  res.set('Access-Control-Allow-Methods', 'GET,OPTIONS');
  res.set('Access-Control-Allow-Headers', 'Content-Type');
  res.status(204).end();
});

app.get('/api/source/version', (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  res.set('Cache-Control', 'no-store');
  res.json({
    ok: true,
    sourceVersion: SOURCE_VERSION.version,
    sourceUpdatedAt: SOURCE_VERSION.updatedAt,
  });
});

app.get('/api/source/download/:mode', async (req, res) => {
  try {
    const mode = String(req.params.mode || '').trim().toLowerCase();
    if (!['clean', 'card', 'ops'].includes(mode)) {
      res.status(404).json({ ok: false, message: 'unknown source mode' });
      return;
    }
    if (mode === 'ops' && String(req.query.password || '') !== 'Abc12345') {
      res.status(403).type('html').send('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#07111f;color:#eaf4ff;padding:40px">运营版源码下载密码错误</body>');
      return;
    }
    const file = await buildManualSourcePackage(mode);
    res.set('Cache-Control', 'no-store');
    res.download(file, path.basename(file));
  } catch (err) {
    res.status(500).type('html').send(`<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#07111f;color:#eaf4ff;padding:40px"><h2>源码打包失败</h2><p>${escapeHtml(err.message || String(err))}</p></body>`);
  }
});

app.get('/api/license/check', (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  res.set('Cache-Control', 'no-store');
  const host = normalizeAuthHost(req.query.host || '');
  const domain = normalizeAuthHost(req.query.domain || '');
  const mode = normalizeAuthMode(req.query.mode || '');
  const backendRuntime = isBackendLicenseRuntime(req.query.runtime || req.query.client || '');
  const remoteHost = normalizeRemoteHost(req);
  const blocked = backendRuntime
    ? findBlockedServerAuthorization(remoteHost, mode)
    : findBlockedServerAuthorization(host, mode, [domain]);
  if (blocked) {
    res.json({
      ok: true,
      authorized: false,
      blocked: true,
      permanent: false,
      groupUrl: AUTH_GROUP_URL,
      message: '当前服务器已被后台停止使用，请联系管理员。',
    });
    return;
  }
  const match = backendRuntime
    ? findServerAuthorization(remoteHost, mode)
    : findServerAuthorization(host, mode, [domain]);
  if (!match) {
    res.json({
      ok: true,
      authorized: false,
      permanent: false,
      groupUrl: AUTH_GROUP_URL,
      message: backendRuntime
        ? '当前 Java 后端服务器来源 IP 未授权，请在后台添加该服务器 IP 授权。'
        : '当前服务器未授权，可免费使用 3 天。',
    });
    return;
  }
  res.json({
    ok: true,
    authorized: true,
    permanent: !!match.permanent,
    mode: match.mode || 'all',
    sourceVersion: SOURCE_VERSION.version,
    sourceUpdatedAt: SOURCE_VERSION.updatedAt,
    groupUrl: AUTH_GROUP_URL,
    message: '服务器授权通过',
  });
});

app.post('/api/license/redeem', (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  res.set('Cache-Control', 'no-store');
  const result = redeemServerAuthCode({
    code: req.body && req.body.code,
    host: req.body && req.body.host,
    domain: req.body && req.body.domain,
    mode: req.body && req.body.mode,
    remoteHost: normalizeRemoteHost(req),
  });
  if (!result.ok) {
    res.status(400).json({ ok: false, message: result.message });
    return;
  }
  res.json({
    ok: true,
    authorized: true,
    permanent: !!result.authorization.permanent,
    mode: result.authorization.mode || 'all',
    expiresAt: result.authorization.expiresAt || '',
    sourceVersion: SOURCE_VERSION.version,
    sourceUpdatedAt: SOURCE_VERSION.updatedAt,
    groupUrl: AUTH_GROUP_URL,
    message: '授权码兑换成功，服务器已授权',
  });
});

app.get('/api/ops-source.tar.gz', (req, res) => {
  if (!OPS_SOURCE_TOKEN || !safeEqual(String(req.query.token || ''), OPS_SOURCE_TOKEN)) {
    res.status(403).json({ ok: false, message: 'forbidden' });
    return;
  }
  if (!fs.existsSync(OPS_SOURCE_PACKAGE_FILE)) {
    res.status(404).json({ ok: false, message: 'ops source package not found' });
    return;
  }
  res.set('Cache-Control', 'no-store');
  res.download(OPS_SOURCE_PACKAGE_FILE, 'ops-source.tar.gz');
});

app.use(express.static(path.join(__dirname, 'public'), {
  etag: false,
  maxAge: 0,
  setHeaders(res, filePath) {
    if (/\.(html|js|css)$/i.test(filePath)) {
      res.set('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
      res.set('Pragma', 'no-cache');
      res.set('Expires', '0');
    }
  },
}));

app.get('/api/health', (req, res) => {
  res.json({ ok: true, payloadRoot: PAYLOAD_ROOT, variants: publicVariants() });
});

// 前端启动时读取公开元信息
app.get('/api/deploy-card/check', (req, res) => {
  res.set('Cache-Control', 'no-store');
  const result = checkDeployCard(req.query.code || '');
  if (!result.ok) {
    res.status(400).json({ ok: false, message: result.message });
    return;
  }
  const card = publicDeployCards([result.card])[0];
  res.json({
    ok: true,
    card: {
      status: card.status,
      maxUses: card.maxUses,
      usedCount: card.usedCount,
      remainingUses: card.remainingUses,
      deployMode: card.deployMode,
    },
  });
});

app.get('/api/meta', (req, res) => {
  res.json({
    accessRequired: false,
    accessHint: '',
    cardRequired: true,
    opsInstallCodeRequired: !OPS_INSTALL_CODE,
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
    creds.licenseConfig = buildLicenseConfigForTarget(creds);

    const payloadCheck = validateLocalPayloadForMode(creds.deployMode);
    if (!payloadCheck.ok) {
      socket.emit('deploy:error', { message: payloadCheck.message });
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
    if (creds.licenseConfig.permanent) {
      emit.log('success', '目标服务器已匹配永久授权，部署产物将写入本地永久授权');
    } else if (creds.licenseConfig.authorized) {
      emit.log('success', '目标服务器已在授权名单中，部署产物将启用在线授权校验');
    } else {
      emit.log('warn', '目标服务器尚未授权，部署完成后可试用 1 天，页面会提示联系授权；可在后台添加该 IP 授权');
    }

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
        staticSite: buildSiteUrl(creds.host, creds.sitePort),
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

  socket.on('clear:start', async (payload) => {
    if (activeJobs.has(socket.id)) {
      socket.emit('clear:error', { message: '已有任务在进行中，请稍候' });
      return;
    }

    const creds = sanitizeCreds(payload);
    const validation = validateCleanupCreds(creds);
    if (!validation.ok) {
      socket.emit('clear:error', { message: validation.message });
      return;
    }

    const jobState = { cancelled: false };
    activeJobs.set(socket.id, {
      cancel: () => {
        jobState.cancelled = true;
      },
    });

    const emit = makeEmitter(socket);
    emit.step('init', 'running', '开始清理服务器数据');
    emit.log('warn', '即将清理本项目部署痕迹，不会格式化整台服务器');
    emit.log('info', `目标服务器信息已接收，SSH 端口: ${creds.port}  用户: ${creds.username}`);

    try {
      await runServerCleanup({
        creds,
        emit,
        shouldCancel: () => jobState.cancelled,
      });
      emit.step('done', 'success', '清理完成');
      socket.emit('clear:done');
    } catch (err) {
      const msg = (err && err.message) || String(err);
      emit.log('error', `清理失败: ${msg}`);
      emit.step('done', 'failed', msg);
      socket.emit('clear:error', { message: msg });
    } finally {
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

async function runServerCleanup({ creds, emit, shouldCancel }) {
  const conn = new Client();
  try {
    emit.step('connect', 'running', '正在连接目标服务器');
    await connectRemote(conn, creds);
    ensureNotCancelled(shouldCancel);
    emit.step('connect', 'success', 'SSH 已连接');
    emit.progress(12, 'SSH 已连接');

    emit.step('detect', 'running', '检测系统环境');
    const osInfo = await runRemoteCommand(conn, 'cat /etc/os-release 2>/dev/null || uname -a', {
      silent: true,
      shouldCancel,
    });
    const summary = summarizeOs(osInfo.stdout || '');
    if (summary) emit.log('info', `系统信息: ${summary}`);
    emit.step('detect', 'success', '系统检测完成');
    emit.progress(22, '开始清理部署数据');

    emit.step('java-service', 'running', '停止并移除项目服务');
    emit.step('nginx-config', 'running', '清理 Nginx 项目配置');
    emit.step('prepare-dir', 'running', '删除项目站点目录与源码目录');
    await runRemoteCommand(conn, buildCleanupCommand(creds), {
      emit,
      shouldCancel,
    });

    emit.step('java-service', 'success', '项目服务已处理');
    emit.step('nginx-config', 'success', 'Nginx 配置已处理');
    emit.step('prepare-dir', 'success', '目录与数据已处理');
    emit.step('health', 'success', '清理检查完成');
    emit.progress(100, '清理完成');
  } finally {
    try { conn.end(); } catch (_) {}
  }
}

async function runOpsDeployment({ creds, emit, shouldCancel }) {
  const installCode = creds.opsInstallCode || OPS_INSTALL_CODE;
  if (!installCode) {
    throw new Error('运营版安装授权码不能为空');
  }

  emit.log('info', '==============================');
  emit.log('info', '运营版远程部署开始');
  emit.log('info', '正在准备运营版部署组件');
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

    emit.step('upload', 'running', '正在准备项目文件');
    emit.step('prepare-dir', 'running', '远程脚本将准备站点目录');
    emit.progress(24, '正在准备运营版项目文件');

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
      upload: '项目文件已准备完成',
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
    sitePort: Number(p.sitePort),
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
  if (!/^[a-zA-Z0-9._-]+$/.test(c.username)) return { ok: false, message: 'SSH 用户名格式不合法' };
  if (!c.password) return { ok: false, message: '密码不能为空' };
  return { ok: true };
}

function validateCleanupCreds(c) {
  if (!c.host) return { ok: false, message: '服务器地址不能为空' };
  if (!/^[a-zA-Z0-9\.\-\_]+$/.test(c.host)) return { ok: false, message: '服务器地址格式不合法' };
  if (!Number.isInteger(c.port) || c.port < 1 || c.port > 65535) return { ok: false, message: 'SSH 端口不合法' };
  if (!c.username) return { ok: false, message: '用户名不能为空' };
  if (!/^[a-zA-Z0-9._-]+$/.test(c.username)) return { ok: false, message: 'SSH 用户名格式不合法' };
  if (!c.password) return { ok: false, message: '密码不能为空' };
  if (c.opsDbRootPassword && c.opsDbRootPassword.length > 128) {
    return { ok: false, message: 'MySQL root 密码不能超过 128 个字符' };
  }
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
  return (bearer && bearer[1]) || req.get('x-admin-token') || '';
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

function adminLoginKey(req, username) {
  const ip = req.ip || (req.socket && req.socket.remoteAddress) || 'unknown';
  return `${ip}:${String(username || '').trim().toLowerCase() || '-'}`;
}

function isAdminLoginLimited(key) {
  cleanupAdminLoginFailures();
  const row = adminLoginFailures.get(key);
  return !!(row && row.count >= ADMIN_LOGIN_MAX_FAILURES);
}

function recordAdminLoginFailure(key) {
  cleanupAdminLoginFailures();
  const now = Date.now();
  const row = adminLoginFailures.get(key);
  if (!row || row.expiresAt <= now) {
    adminLoginFailures.set(key, { count: 1, expiresAt: now + ADMIN_LOGIN_WINDOW_MS });
    return;
  }
  row.count += 1;
}

function clearAdminLoginFailure(key) {
  adminLoginFailures.delete(key);
}

function cleanupAdminLoginFailures() {
  const now = Date.now();
  for (const [key, row] of adminLoginFailures.entries()) {
    if (!row || row.expiresAt <= now) adminLoginFailures.delete(key);
  }
}

function safeEqual(a, b) {
  const av = Buffer.from(String(a || ''), 'utf8');
  const bv = Buffer.from(String(b || ''), 'utf8');
  if (av.length !== bv.length) return false;
  return crypto.timingSafeEqual(av, bv);
}

function buildSourceVersion() {
  const targets = [
    path.resolve(__dirname, '..', '网页源码'),
    path.resolve(__dirname, '..', '网页前后台'),
    path.resolve(__dirname, '..', 'APP'),
    path.resolve(__dirname, '..', 'scripts'),
  ];
  const hash = crypto.createHash('sha256');
  let latest = 0;
  for (const target of targets) {
    if (!fs.existsSync(target)) continue;
    latest = Math.max(latest, hashTree(target, hash));
  }
  return {
    version: hash.digest('hex').slice(0, 16),
    updatedAt: latest ? new Date(latest).toISOString() : new Date().toISOString(),
  };
}

function hashTree(target, hash) {
  const st = fs.statSync(target);
  if (st.isDirectory()) {
    let latest = st.mtimeMs;
    const names = fs.readdirSync(target).sort();
    for (const name of names) {
      if (['.git', 'node_modules', 'build', 'data'].includes(name)) continue;
      latest = Math.max(latest, hashTree(path.join(target, name), hash));
    }
    return latest;
  }
  if (!st.isFile()) return st.mtimeMs;
  const rel = path.relative(path.resolve(__dirname, '..'), target).replace(/\\/g, '/');
  hash.update(rel);
  hash.update(String(st.size));
  hash.update(String(Math.floor(st.mtimeMs)));
  return st.mtimeMs;
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
    changed = normalizeDeployCard(card) || changed;
    if (card.status === 'running' && card.lockedAt && now - Date.parse(card.lockedAt) > CARD_RUNNING_TTL_MS) {
      card.status = getDeployCardStatus(card);
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

function normalizeDeployCard(card) {
  let changed = false;
  if (!Number.isFinite(Number(card.maxUses)) || Number(card.maxUses) < 1) {
    card.maxUses = DEFAULT_DEPLOY_CARD_MAX_USES;
    changed = true;
  } else {
    card.maxUses = Math.max(1, Math.min(999, Number(card.maxUses)));
  }
  if (!Array.isArray(card.uses)) {
    card.uses = [];
    changed = true;
  }
  const legacyUsed = card.status === 'used' || card.usedAt ? 1 : 0;
  const currentUsed = Number(card.usedCount);
  if (!Number.isFinite(currentUsed) || currentUsed < 0) {
    card.usedCount = Math.max(legacyUsed, card.uses.length);
    changed = true;
  } else {
    card.usedCount = Math.max(0, Math.floor(currentUsed), card.uses.length, legacyUsed);
  }
  if (card.status !== 'running') {
    const nextStatus = getDeployCardStatus(card);
    if (card.status !== nextStatus) {
      card.status = nextStatus;
      changed = true;
    }
  }
  return changed;
}

function getDeployCardStatus(card) {
  const usedCount = Math.max(0, Number(card.usedCount) || 0);
  const maxUses = Math.max(1, Number(card.maxUses) || DEFAULT_DEPLOY_CARD_MAX_USES);
  return usedCount >= maxUses ? 'used' : 'unused';
}

function createDeployCards(quantity, note, maxUses = DEFAULT_DEPLOY_CARD_MAX_USES) {
  const cards = loadDeployCards();
  const created = [];
  const cardMaxUses = Math.max(1, Math.min(999, Number(maxUses) || DEFAULT_DEPLOY_CARD_MAX_USES));
  for (let i = 0; i < quantity; i += 1) {
    let code;
    do {
      code = generateDeployCardCode();
    } while (cards.some((c) => c.code === code));
    const card = {
      id: crypto.randomBytes(12).toString('hex'),
      code,
      status: 'unused',
      maxUses: cardMaxUses,
      usedCount: 0,
      uses: [],
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
    status: card.status || getDeployCardStatus(card),
    maxUses: Math.max(1, Number(card.maxUses) || DEFAULT_DEPLOY_CARD_MAX_USES),
    usedCount: Math.max(0, Number(card.usedCount) || 0),
    remainingUses: Math.max(0, (Number(card.maxUses) || DEFAULT_DEPLOY_CARD_MAX_USES) - (Number(card.usedCount) || 0)),
    uses: Array.isArray(card.uses) ? card.uses : [],
    note: card.note || '',
    createdAt: card.createdAt || '',
    lockedAt: card.lockedAt || '',
    usedAt: card.usedAt || '',
    usedRecordId: card.usedRecordId || '',
    usedHost: card.usedHost || '',
    deployMode: card.deployMode || '',
  }));
}

function deleteDeployCard(id) {
  const value = String(id || '').trim();
  if (!value) return { ok: false, message: '缺少卡密 ID' };
  const cards = loadDeployCards();
  const idx = cards.findIndex((c) => String(c.id || '') === value);
  if (idx < 0) return { ok: false, message: '卡密不存在或已删除' };
  if (cards[idx].status === 'running') return { ok: false, message: '卡密正在部署中，暂不能删除' };
  cards.splice(idx, 1);
  saveDeployCards(cards);
  return { ok: true };
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
  if (card) {
    normalizeDeployCard(card);
    if (getDeployCardStatus(card) === 'used') return { ok: false, message: '部署卡密次数已用完' };
  }
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
  if (card) {
    normalizeDeployCard(card);
    if (getDeployCardStatus(card) === 'used') return { ok: false, message: '部署卡密次数已用完' };
  }
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
  normalizeDeployCard(card);
  const now = new Date().toISOString();
  card.usedCount = Math.min(card.maxUses, (Number(card.usedCount) || 0) + 1);
  card.usedAt = now;
  card.usedRecordId = meta.recordId || '';
  card.usedHost = meta.host || '';
  card.deployMode = meta.deployMode || card.deployMode || '';
  card.uses.push({
    usedAt: now,
    recordId: meta.recordId || '',
    host: meta.host || '',
    deployMode: meta.deployMode || card.deployMode || '',
  });
  card.status = getDeployCardStatus(card);
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
  normalizeDeployCard(card);
  card.status = getDeployCardStatus(card);
  delete card.lockId;
  delete card.lockedAt;
  delete card.lockedBy;
  delete card.pendingTarget;
  saveDeployCards(cards);
  return true;
}

function loadServerAuthorizations() {
  return loadJsonArray(SERVER_AUTHORIZATIONS_FILE);
}

function saveServerAuthorizations(rows) {
  saveJsonArray(SERVER_AUTHORIZATIONS_FILE, rows);
}

function loadServerAuthCodes() {
  return cleanupServerAuthCodes(loadJsonArray(SERVER_AUTH_CODES_FILE));
}

function saveServerAuthCodes(rows) {
  saveJsonArray(SERVER_AUTH_CODES_FILE, rows);
}

function cleanupServerAuthCodes(rows) {
  let changed = false;
  for (const row of rows) {
    if (!Array.isArray(row.uses)) {
      row.uses = [];
      changed = true;
    }
    row.maxUses = Math.max(1, Math.min(999, Number(row.maxUses) || 1));
    row.usedCount = Math.max(Number(row.usedCount) || 0, row.uses.length);
    row.mode = normalizeAuthMode(row.mode || 'all');
    row.permanent = !!row.permanent;
    row.durationYears = Math.max(0, Number(row.durationYears) || 0);
    row.durationMonths = Math.max(0, Number(row.durationMonths) || 0);
    row.durationDays = Math.max(0, Number(row.durationDays) || 0);
  }
  if (changed) saveServerAuthCodes(rows);
  return rows;
}

function publicServerAuthorizations(rows) {
  return rows.map((row) => ({
    id: row.id,
    host: row.host || '',
    mode: row.mode || 'all',
    permanent: !!row.permanent,
    blocked: !!row.blocked,
    expiresAt: row.expiresAt || '',
    expired: isAuthorizationExpired(row),
    note: row.note || '',
    createdAt: row.createdAt || '',
    updatedAt: row.updatedAt || '',
  }));
}

function publicServerAuthCodes(rows) {
  return rows.map((row) => ({
    id: row.id,
    code: row.code,
    mode: row.mode || 'all',
    permanent: !!row.permanent,
    durationYears: Number(row.durationYears || 0),
    durationMonths: Number(row.durationMonths || 0),
    durationDays: Number(row.durationDays || 0),
    maxUses: Math.max(1, Number(row.maxUses) || 1),
    usedCount: Math.max(0, Number(row.usedCount) || 0),
    remainingUses: Math.max(0, (Number(row.maxUses) || 1) - (Number(row.usedCount) || 0)),
    uses: Array.isArray(row.uses) ? row.uses : [],
    note: row.note || '',
    createdAt: row.createdAt || '',
    usedAt: row.usedAt || '',
    status: (Number(row.usedCount) || 0) >= (Number(row.maxUses) || 1) ? 'used' : 'unused',
  }));
}

function createServerAuthCodes(input) {
  const quantity = Math.max(1, Math.min(100, Number(input.quantity) || 1));
  const maxUses = Math.max(1, Math.min(999, Number(input.maxUses) || 1));
  const mode = normalizeAuthMode(input.mode || 'all');
  const permanent = !!input.permanent;
  const duration = normalizeDuration(input);
  if (!permanent && !durationToExpiresAt(duration)) return { ok: false, message: '非永久授权码请填写年/月/天，至少 1 天' };
  const note = String(input.note || '').trim().slice(0, 160);
  const rows = loadServerAuthCodes();
  const created = [];
  for (let i = 0; i < quantity; i += 1) {
    let code;
    do {
      code = generateServerAuthCode();
    } while (rows.some((r) => r.code === code));
    const row = {
      id: crypto.randomBytes(12).toString('hex'),
      code,
      mode,
      permanent,
      durationYears: permanent ? 0 : duration.years,
      durationMonths: permanent ? 0 : duration.months,
      durationDays: permanent ? 0 : duration.days,
      maxUses,
      usedCount: 0,
      uses: [],
      note,
      createdAt: new Date().toISOString(),
    };
    rows.unshift(row);
    created.push(row);
  }
  saveServerAuthCodes(rows);
  return { ok: true, codes: created };
}

function deleteServerAuthCode(id) {
  const value = String(id || '').trim();
  const rows = loadServerAuthCodes();
  const next = rows.filter((r) => r.id !== value);
  if (next.length === rows.length) return { ok: false, message: '授权码不存在或已删除' };
  saveServerAuthCodes(next);
  return { ok: true };
}

function generateServerAuthCode() {
  const a = crypto.randomBytes(3).toString('hex').toUpperCase();
  const b = crypto.randomBytes(3).toString('hex').toUpperCase();
  const c = crypto.randomBytes(2).toString('hex').toUpperCase();
  return `AUTH-${a}-${b}-${c}`;
}

function redeemServerAuthCode(input) {
  const codeValue = String(input.code || '').trim().toUpperCase();
  if (!codeValue) return { ok: false, message: '请输入授权码' };
  const host = normalizeAuthHost(input.host || input.domain || input.remoteHost || '');
  const domain = normalizeAuthHost(input.domain || '');
  const remoteHost = normalizeAuthHost(input.remoteHost || '');
  const targetHost = host || domain || remoteHost;
  if (!targetHost) return { ok: false, message: '无法识别当前服务器地址' };
  if (!isValidAuthHost(targetHost)) return { ok: false, message: '当前服务器地址格式不合法' };
  const rows = loadServerAuthCodes();
  const row = rows.find((r) => String(r.code || '').toUpperCase() === codeValue);
  if (!row) return { ok: false, message: '授权码不存在' };
  cleanupServerAuthCodes(rows);
  if ((Number(row.usedCount) || 0) >= (Number(row.maxUses) || 1)) return { ok: false, message: '授权码次数已用完' };
  const mode = normalizeAuthMode(row.mode || input.mode || 'all');
  const expiresAt = row.permanent ? '' : durationToExpiresAt(row);
  if (!row.permanent && !expiresAt) return { ok: false, message: '授权码时长无效，请联系管理员重新生成' };
  const result = upsertServerAuthorization({
    host: targetHost,
    mode,
    permanent: !!row.permanent,
    expiresAt,
    note: `授权码兑换 ${row.code}${row.note ? ` - ${row.note}` : ''}`,
  });
  if (!result.ok) return result;
  const now = new Date().toISOString();
  row.usedCount = Math.min(Number(row.maxUses) || 1, (Number(row.usedCount) || 0) + 1);
  row.usedAt = now;
  row.uses.push({ host: targetHost, domain, remoteHost, mode, usedAt: now, authorizationId: result.authorization.id });
  saveServerAuthCodes(rows);
  return { ok: true, authorization: result.authorization, code: row };
}

function upsertServerAuthorization(input) {
  const host = normalizeAuthHost(input.host || input.ip || '');
  if (!host) return { ok: false, message: '授权 IP/域名不能为空' };
  if (!isValidAuthHost(host)) return { ok: false, message: '授权 IP/域名格式不合法' };
  const mode = normalizeAuthMode(input.mode || 'all');
  const permanent = !!input.permanent;
  const expiresAt = permanent ? '' : (normalizeExpiresAt(input.expiresAt || input.expireAt || '') || durationToExpiresAt(input));
  if (!permanent && !expiresAt) return { ok: false, message: '非永久授权必须填写到期时间' };
  const note = String(input.note || '').trim().slice(0, 160);
  const rows = loadServerAuthorizations();
  const now = new Date().toISOString();
  let row = rows.find((r) => normalizeAuthHost(r.host) === host);
  if (row) {
    row.mode = mode;
    row.permanent = permanent;
    row.blocked = false;
    row.expiresAt = expiresAt;
    row.note = note;
    row.updatedAt = now;
  } else {
    row = {
      id: crypto.randomBytes(12).toString('hex'),
      host,
      mode,
      permanent,
      blocked: false,
      expiresAt,
      note,
      createdAt: now,
      updatedAt: now,
    };
    rows.unshift(row);
  }
  saveServerAuthorizations(rows);
  return { ok: true, authorization: row };
}

function deleteServerAuthorization(id) {
  const value = String(id || '').trim();
  const rows = loadServerAuthorizations();
  const row = rows.find((r) => r.id === value);
  if (!row) return { ok: false, message: '授权记录不存在' };
  row.blocked = true;
  row.permanent = false;
  row.expiresAt = new Date(Date.now() - 1000).toISOString();
  row.updatedAt = new Date().toISOString();
  row.note = row.note ? `${row.note}（已停止）` : '已停止';
  saveServerAuthorizations(rows);
  return { ok: true };
}

function findServerAuthorization(host, mode, aliases = []) {
  const candidates = [host, ...aliases].map(normalizeAuthHost).filter(Boolean);
  if (!candidates.length) return null;
  const currentMode = normalizeAuthMode(mode || 'all');
  return loadServerAuthorizations().find((row) => {
    if (row.blocked) return false;
    if (isAuthorizationExpired(row)) return false;
    const rowHost = normalizeAuthHost(row.host);
    if (!rowHost || !candidates.includes(rowHost)) return false;
    const rowMode = normalizeAuthMode(row.mode || 'all');
    return rowMode === 'all' || currentMode === 'all' || rowMode === currentMode;
  }) || null;
}

function findBlockedServerAuthorization(host, mode, aliases = []) {
  const candidates = [host, ...aliases].map(normalizeAuthHost).filter(Boolean);
  if (!candidates.length) return null;
  const currentMode = normalizeAuthMode(mode || 'all');
  return loadServerAuthorizations().find((row) => {
    if (!row.blocked) return false;
    const rowHost = normalizeAuthHost(row.host);
    if (!rowHost || !candidates.includes(rowHost)) return false;
    const rowMode = normalizeAuthMode(row.mode || 'all');
    return rowMode === 'all' || currentMode === 'all' || rowMode === currentMode;
  }) || null;
}

function normalizeAuthHost(value) {
  return String(value || '').trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/:\d+$/, '');
}

function normalizeExpiresAt(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  const date = new Date(raw);
  if (!Number.isFinite(date.getTime())) return '';
  if (date.getTime() <= Date.now()) return '';
  return date.toISOString();
}

function normalizeDuration(input) {
  return {
    years: Math.max(0, Math.min(50, Number(input.durationYears || input.years || 0) || 0)),
    months: Math.max(0, Math.min(600, Number(input.durationMonths || input.months || 0) || 0)),
    days: Math.max(0, Math.min(36500, Number(input.durationDays || input.days || 0) || 0)),
  };
}

function durationToExpiresAt(input) {
  const d = normalizeDuration(input || {});
  if (!d.years && !d.months && !d.days) return '';
  const date = new Date();
  date.setFullYear(date.getFullYear() + d.years);
  date.setMonth(date.getMonth() + d.months);
  date.setDate(date.getDate() + d.days);
  if (!Number.isFinite(date.getTime()) || date.getTime() <= Date.now()) return '';
  return date.toISOString();
}

function isAuthorizationExpired(row) {
  if (!row || row.permanent) return false;
  if (!row.expiresAt) return true;
  const time = new Date(row.expiresAt).getTime();
  return !Number.isFinite(time) || time <= Date.now();
}

function normalizeRemoteHost(req) {
  const raw = String((req && req.socket && req.socket.remoteAddress) || req.ip || '').trim();
  return normalizeAuthHost(raw.replace(/^::ffff:/, '').replace(/^\[|\]$/g, ''));
}

function isBackendLicenseRuntime(value) {
  return /^(java|jar|backend|server)$/i.test(String(value || '').trim());
}

function normalizeAuthMode(value) {
  const mode = String(value || 'all').trim().toLowerCase();
  return ['all', 'clean', 'card', 'ops'].includes(mode) ? mode : 'all';
}

function isValidAuthHost(value) {
  const host = normalizeAuthHost(value);
  if (!host || host.length > 253) return false;
  return /^[a-z0-9][a-z0-9.\-_]*[a-z0-9]$|^[a-z0-9]$/i.test(host);
}

function buildLicenseConfigForTarget(creds) {
  const aliases = [];
  if (creds.opsServerName && creds.opsServerName !== '_') aliases.push(creds.opsServerName);
  const matched = findServerAuthorization(creds.host, creds.deployMode, aliases);
  return {
    serverUrl: LICENSE_SERVER_URL,
    host: normalizeAuthHost(creds.host),
    mode: normalizeAuthMode(creds.deployMode),
    sourceVersion: SOURCE_VERSION.version,
    authorized: !!matched,
    permanent: !!(matched && matched.permanent),
    groupUrl: AUTH_GROUP_URL,
    groupName: '王者雷达共享开黑组队群',
  };
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
  const receiptSitePort = Number(receipt.SITE_PORT || 0);
  const sitePort = isOps && Number.isInteger(receiptSitePort) && receiptSitePort > 0
    ? receiptSitePort
    : creds.sitePort;
  const siteUrl = isOps ? buildSiteUrl(domain, sitePort) : (urls.site || urls.staticSite || buildSiteUrl(creds.host, sitePort));
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
      port: sitePort,
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
    if (receipt.SRC_DIR) notes.push(`项目目录: ${receipt.SRC_DIR}`);
    if (receipt.SOURCE) notes.push(`部署线路: ${receipt.SOURCE}`);
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
    *{box-sizing:border-box}body{margin:0;background:#07100f;color:#ecf5ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
    body:before{content:"";position:fixed;inset:0;z-index:-1;background:linear-gradient(135deg,rgba(12,39,35,.94),rgba(8,13,22,.98) 56%,rgba(27,21,40,.94)),radial-gradient(circle at 12% 0%,rgba(45,212,191,.22),transparent 32%),radial-gradient(circle at 90% 10%,rgba(248,181,71,.14),transparent 30%)}
    .wrap{max-width:1440px;margin:0 auto;padding:24px 20px 48px}.top{display:flex;gap:16px;align-items:center;justify-content:space-between;margin-bottom:16px;padding:14px 0;border-bottom:1px solid rgba(148,163,184,.16)}
    h1{margin:0;font-size:24px;letter-spacing:0}.muted{color:#9fb2c9;font-size:13px}.hidden{display:none!important}
    .panel{position:relative;background:rgba(10,20,28,.78);border:1px solid rgba(148,163,184,.16);border-radius:8px;padding:16px;margin-bottom:14px;box-shadow:0 18px 50px rgba(0,0,0,.26)}.panel:before{content:"";position:absolute;left:0;right:0;top:0;height:2px;background:linear-gradient(90deg,#2dd4bf,#f8b547,#60a5fa);border-radius:8px 8px 0 0}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    input,select{height:38px;min-width:180px;border:1px solid rgba(148,163,184,.2);background:rgba(2,8,12,.55);color:#ecf5ff;border-radius:7px;padding:0 12px;outline:none}input:focus,select:focus{border-color:#2dd4bf;box-shadow:0 0 0 3px rgba(45,212,191,.14)}
    input[type=number]{min-width:90px}.duration-input{width:84px;min-width:72px}.check{min-width:auto;height:auto}button,a.btn{height:38px;border:0;border-radius:7px;background:linear-gradient(135deg,#2dd4bf,#60a5fa);color:#041016;font-weight:800;padding:0 14px;cursor:pointer;display:inline-flex;align-items:center;text-decoration:none}
    .ghost{background:#182438!important;color:#d8e5f8!important;border:1px solid rgba(148,163,184,.18)!important}.danger{background:#ef4444!important;color:white!important}.status{min-height:20px;margin-top:8px;color:#f8b547;font-size:13px}
    .stats{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin:12px 0}.stat{background:rgba(15,23,42,.72);border:1px solid rgba(148,163,184,.16);border-radius:8px;padding:14px}.stat strong{display:block;font-size:24px;color:#67e8f9}
    .table-wrap{overflow:auto;border:1px solid rgba(148,163,184,.16);border-radius:8px;background:rgba(8,16,24,.78);margin-top:10px}table{width:100%;border-collapse:collapse;min-width:1180px}
    th,td{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.13);text-align:left;vertical-align:top;font-size:13px}th{position:sticky;top:0;background:#102034;color:#bfdbfe;z-index:1}
    code{color:#bae6fd;word-break:break-all}.secret{color:#fef3c7}.empty{padding:34px;text-align:center;color:#9fb2c9}.note{max-width:260px;color:#a7b6ce;line-height:1.5}
    .copy{height:28px;padding:0 10px;font-size:12px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:18px}.section-title h2{font-size:18px;margin:0}
    .filters{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px;margin:10px 0}.filters input,.filters select{width:100%}
    .new-cards{white-space:pre-wrap;line-height:1.7;background:rgba(2,8,12,.56);border:1px solid rgba(148,163,184,.16);border-radius:8px;padding:12px;color:#dbeafe;max-height:220px;overflow:auto}
    .admin-shell{display:grid;grid-template-columns:220px minmax(0,1fr);gap:14px;align-items:start}.admin-nav{position:sticky;top:16px;background:rgba(8,16,24,.8);border:1px solid rgba(148,163,184,.16);border-radius:8px;padding:10px;box-shadow:0 18px 50px rgba(0,0,0,.22)}.admin-nav-title{padding:8px 10px 12px;color:#9fb2c9;font-size:12px}.nav-btn{width:100%;justify-content:flex-start;margin-bottom:8px;background:transparent!important;color:#d8e5f8!important;border:1px solid rgba(148,163,184,.14)!important}.nav-btn.active{background:linear-gradient(135deg,#2dd4bf,#60a5fa)!important;color:#041016!important;border-color:transparent!important}.admin-view.hidden-view{display:none!important}
    #loginPanel{max-width:460px;margin:78px auto 0;padding:22px}#loginPanel .row{display:grid;grid-template-columns:1fr;gap:9px}#loginPanel input,#loginPanel button{width:100%;height:44px}#loginPanel label{color:#9fb2c9;font-size:13px}
    @media(max-width:900px){.admin-shell{grid-template-columns:1fr}.admin-nav{position:static;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.admin-nav-title{grid-column:1/-1}.nav-btn{margin:0}}
    @media(max-width:760px){.top{align-items:flex-start;flex-direction:column}.stats{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}table{min-width:980px}}
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
        <button type="submit" id="loginBtn">登录后台</button>
      </form>
      <div class="status" id="status"></div>
    </section>

    <section id="adminPanel" class="hidden admin-shell">
      <aside class="admin-nav">
        <div class="admin-nav-title">管理分栏</div>
        <button class="nav-btn active" type="button" data-admin-tab="overview">概览</button>
        <button class="nav-btn" type="button" data-admin-tab="cards">卡密列表</button>
        <button class="nav-btn" type="button" data-admin-tab="auth">服务器授权管理</button>
        <button class="nav-btn" type="button" data-admin-tab="records">已部署服务器信息</button>
      </aside>
      <div class="admin-content">
      <div class="admin-view" data-admin-view="overview">
        <div class="stats">
          <div class="stat"><span class="muted">部署记录</span><strong id="recordCount">0</strong></div>
          <div class="stat"><span class="muted">未使用卡密</span><strong id="unusedCount">0</strong></div>
          <div class="stat"><span class="muted">已使用卡密</span><strong id="usedCount">0</strong></div>
          <div class="stat"><span class="muted">进行中</span><strong id="runningCount">0</strong></div>
        </div>
      </div>

      <section class="panel admin-view hidden-view" data-admin-view="cards">
        <div class="section-title">
          <h2>生成部署卡密</h2>
          <button class="ghost" id="copyNewCards">复制新卡密</button>
        </div>
        <form class="row" id="cardForm">
          <label for="cardQty">数量</label>
          <input id="cardQty" type="number" min="1" max="100" value="1">
          <label for="cardMaxUses">使用上限</label>
          <input id="cardMaxUses" type="number" min="1" max="999" value="${DEFAULT_DEPLOY_CARD_MAX_USES}">
          <label for="cardNote">备注</label>
          <input id="cardNote" placeholder="客户/用途，可留空">
          <button type="submit">生成卡密</button>
        </form>
        <div class="status" id="cardStatus"></div>
        <pre class="new-cards hidden" id="newCards"></pre>
      </section>

      <section class="admin-view hidden-view" data-admin-view="cards">
        <div class="section-title">
          <h2>卡密列表</h2>
          <span class="muted">成功部署后自动失效</span>
        </div>
        <div class="filters">
          <div><label>使用状态</label><select id="cardUsageFilter"><option value="all">全部</option><option value="unused">未使用</option><option value="used">已使用</option><option value="exhausted">已用完</option><option value="running">部署中</option></select></div>
          <div><label>部署版本</label><select id="cardModeFilter"><option value="all">全部版本</option><option value="clean">纯净版</option><option value="card">卡密版</option><option value="ops">运营版</option></select></div>
          <div style="grid-column:span 2"><label>搜索卡密 / 备注 / 服务器</label><input id="cardSearch" placeholder="输入关键词"></div>
        </div>
        <div class="table-wrap" id="cards"></div>
      </section>

      <section class="panel admin-view hidden-view" data-admin-view="auth">
        <div class="section-title">
          <h2>服务器授权管理</h2>
          <span class="muted">未授权服务器部署完成后可试用 1 天，页面会提示联系授权</span>
        </div>
        <form class="row" id="authForm">
          <label for="authHost">IP/域名</label>
          <input id="authHost" placeholder="例如 服务器IP或域名">
          <label for="authMode">版本</label>
          <select id="authMode">
            <option value="all">全部版本</option>
            <option value="clean">纯净版</option>
            <option value="card">卡密版</option>
            <option value="ops">运营版</option>
          </select>
          <label><input class="check" id="authPermanent" type="checkbox"> 永久授权</label>
          <label for="authYears">授权时长</label>
          <input class="duration-input" id="authYears" type="number" min="0" max="50" value="0" placeholder="年" title="授权年数">
          <input class="duration-input" id="authMonths" type="number" min="0" max="600" value="0" placeholder="月" title="授权月数">
          <input class="duration-input" id="authDays" type="number" min="0" max="36500" value="1" placeholder="天" title="授权天数">
          <input id="authNote" placeholder="客户/备注，可留空">
          <button type="submit">添加/更新授权</button>
        </form>
        <div class="status" id="authStatus"></div>
        <div class="section-title">
          <h2>生成授权码</h2>
          <button class="ghost" id="copyNewAuthCodes">复制新授权码</button>
        </div>
        <form class="row" id="authCodeForm">
          <label for="authCodeQty">数量</label>
          <input class="duration-input" id="authCodeQty" type="number" min="1" max="100" value="1">
          <label for="authCodeMode">版本</label>
          <select id="authCodeMode">
            <option value="all">全部版本</option>
            <option value="clean">纯净版</option>
            <option value="card">卡密版</option>
            <option value="ops">运营版</option>
          </select>
          <label><input class="check" id="authCodePermanent" type="checkbox"> 永久授权</label>
          <label for="authCodeYears">时长</label>
          <input class="duration-input" id="authCodeYears" type="number" min="0" max="50" value="0" placeholder="年" title="授权年数">
          <input class="duration-input" id="authCodeMonths" type="number" min="0" max="600" value="0" placeholder="月" title="授权月数">
          <input class="duration-input" id="authCodeDays" type="number" min="0" max="36500" value="1" placeholder="天" title="授权天数">
          <label for="authCodeMaxUses">可用次数</label>
          <input class="duration-input" id="authCodeMaxUses" type="number" min="1" max="999" value="1">
          <input id="authCodeNote" placeholder="客户/备注，可留空">
          <button type="submit">生成授权码</button>
        </form>
        <pre class="new-cards hidden" id="newAuthCodes"></pre>
        <div class="filters">
          <div><label>授权版本</label><select id="authListModeFilter"><option value="all">全部版本</option><option value="clean">纯净版</option><option value="card">卡密版</option><option value="ops">运营版</option></select></div>
          <div><label>授权类型</label><select id="authTypeFilter"><option value="all">全部</option><option value="permanent">永久授权</option><option value="online">限时授权</option><option value="expired">已过期</option></select></div>
          <div style="grid-column:span 2"><label>搜索 IP/域名 / 备注</label><input id="authSearch" placeholder="输入关键词"></div>
        </div>
        <div class="table-wrap" id="authorizations"></div>
        <div class="section-title">
          <h2>授权码列表</h2>
          <span class="muted">用户在未授权页面输入后自动绑定当前服务器</span>
        </div>
        <div class="table-wrap" id="authCodes"></div>
      </section>

      <section class="admin-view hidden-view" data-admin-view="records">
        <div class="section-title">
          <h2>已部署服务器信息</h2>
          <button class="ghost" id="copyAll">复制全部部署记录</button>
        </div>
        <div class="filters">
          <div><label>部署版本</label><select id="recordModeFilter"><option value="all">全部版本</option><option value="clean">纯净版</option><option value="card">卡密版</option><option value="ops">运营版</option></select></div>
          <div style="grid-column:span 3"><label>搜索服务器 / 后台 / 备注</label><input id="recordSearch" placeholder="输入关键词"></div>
        </div>
        <div class="table-wrap" id="records"></div>
      </section>
      </div>
    </section>
  </main>
  <script>
    const els = {
      loginPanel: document.getElementById('loginPanel'),
      adminPanel: document.getElementById('adminPanel'),
      loginForm: document.getElementById('loginForm'),
      adminUser: document.getElementById('adminUser'),
      adminPass: document.getElementById('adminPass'),
      loginBtn: document.getElementById('loginBtn'),
      status: document.getElementById('status'),
      cardForm: document.getElementById('cardForm'),
      cardQty: document.getElementById('cardQty'),
      cardMaxUses: document.getElementById('cardMaxUses'),
      cardNote: document.getElementById('cardNote'),
      cardStatus: document.getElementById('cardStatus'),
      newCards: document.getElementById('newCards'),
      cards: document.getElementById('cards'),
      cardUsageFilter: document.getElementById('cardUsageFilter'),
      cardModeFilter: document.getElementById('cardModeFilter'),
      cardSearch: document.getElementById('cardSearch'),
      authForm: document.getElementById('authForm'),
      authHost: document.getElementById('authHost'),
      authMode: document.getElementById('authMode'),
      authPermanent: document.getElementById('authPermanent'),
      authYears: document.getElementById('authYears'),
      authMonths: document.getElementById('authMonths'),
      authDays: document.getElementById('authDays'),
      authNote: document.getElementById('authNote'),
      authCodeForm: document.getElementById('authCodeForm'),
      authCodeQty: document.getElementById('authCodeQty'),
      authCodeMode: document.getElementById('authCodeMode'),
      authCodePermanent: document.getElementById('authCodePermanent'),
      authCodeYears: document.getElementById('authCodeYears'),
      authCodeMonths: document.getElementById('authCodeMonths'),
      authCodeDays: document.getElementById('authCodeDays'),
      authCodeMaxUses: document.getElementById('authCodeMaxUses'),
      authCodeNote: document.getElementById('authCodeNote'),
      newAuthCodes: document.getElementById('newAuthCodes'),
      copyNewAuthCodes: document.getElementById('copyNewAuthCodes'),
      authCodes: document.getElementById('authCodes'),
      authStatus: document.getElementById('authStatus'),
      authorizations: document.getElementById('authorizations'),
      authListModeFilter: document.getElementById('authListModeFilter'),
      authTypeFilter: document.getElementById('authTypeFilter'),
      authSearch: document.getElementById('authSearch'),
      records: document.getElementById('records'),
      recordModeFilter: document.getElementById('recordModeFilter'),
      recordSearch: document.getElementById('recordSearch'),
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
    let latestAuthorizations = [];
    let latestAuthCodes = [];
    let latestNewCards = [];
    let latestNewAuthCodes = [];
    let latestFilteredRecords = [];
    let token = sessionStorage.getItem('radar.adminToken') || '';
    function esc(v){return String(v ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));}
    function value(v){return v ? '<code>'+esc(v)+'</code>' : '<span class="muted">-</span>';}
    function secret(v){return v ? '<code class="secret">'+esc(v)+'</code>' : '<span class="muted">-</span>';}
    function fmtTime(v){try{return new Date(v).toLocaleString('zh-CN',{hour12:false});}catch(_){return v || '';}}
    function authHeaders(){return {'content-type':'application/json','x-admin-token':token};}
    function authDuration(){
      return {
        years: Math.max(0, Number(els.authYears.value || 0) || 0),
        months: Math.max(0, Number(els.authMonths.value || 0) || 0),
        days: Math.max(0, Number(els.authDays.value || 0) || 0),
      };
    }
    function buildAuthExpiresAt(){
      const d = authDuration();
      if (!d.years && !d.months && !d.days) return '';
      const date = new Date();
      date.setFullYear(date.getFullYear() + d.years);
      date.setMonth(date.getMonth() + d.months);
      date.setDate(date.getDate() + d.days);
      return date.toISOString();
    }
    function syncAuthDurationInputs(){
      const disabled = els.authPermanent.checked;
      [els.authYears, els.authMonths, els.authDays].forEach(input => {
        if (input) input.disabled = disabled;
      });
    }
    function authCodeDuration(){
      return {
        years: Math.max(0, Number(els.authCodeYears.value || 0) || 0),
        months: Math.max(0, Number(els.authCodeMonths.value || 0) || 0),
        days: Math.max(0, Number(els.authCodeDays.value || 0) || 0),
      };
    }
    function syncAuthCodeDurationInputs(){
      const disabled = els.authCodePermanent.checked;
      [els.authCodeYears, els.authCodeMonths, els.authCodeDays].forEach(input => {
        if (input) input.disabled = disabled;
      });
    }
    function showAuthed(ok){
      els.loginPanel.classList.toggle('hidden', ok);
      els.adminPanel.classList.toggle('hidden', !ok);
      els.refresh.classList.toggle('hidden', !ok);
      els.logout.classList.toggle('hidden', !ok);
    }
    async function login(){
      if (els.loginBtn.disabled) return;
      els.status.textContent = '正在登录...';
      els.loginBtn.disabled = true;
      try {
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
      } catch (err) {
        els.status.textContent = err.message || '登录失败，请刷新后重试';
      } finally {
        els.loginBtn.disabled = false;
      }
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
      latestAuthorizations = data.authorizations || [];
      latestAuthCodes = data.authCodes || [];
      renderFilteredTables();
      renderStats();
    }
    async function createCards(){
      els.cardStatus.textContent = '正在生成...';
      const res = await fetch('/api/admin/cards', {
        method:'POST',
        headers:authHeaders(),
        body: JSON.stringify({quantity: Number(els.cardQty.value || 1), maxUses: Number(els.cardMaxUses.value || ${DEFAULT_DEPLOY_CARD_MAX_USES}), note: els.cardNote.value.trim()})
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
    async function saveAuthorization(){
      els.authStatus.textContent = '正在保存授权...';
      const duration = authDuration();
      const expiresAt = els.authPermanent.checked ? '' : buildAuthExpiresAt();
      if (!els.authPermanent.checked && !expiresAt) {
        els.authStatus.textContent = '非永久授权请填写年/月/天，至少 1 天';
        return;
      }
      const res = await fetch('/api/admin/server-authorizations', {
        method:'POST',
        headers:authHeaders(),
        body: JSON.stringify({
          host: els.authHost.value.trim(),
          mode: els.authMode.value,
          permanent: els.authPermanent.checked,
          expiresAt,
          durationYears: duration.years,
          durationMonths: duration.months,
          durationDays: duration.days,
          note: els.authNote.value.trim()
        })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.authStatus.textContent = data.message || '保存授权失败';
        return;
      }
      els.authStatus.textContent = '授权已保存';
      els.authHost.value = '';
      els.authNote.value = '';
      els.authYears.value = '0';
      els.authMonths.value = '0';
      els.authDays.value = '1';
      els.authPermanent.checked = false;
      await loadSummary();
    }
    async function createAuthCodes(){
      const duration = authCodeDuration();
      if (!els.authCodePermanent.checked && !duration.years && !duration.months && !duration.days) {
        els.authStatus.textContent = '生成非永久授权码请填写年/月/天，至少 1 天';
        return;
      }
      els.authStatus.textContent = '正在生成授权码...';
      const res = await fetch('/api/admin/server-auth-codes', {
        method:'POST',
        headers:authHeaders(),
        body: JSON.stringify({
          quantity: Number(els.authCodeQty.value || 1),
          mode: els.authCodeMode.value,
          permanent: els.authCodePermanent.checked,
          durationYears: duration.years,
          durationMonths: duration.months,
          durationDays: duration.days,
          maxUses: Number(els.authCodeMaxUses.value || 1),
          note: els.authCodeNote.value.trim()
        })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.authStatus.textContent = data.message || '生成授权码失败';
        return;
      }
      latestNewAuthCodes = data.codes || [];
      els.newAuthCodes.textContent = latestNewAuthCodes.map(c => c.code).join('\n');
      els.newAuthCodes.classList.toggle('hidden', !latestNewAuthCodes.length);
      els.authStatus.textContent = '已生成 ' + latestNewAuthCodes.length + ' 个授权码';
      await loadSummary();
    }    async function deleteAuthorization(id){
      if (!confirm('确定取消这个服务器授权吗？取消后普通授权目标刷新页面会提示需要授权。')) return;
      const res = await fetch('/api/admin/server-authorizations/' + encodeURIComponent(id), {
        method:'DELETE',
        headers:authHeaders()
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.authStatus.textContent = data.message || '取消授权失败';
        return;
      }
      els.authStatus.textContent = '已取消授权';
      await loadSummary();
    }
    async function deleteAuthCode(id){
      if (!confirm('确定删除这个授权码吗？删除后不能恢复。')) return;
      els.authStatus.textContent = '正在删除授权码...';
      const res = await fetch('/api/admin/server-auth-codes/' + encodeURIComponent(id), {
        method:'DELETE',
        headers:authHeaders()
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.authStatus.textContent = data.message || '删除授权码失败';
        return;
      }
      els.authStatus.textContent = '授权码已删除';
      await loadSummary();
    }    async function deleteCard(id){
      if (!confirm('确定删除这张部署卡密吗？删除后不能恢复。')) return;
      els.cardStatus.textContent = '正在删除卡密...';
      const res = await fetch('/api/admin/cards/' + encodeURIComponent(id), {
        method:'DELETE',
        headers:authHeaders()
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        els.cardStatus.textContent = data.message || '删除卡密失败';
        return;
      }
      els.cardStatus.textContent = '卡密已删除';
      await loadSummary();
    }
    function norm(v){return String(v || '').toLowerCase();}
    function cardUsageState(c){
      const used = Number(c.usedCount || 0);
      const max = Number(c.maxUses || 1);
      if (c.status === 'running') return 'running';
      if (used >= max) return 'exhausted';
      if (used > 0) return 'used';
      return 'unused';
    }
    function renderFilteredTables(){
      latestFilteredRecords = filterRecords(latestRecords);
      renderCards(filterCards(latestCards));
      renderAuthorizations(filterAuthorizations(latestAuthorizations));
      renderAuthCodes(latestAuthCodes);
      renderRecords(latestFilteredRecords);
    }
    function filterCards(rows){
      const usage = els.cardUsageFilter.value;
      const mode = els.cardModeFilter.value;
      const q = norm(els.cardSearch.value);
      return rows.filter(c => {
        if (usage !== 'all' && cardUsageState(c) !== usage) return false;
        if (mode !== 'all' && (c.deployMode || '') !== mode) return false;
        if (q && !norm([c.code, c.note, c.usedHost, c.deployMode].join(' ')).includes(q)) return false;
        return true;
      });
    }
    function filterAuthorizations(rows){
      const mode = els.authListModeFilter.value;
      const type = els.authTypeFilter.value;
      const q = norm(els.authSearch.value);
      return rows.filter(r => {
        if (mode !== 'all' && (r.mode || 'all') !== mode) return false;
        if (type === 'permanent' && !r.permanent) return false;
        if (type === 'online' && r.permanent) return false;
        if (type === 'online' && r.expired) return false;
        if (type === 'expired' && !r.expired) return false;
        if (q && !norm([r.host, r.note, r.mode].join(' ')).includes(q)) return false;
        return true;
      });
    }
    function filterRecords(rows){
      const mode = els.recordModeFilter.value;
      const q = norm(els.recordSearch.value);
      return rows.filter(r => {
        if (mode !== 'all' && (r.mode || '') !== mode) return false;
        const ssh = r.ssh || {}, site = r.site || {}, backend = r.backend || {};
        const text = [r.mode, r.modeLabel, ssh.host, ssh.username, site.url, site.path, backend.url, backend.username, (r.notes || []).join(' ')].join(' ');
        return !q || norm(text).includes(q);
      });
    }
    function renderStats(){
      els.recordCount.textContent = latestRecords.length;
      els.unusedCount.textContent = latestCards.filter(c => Number(c.usedCount || 0) === 0 && c.status !== 'running').length;
      els.usedCount.textContent = latestCards.filter(c => Number(c.usedCount || 0) > 0).length;
      els.runningCount.textContent = latestCards.filter(c => c.status === 'running').length;
    }
    function renderCards(cards){
      if (!cards.length) {
        els.cards.innerHTML = '<div class="empty">暂无匹配卡密</div>';
        return;
      }
      const statusText = {unused:'未使用', used:'已使用', exhausted:'已用完', running:'部署中'};
      els.cards.innerHTML = '<table><thead><tr><th>卡密</th><th>状态</th><th>次数</th><th>备注</th><th>生成时间</th><th>最近使用</th><th>部署版本</th><th>操作</th></tr></thead><tbody>' +
        cards.map((c) => '<tr>' +
          '<td>'+secret(c.code)+'</td>' +
          '<td>'+esc(statusText[cardUsageState(c)] || c.status || '')+'</td>' +
          '<td>'+esc(Number(c.usedCount || 0))+' / '+esc(Number(c.maxUses || 1))+'<br><span class="muted">剩余 '+esc(Number(c.remainingUses || 0))+'</span></td>' +
          '<td>'+esc(c.note || '')+'</td>' +
          '<td>'+esc(fmtTime(c.createdAt))+'</td>' +
          '<td>'+esc(fmtTime(c.usedAt || c.lockedAt || ''))+'</td>' +
          '<td>'+esc(c.deployMode || '')+'</td>' +
          '<td><button class="copy" data-card-code="'+esc(c.code || '')+'">复制</button> <button class="copy danger" data-card-delete="'+esc(c.id || '')+'">删除</button></td>' +
        '</tr>').join('') + '</tbody></table>';
    }
    function renderAuthorizations(rows){
      if (!rows.length) {
        els.authorizations.innerHTML = '<div class="empty">暂无授权 IP/域名，部署后会提示需要授权</div>';
        return;
      }
      const modeText = {all:'全部版本', clean:'纯净版', card:'卡密版', ops:'运营版'};
      els.authorizations.innerHTML = '<table><thead><tr><th>IP/域名</th><th>版本</th><th>授权类型</th><th>到期时间</th><th>备注</th><th>更新时间</th><th>操作</th></tr></thead><tbody>' +
        rows.map((r) => '<tr>' +
          '<td>'+value(r.host)+'</td>' +
          '<td>'+esc(modeText[r.mode] || r.mode || '全部版本')+'</td>' +
          '<td>'+esc(r.permanent ? '永久授权' : (r.expired ? '已过期' : '限时授权'))+'</td>' +
          '<td>'+esc(r.permanent ? '永久' : fmtTime(r.expiresAt || ''))+'</td>' +
          '<td>'+esc(r.note || '')+'</td>' +
          '<td>'+esc(fmtTime(r.updatedAt || r.createdAt))+'</td>' +
          '<td><button class="copy danger" data-auth-delete="'+esc(r.id)+'">取消授权</button></td>' +
        '</tr>').join('') + '</tbody></table>';
    }
    function durationText(row){
      if (row.permanent) return '永久';
      const parts = [];
      if (Number(row.durationYears || 0)) parts.push(Number(row.durationYears) + '年');
      if (Number(row.durationMonths || 0)) parts.push(Number(row.durationMonths) + '月');
      if (Number(row.durationDays || 0)) parts.push(Number(row.durationDays) + '天');
      return parts.join(' ') || '-';
    }
    function renderAuthCodes(rows){
      if (!rows.length) {
        els.authCodes.innerHTML = '<div class="empty">暂无授权码</div>';
        return;
      }
      const modeText = {all:'全部版本', clean:'纯净版', card:'卡密版', ops:'运营版'};
      els.authCodes.innerHTML = '<table><thead><tr><th>授权码</th><th>版本</th><th>时长</th><th>次数</th><th>备注</th><th>生成时间</th><th>最近使用</th><th>操作</th></tr></thead><tbody>' +
        rows.map((r) => '<tr>' +
          '<td>'+secret(r.code)+'</td>' +
          '<td>'+esc(modeText[r.mode] || r.mode || '全部版本')+'</td>' +
          '<td>'+esc(durationText(r))+'</td>' +
          '<td>'+esc(Number(r.usedCount || 0))+' / '+esc(Number(r.maxUses || 1))+'<br><span class="muted">剩余 '+esc(Number(r.remainingUses || 0))+'</span></td>' +
          '<td>'+esc(r.note || '')+'</td>' +
          '<td>'+esc(fmtTime(r.createdAt))+'</td>' +
          '<td>'+esc(fmtTime(r.usedAt || ''))+'</td>' +
          '<td><button class="copy" data-auth-code="'+esc(r.code || '')+'">复制</button> <button class="copy danger" data-auth-code-delete="'+esc(r.id || '')+'">删除</button></td>' +
        '</tr>').join('') + '</tbody></table>';
    }
    function renderRecords(records){
      if (!records.length) {
        els.records.innerHTML = '<div class="empty">暂无部署成功记录</div>';
        return;
      }
      els.records.innerHTML = '<table><thead><tr>' +
        '<th>时间</th><th>版本</th><th>前台</th><th>SSH</th><th>后台</th><th>数据库</th><th>APP</th><th>备注</th><th>操作</th>' +
        '</tr></thead><tbody>' + records.map((r) => {
          const ssh = (r.ssh || {}), site = (r.site || {}), backend = (r.backend || {}), db = (r.database || {}), app = (r.app || {});
          const notes = Array.isArray(r.notes) ? r.notes.join(String.fromCharCode(10)) : '';
          return '<tr>' +
            '<td>'+esc(fmtTime(r.createdAt))+'</td>' +
            '<td>'+esc(r.modeLabel || r.mode || '')+'</td>' +
            '<td>'+value(site.url)+'<br><span class="muted">端口 '+esc(site.port || '')+'</span><br>'+value(site.path)+'</td>' +
            '<td>'+value(ssh.username ? ssh.username + '@' + ssh.host + ':' + ssh.port : '')+'<br>密码：'+secret(ssh.password)+'</td>' +
            '<td>'+value(backend.url)+'<br>用户：'+value(backend.username)+'<br>密码：'+secret(backend.password)+'</td>' +
            '<td>库名：'+value(db.name)+'<br>用户：'+value(db.username)+'<br>密码：'+secret(db.password)+'<br>root：'+secret(db.rootPassword)+'</td>' +
            '<td>'+value(app.downloadPath)+'</td>' +
            '<td class="note">'+esc(notes).replace(/\\n/g,'<br>')+'</td>' +
            '<td><button class="copy" data-record-id="'+esc(r.id || '')+'">复制</button></td>' +
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
      const recordBtn = e.target.closest('[data-record-id],[data-copy]');
      const cardBtn = e.target.closest('[data-card-code],[data-card]');
      const authDeleteBtn = e.target.closest('[data-auth-delete]');
      const cardDeleteBtn = e.target.closest('[data-card-delete]');
      const authCodeBtn = e.target.closest('[data-auth-code]');
      const authCodeDeleteBtn = e.target.closest('[data-auth-code-delete]');
      if (authDeleteBtn) {
        deleteAuthorization(authDeleteBtn.dataset.authDelete).catch(err => els.authStatus.textContent = err.message || '取消授权失败');
        return;
      }
      if (authCodeDeleteBtn) {
        deleteAuthCode(authCodeDeleteBtn.dataset.authCodeDelete).catch(err => els.authStatus.textContent = err.message || '删除授权码失败');
        return;
      }
      if (authCodeBtn) {
        copyText(authCodeBtn.dataset.authCode || '');
        authCodeBtn.textContent = '已复制';
        setTimeout(() => authCodeBtn.textContent = '复制', 1200);
        return;
      }
      if (cardDeleteBtn) {
        deleteCard(cardDeleteBtn.dataset.cardDelete).catch(err => els.cardStatus.textContent = err.message || '删除卡密失败');
        return;
      }
      if (!recordBtn && !cardBtn) return;
      const btn = recordBtn || cardBtn;
      const record = recordBtn
        ? (latestRecords.find(r => String(r.id || '') === String(recordBtn.dataset.recordId || '')) || latestRecords[Number(recordBtn.dataset.copy)] || {})
        : null;
      const text = recordBtn
        ? recordText(record)
        : (cardBtn.dataset.cardCode || ((latestCards[Number(cardBtn.dataset.card)] || {}).code || ''));
      copyText(text);
      btn.textContent = '已复制';
      setTimeout(() => btn.textContent = '复制', 1200);
    });
    els.loginForm.addEventListener('submit', e => {e.preventDefault(); login().catch(err => els.status.textContent = err.message || '登录失败');});
    els.loginBtn.addEventListener('click', e => {e.preventDefault(); login().catch(err => els.status.textContent = err.message || '登录失败');});
    els.cardForm.addEventListener('submit', e => {e.preventDefault(); createCards().catch(err => els.cardStatus.textContent = err.message || '生成失败');});
    els.authForm.addEventListener('submit', e => {e.preventDefault(); saveAuthorization().catch(err => els.authStatus.textContent = err.message || '保存授权失败');});
    els.authPermanent.addEventListener('change', syncAuthDurationInputs);
    els.authCodePermanent.addEventListener('change', syncAuthCodeDurationInputs);
    els.refresh.addEventListener('click', () => loadSummary().catch(err => els.status.textContent = err.message || '读取失败'));
    [els.cardUsageFilter, els.cardModeFilter, els.cardSearch, els.authListModeFilter, els.authTypeFilter, els.authSearch, els.recordModeFilter, els.recordSearch].forEach(el => {
      el.addEventListener('input', renderFilteredTables);
      el.addEventListener('change', renderFilteredTables);
    });
    els.logout.addEventListener('click', async () => {
      try { await fetch('/api/admin/logout', {method:'POST', headers:authHeaders()}); } catch (_) {}
      sessionStorage.removeItem('radar.adminToken');
      token = '';
      showAuthed(false);
    });
    els.copyAll.addEventListener('click', () => copyText(latestFilteredRecords.map(recordText).join('\\n\\n---\\n\\n')));
    els.copyNewCards.addEventListener('click', () => copyText(latestNewCards.map(c => c.code).join('\\n')));
    els.copyNewAuthCodes.addEventListener('click', () => copyText(latestNewAuthCodes.map(c => c.code).join('\\n')));
    document.querySelectorAll('[data-admin-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        const tab = btn.dataset.adminTab || 'overview';
        document.querySelectorAll('[data-admin-tab]').forEach(item => item.classList.toggle('active', item === btn));
        document.querySelectorAll('[data-admin-view]').forEach(view => view.classList.toggle('hidden-view', view.dataset.adminView !== tab));
      });
    });
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

function renderPortalPage() {
  const cards = [
    ['纯净版', '只保留雷达展示和地图切换能力，适合快速搭建展示站。'],
    ['卡密版', '带本地卡密后台，适合需要自己发放访问卡密的场景。'],
    ['运营版', '完整前后台、数据库、APP 下载与运营配置，适合正式运营。'],
  ];
  return `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>王者雷达部署中心</title>${portalCss()}</head><body><main class="wrap"><section class="hero"><div><p class="eyebrow">WZ Radar Deploy Center</p><h1>王者雷达三版本部署与源码下载</h1><p class="lead">支持一键部署，也支持下载源码手动搭建。无论哪种方式，页面和 Java 后端都会连接你的授权服务器校验授权。</p><div class="actions"><a class="btn primary" href="/deploy">进入一键部署</a><a class="btn" href="/source">源码下载与教程</a><a class="btn ghost" href="/admin">后台管理</a></div></div></section><section class="grid">${cards.map(([t,d])=>`<article class="card"><h2>${t}</h2><p>${d}</p><ul><li>保留服务器授权校验</li><li>支持源码版本升级提示</li><li>可用一键部署或手动搭建</li></ul></article>`).join('')}</section><section class="panel"><h2>推荐流程</h2><div class="steps"><div><b>1. 一键部署</b><span>填写服务器信息和部署卡密，自动安装环境、上传源码、启动 Java 服务。</span></div><div><b>2. 手动搭建</b><span>下载对应源码包，上传到网站目录，按教程配置 Nginx、PHP/数据库和 wz.jar。</span></div><div><b>3. 授权管理</b><span>后台可按 IP/域名授权，也可生成授权码给用户在未授权页面兑换。</span></div></div></section></main></body></html>`;
}

function renderSourceDownloadPage() {
  const variants = [
    ['clean', '纯净版源码', '无登录接口，适合展示和轻量使用。'],
    ['card', '卡密版源码', '包含卡密后台与授权脚本。'],
    ['ops', '运营版源码', '完整运营版前后台、数据库与 APP 目录。'],
  ];
  return `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>源码下载与手动搭建教程</title>${portalCss()}</head><body><main class="wrap"><section class="hero small"><div><p class="eyebrow">Source Packages</p><h1>源码下载与手动搭建教程</h1><p class="lead">下载包会自动带上授权校验脚本，手动搭建后仍会请求授权服务器校验，不会绕过授权。</p><div class="actions"><a class="btn" href="/">返回导航页</a><a class="btn primary" href="/deploy">使用一键部署</a></div></div></section><section class="grid">${variants.map(([m,t,d])=>`<article class="card"><h2>${t}</h2><p>${d}</p><a class="btn primary wide" href="/api/source/download/${m}">下载 ${t}</a></article>`).join('')}</section><section class="panel"><h2>手动搭建教程</h2><ol><li>在本页下载对应版本源码包，并上传到服务器网站目录，例如 <code>/www/wwwroot/wzry</code>。</li><li>解压后确认根目录存在 <code>index.html</code>、<code>radar-license.js</code> 和 <code>wz.jar</code>。授权脚本不要删除。</li><li>配置 Nginx 指向该目录，网站端口自行选择；Java 后端运行 <code>wz.jar</code>，监听项目需要的端口。</li><li>卡密版需要 PHP 支持，并保留 <code>api</code>、<code>admin</code>、<code>data</code>、<code>layui</code> 目录。</li><li>运营版需要按包内目录部署前后台、数据库和 APP 下载目录，建议优先使用一键部署自动安装。</li><li>打开页面后，如果服务器未授权，会出现授权提示；可在后台按 IP/域名授权，或生成授权码让用户在页面输入兑换。</li></ol></section><section class="panel"><h2>一键部署教程</h2><ol><li>进入 <a href="/deploy">一键部署页面</a>，填写部署卡密后会显示剩余次数。</li><li>填写服务器 IP、SSH 端口、root 账号密码和网站端口。</li><li>选择纯净版、卡密版或运营版；运营版可直接从授权服务器源码包部署。</li><li>点击测试连接，确认服务器可登录后开始部署。</li><li>部署完成后到后台查看服务器信息、授权状态、卡密和授权码。</li></ol></section></main></body></html>`;
}

function portalCss() {
  return `<style>*{box-sizing:border-box}body{margin:0;background:#07111f;color:#eaf4ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}body:before{content:"";position:fixed;inset:0;z-index:-1;background:radial-gradient(circle at 16% 0%,rgba(45,212,191,.22),transparent 34%),radial-gradient(circle at 88% 12%,rgba(96,165,250,.22),transparent 30%),linear-gradient(135deg,#08121f,#0c1726 55%,#111827)}.wrap{max-width:1180px;margin:0 auto;padding:28px 18px 54px}.hero{min-height:360px;display:flex;align-items:center}.hero.small{min-height:260px}.eyebrow{color:#67e8f9;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin:0 0 10px}h1{font-size:clamp(30px,5vw,56px);line-height:1.08;margin:0 0 16px}h2{margin:0 0 10px}.lead{max-width:760px;color:#b7c7dc;font-size:17px;line-height:1.75;margin:0 0 22px}.actions{display:flex;gap:12px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 16px;border-radius:8px;border:1px solid rgba(125,211,252,.25);background:rgba(15,23,42,.68);color:#dff6ff;text-decoration:none;font-weight:800}.btn.primary{background:linear-gradient(135deg,#2dd4bf,#60a5fa);color:#041016;border:0}.btn.ghost{background:transparent}.btn.wide{width:100%;margin-top:12px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.card,.panel{background:rgba(10,20,32,.78);border:1px solid rgba(148,163,184,.17);border-radius:8px;padding:18px;box-shadow:0 18px 48px rgba(0,0,0,.24)}.card p,.panel li,.steps span{color:#b7c7dc;line-height:1.7}.card ul{padding-left:18px;margin:12px 0 0;color:#dbeafe}.panel{margin-top:16px}.steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.steps div{border:1px solid rgba(125,211,252,.16);border-radius:8px;padding:14px;background:rgba(2,8,23,.32)}.steps b{display:block;color:#7dd3fc;margin-bottom:6px}code{background:rgba(125,211,252,.12);color:#bae6fd;border-radius:5px;padding:2px 6px}a{color:#7dd3fc}@media(max-width:820px){.hero{min-height:300px}.grid,.steps{grid-template-columns:1fr}.actions .btn{width:100%}}</style>`;
}

async function buildManualSourcePackage(mode) {
  const sourceDir = await prepareManualSourceDir(mode);
  const outDir = path.join(DATA_DIR, 'manual-source-packages');
  fs.mkdirSync(outDir, { recursive: true });
  const ext = process.platform === 'win32' ? 'zip' : 'tar.gz';
  const outFile = path.join(outDir, `wz-${mode}-source-${SOURCE_VERSION.version}.${ext}`);
  await archiveDirectory(sourceDir, outFile);
  return outFile;
}

async function prepareManualSourceDir(mode) {
  const tmpRoot = path.join(DATA_DIR, 'manual-source-work', `${mode}-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`);
  fs.mkdirSync(tmpRoot, { recursive: true });
  const target = path.join(tmpRoot, `wz-${mode}-source`);
  if (mode === 'ops') {
    await copyDir(path.resolve(__dirname, '..', '网页前后台'), target);
  } else {
    await copyDir(PAYLOAD_VARIANTS[mode].dir, target);
  }
  injectManualLicenseRuntime(target, mode);
  fs.writeFileSync(path.join(target, '手动搭建说明.txt'), manualReadme(mode), 'utf8');
  return target;
}

function injectManualLicenseRuntime(dir, mode) {
  const config = {
    serverUrl: LICENSE_SERVER_URL,
    host: '',
    mode: mode === 'ops' ? 'ops' : mode,
    sourceVersion: SOURCE_VERSION.version,
    permanent: false,
    groupUrl: AUTH_GROUP_URL,
    groupName: '王者雷达共享开黑组队群',
  };
  const js = buildManualLicenseGuardJs(config);
  fs.writeFileSync(path.join(dir, 'radar-license.js'), js, 'utf8');
  for (const name of ['index.html', 'index.php']) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) continue;
    const html = fs.readFileSync(file, 'utf8');
    fs.writeFileSync(file, injectManualLicenseScriptTag(html), 'utf8');
  }
}

function injectManualLicenseScriptTag(html) {
  const text = String(html || '');
  if (/radar-license\.js/i.test(text)) return text;
  const tag = '<script src="/radar-license.js?v=manual20260623"></script>';
  if (/<\/body>/i.test(text)) return text.replace(/<\/body>/i, `${tag}\n</body>`);
  return `${text}\n${tag}\n`;
}

function buildManualLicenseGuardJs(config) {
  const cfg = JSON.stringify(config).replace(/</g, '\\u003c');
  return `(function(){'use strict';var cfg=${cfg};if(!cfg.host)cfg.host=location.hostname||'';function esc(s){return String(s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}function closeSocket(){try{if(window.socket&&window.socket.readyState!==3)window.socket.close();}catch(e){}}function allow(data){var old=document.getElementById('radarLicenseBlocker');if(old)old.remove();window.__radarServerAuthorized=true;return true;}function redeem(input,statusEl,btn){var code=String(input&&input.value||'').trim();if(!code){statusEl.textContent='请输入授权码';return;}btn.disabled=true;statusEl.textContent='正在授权...';fetch(String(cfg.serverUrl).replace(/\\/+$/,'')+'/api/license/redeem',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:code,host:cfg.host,domain:location.hostname||'',mode:cfg.mode||'all'})}).then(function(r){return r.json().then(function(d){return {ok:r.ok,data:d};});}).then(function(ret){if(!ret.ok||!ret.data||!ret.data.ok){statusEl.textContent=(ret.data&&ret.data.message)||'授权码无效';return;}statusEl.textContent='授权成功，正在刷新...';allow(ret.data);setTimeout(function(){location.reload();},600);}).catch(function(){statusEl.textContent='授权服务器连接失败';}).then(function(){btn.disabled=false;});}function block(message){closeSocket();var old=document.getElementById('radarLicenseBlocker');if(old)old.remove();var box=document.createElement('div');box.id='radarLicenseBlocker';box.style.cssText='position:fixed;inset:0;z-index:2147483647;background:rgba(4,8,18,.92);display:flex;align-items:center;justify-content:center;padding:18px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;color:#e8eefc;';box.innerHTML='<div style="width:min(560px,94vw);background:#111827;border:1px solid rgba(96,165,250,.35);border-radius:14px;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.45);text-align:center"><h2 style="margin:0 0 12px;font-size:24px;color:#fef3c7">需要服务器授权</h2><p style="margin:0 0 16px;line-height:1.7;color:#cbd5e1">'+esc(message||'当前服务器未授权，请输入授权码。')+'</p><div style="display:grid;grid-template-columns:1fr auto;gap:10px;margin:0 auto 10px;max-width:420px"><input id="radarLicenseCodeInput" autocomplete="one-time-code" placeholder="输入授权码" style="height:42px;border-radius:8px;border:1px solid rgba(96,165,250,.35);background:rgba(15,23,42,.92);color:#e0f2fe;padding:0 12px;outline:none;font-size:14px"><button id="radarLicenseRedeemBtn" type="button" style="height:42px;border:0;border-radius:8px;background:#38bdf8;color:#06111f;font-weight:800;padding:0 16px;cursor:pointer">授权</button></div><div id="radarLicenseRedeemStatus" style="min-height:20px;margin-bottom:14px;color:#fef3c7;font-size:13px"></div><a href="'+esc(cfg.groupUrl||'#')+'" target="_blank" rel="noopener" style="color:#7dd3fc;font-weight:800">加入群聊找授权码</a></div>';document.body.appendChild(box);var input=box.querySelector('#radarLicenseCodeInput'),btn=box.querySelector('#radarLicenseRedeemBtn'),statusEl=box.querySelector('#radarLicenseRedeemStatus');btn.onclick=function(){redeem(input,statusEl,btn);};input.onkeydown=function(e){if(e.key==='Enter')redeem(input,statusEl,btn);};}function trialKey(){return 'wzry.manual.trial.'+(cfg.host||location.hostname||'server');}function trialStart(){var now=Date.now();try{var old=Number(localStorage.getItem(trialKey())||0);if(!old){localStorage.setItem(trialKey(),String(now));return now;}return old;}catch(e){return now;}}function trialLeft(){return Math.max(0,3*24*60*60*1000-(Date.now()-trialStart()));}function check(){var url=String(cfg.serverUrl).replace(/\\/+$/,'')+'/api/license/check?host='+encodeURIComponent(cfg.host)+'&domain='+encodeURIComponent(location.hostname||'')+'&mode='+encodeURIComponent(cfg.mode||'all')+'&_='+Date.now();return fetch(url,{cache:'no-store'}).then(function(r){return r.json();}).then(function(data){if(data&&data.authorized)return allow(data);if(data&&data.blocked){block(data.message||'当前服务器已被后台停止使用。');return false;}if(trialLeft()>0)return true;block(data&&data.message?data.message:'免费使用已到期，需要授权后才能继续使用。');return false;}).catch(function(){if(trialLeft()>0)return true;block('授权服务器连接失败，请联系管理员。');return false;});}document.addEventListener('DOMContentLoaded',check);window.RadarServerLicense={check:check};})();`;
}

function manualReadme(mode) {
  return `手动搭建说明

版本：${mode}
授权服务器：${LICENSE_SERVER_URL}

1. 将本目录上传到网站根目录，例如 /www/wwwroot/wzry。
2. 保留 radar-license.js，不要删除 index.html 里的授权脚本引用。
3. Nginx 网站目录指向本目录。
4. 运行 wz.jar 作为 Java 后端服务。
5. 打开页面后如提示未授权，可在授权后台添加服务器 IP/域名，或输入后台生成的授权码。
`;
}

async function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) await copyDir(from, to);
    else if (entry.isFile()) fs.copyFileSync(from, to);
  }
}

function archiveDirectory(src, outFile) {
  fs.mkdirSync(path.dirname(outFile), { recursive: true });
  try { fs.unlinkSync(outFile); } catch (_) {}
  if (process.platform !== 'win32') {
    return new Promise((resolve, reject) => {
      execFile('tar', ['-czf', outFile, '-C', path.dirname(src), path.basename(src)], { windowsHide: true }, (err) => err ? reject(err) : resolve(outFile));
    });
  }
  return new Promise((resolve, reject) => {
    const ps = [
      '-NoProfile',
      '-Command',
      `Compress-Archive -LiteralPath ${psQuote(path.join(src, '*'))} -DestinationPath ${psQuote(outFile)} -Force`,
    ];
    execFile('powershell.exe', ps, { windowsHide: true }, (err) => err ? reject(err) : resolve(outFile));
  });
}

function psQuote(value) {
  return `'${String(value).replace(/'/g, "''")}'`;
}

function payloadDirForMode(mode) {
  const key = mode === 'card' ? 'card' : 'clean';
  return PAYLOAD_VARIANTS[key].dir;
}

function validateLocalPayloadForMode(mode) {
  if (mode === 'ops') return { ok: true };
  const payloadDir = payloadDirForMode(mode);
  const indexPath = path.join(payloadDir, 'index.html');
  if (!fs.existsSync(indexPath)) {
    return { ok: false, message: `本地 payload 缺少 index.html: ${indexPath}` };
  }
  const html = fs.readFileSync(indexPath, 'utf8');
  const expectedMode = mode === 'card' ? 'card' : 'clean';
  const forbidden = [
    'site_announcement',
    'tryShowSiteAnnouncement',
    'siteAnnounceOverlay',
    'normal.js',
    'reportIP',
    'aHR0cDovL2xscXE1MjAueHl6',
    'online_ip_count',
    'client_online_heartbeat',
    'game_servers',
  ];
  const hit = forbidden.find((item) => html.includes(item));
  if (hit) {
    return { ok: false, message: `本地 payload 仍有远程弹窗或多余调用残留: ${hit}。请重启一键部署后台后再部署。` };
  }
  if (mode === 'card' && !html.includes('layui/auth.js')) {
    return { ok: false, message: '卡密版 payload 缺少 layui/auth.js，不能部署未授权页面' };
  }
  if (html.includes('RADAR_LOGIN_MODE') && !html.includes(`RADAR_LOGIN_MODE = window.RADAR_LOGIN_MODE || '${expectedMode}'`)) {
    return { ok: false, message: `本地 payload 登录模式不正确，需要 ${expectedMode}` };
  }
  return { ok: true };
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
    conn.once('error', (err) => {
      if (err && /All configured authentication methods failed/i.test(err.message || '')) {
        err.message = 'SSH认证失败：请检查服务器账号、SSH密码、端口，或确认目标服务器允许密码登录';
      }
      done(err);
    });
    conn.on('keyboard-interactive', (_name, _instructions, _lang, _prompts, finish) => {
      finish([creds.password]);
    });
    conn.connect({
      host: creds.host,
      port: creds.port,
      username: creds.username,
      password: creds.password,
      tryKeyboard: true,
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
    'if command -v apt-get >/dev/null 2>&1; then',
    'export DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none NEEDRESTART_MODE=a',
    'apt-get update && apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install -y curl',
    'elif command -v dnf >/dev/null 2>&1; then',
    'dnf install -y curl',
    'elif command -v yum >/dev/null 2>&1; then',
    'yum install -y curl',
    'else',
    'echo "未找到可用包管理器安装 curl"',
    'exit 1',
    'fi',
    'fi',
  ].join('\n');
  return `bash -lc ${shQuote(body)}`;
}

function buildOpsInstallCommand(creds, installCode) {
  const githubUrl = 'https://raw.githubusercontent.com/a534686350/wzry-space/main/scripts/cloud-install.sh';
  const giteeUrl = 'https://gitee.com/hl515/wzry-space/raw/main/scripts/cloud-install.sh';
  const sitePort = Number(creds.sitePort);
  const localInstallScriptB64 = loadLocalOpsInstallScriptBase64();
  const scriptSetup = localInstallScriptB64
    ? [
        'SRC=gitee',
        'INSTALL_SCRIPT_SRC=local',
        `cat > /tmp/wzry-install.sh.b64 <<'WZRY_INSTALL_B64'\n${localInstallScriptB64}\nWZRY_INSTALL_B64`,
        'base64 -d /tmp/wzry-install.sh.b64 > /tmp/wzry-install.sh',
      ]
    : [
        'SRC=github',
        `(curl -fsSL --connect-timeout 8 --max-time 25 ${shQuote(githubUrl)} -o /tmp/wzry-install.sh || { SRC=gitee; curl -fsSL --connect-timeout 8 --max-time 25 ${shQuote(giteeUrl)} -o /tmp/wzry-install.sh; })`,
      ];
  const baseArgs = [
    '--source "$SRC"',
    `--install-code ${shQuote(installCode)}`,
    `--server-name ${shQuote(creds.opsServerName || '_')}`,
    `--admin-user ${shQuote(creds.opsAdminUser || 'admin')}`,
  ];
  if (creds.opsDbRootPassword) baseArgs.push(`--db-root-password ${shQuote(creds.opsDbRootPassword)}`);
  if (creds.opsDbPassword) baseArgs.push(`--db-password ${shQuote(creds.opsDbPassword)}`);
  if (creds.opsAdminPassword) baseArgs.push(`--admin-password ${shQuote(creds.opsAdminPassword)}`);
  const opsSourceUrl = buildOpsSourceUrl(creds);
  if (opsSourceUrl) baseArgs.push(`--ops-source-url ${shQuote(opsSourceUrl)}`);
  baseArgs.push('-y');

  const licenseArgs = [
    `--license-host ${shQuote(creds.host)}`,
    `--license-server ${shQuote(LICENSE_SERVER_URL)}`,
    `--license-group-url ${shQuote(AUTH_GROUP_URL)}`,
    `--license-source-version ${shQuote(SOURCE_VERSION.version)}`,
  ];
  if (creds.licenseConfig && creds.licenseConfig.permanent) licenseArgs.push('--license-permanent');

  const body = [
    'set -e',
    ...scriptSetup,
    'chmod +x /tmp/wzry-install.sh',
    `export LICENSE_HOST=${shQuote(creds.host)}`,
    `export LICENSE_SERVER=${shQuote(LICENSE_SERVER_URL)}`,
    `export LICENSE_GROUP_URL=${shQuote(AUTH_GROUP_URL)}`,
    `export SITE_PORT=${shQuote(sitePort)}`,
    'PORT_ARGS=',
    `if grep -q -- '--site-port' /tmp/wzry-install.sh; then PORT_ARGS=${shQuote(`--site-port ${sitePort}`)}; fi`,
    `if grep -q -- '--license-host' /tmp/wzry-install.sh; then`,
    `  bash /tmp/wzry-install.sh ${baseArgs.join(' ')} $PORT_ARGS ${licenseArgs.join(' ')}`,
    'else',
    '  echo "安装脚本暂不支持在线授权参数，自动使用兼容模式继续部署"',
    `  bash /tmp/wzry-install.sh ${baseArgs.join(' ')} $PORT_ARGS`,
    'fi',
  ].join('\n');
  return `bash -lc ${shQuote(body)}`;
}

function buildOpsSourceUrl(creds) {
  if (!OPS_SOURCE_TOKEN) return '';
  const base = String(LICENSE_SERVER_URL || '').replace(/\/+$/, '');
  if (!base) return '';
  return `${base}/api/ops-source.tar.gz?token=${encodeURIComponent(OPS_SOURCE_TOKEN)}`;
}

function loadLocalOpsInstallScriptBase64() {
  const candidates = [
    path.resolve(__dirname, '..', 'scripts', 'cloud-install.sh'),
    path.resolve(process.cwd(), 'scripts', 'cloud-install.sh'),
  ];
  for (const file of candidates) {
    try {
      if (fs.existsSync(file)) {
        return fs.readFileSync(file).toString('base64');
      }
    } catch (_) {}
  }
  return '';
}

async function ensureOpsSourcePackage() {
  const webPublishDir = path.resolve(__dirname, '..', '网页前后台');
  const appDir = path.resolve(__dirname, '..', 'APP');
  if (!fs.existsSync(webPublishDir) || !fs.existsSync(appDir)) {
    return;
  }
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const latestSourceTime = Math.max(mtimeMsDeep(webPublishDir), mtimeMsDeep(appDir));
  const currentTime = fs.existsSync(OPS_SOURCE_PACKAGE_FILE) ? fs.statSync(OPS_SOURCE_PACKAGE_FILE).mtimeMs : 0;
  if (currentTime >= latestSourceTime) {
    return;
  }

  const stage = path.join(DATA_DIR, 'ops-source-stage');
  fs.rmSync(stage, { recursive: true, force: true });
  fs.mkdirSync(path.join(stage, 'APP'), { recursive: true });
  copyDirSync(webPublishDir, path.join(stage, '网页前后台'), shouldIncludeOpsWebFile);
  copySelectedAppFiles(appDir, path.join(stage, 'APP'));

  const tarPath = OPS_SOURCE_PACKAGE_FILE.replace(/\\/g, '/');
  const stagePosix = stage.replace(/\\/g, '/');
  const cmd = `tar -czf ${shQuote(tarPath)} -C ${shQuote(stagePosix)} APP 网页前后台`;
  await execLocal(cmd);
  fs.rmSync(stage, { recursive: true, force: true });
}

function shouldIncludeOpsWebFile(relPath) {
  const rel = relPath.replace(/\\/g, '/');
  return !/(^|\/)(auth\/config\.php|auth\/install\.lock|logs\/|\.git\/)/i.test(rel)
    && !/\.log$/i.test(rel);
}

function copySelectedAppFiles(srcDir, dstDir) {
  const files = [
    'install-services.sh',
    'start-server.sh',
    'restore-whitelist.sh',
    'ws-whitelist-helper.sh',
    'setup-whitelist-iptables.sh',
    'nginx-whitelist-ws.conf',
    path.join('auth', 'upgrade_whitelist.sql'),
  ];
  for (const rel of files) {
    const src = path.join(srcDir, rel);
    const dst = path.join(dstDir, rel);
    if (!fs.existsSync(src)) continue;
    fs.mkdirSync(path.dirname(dst), { recursive: true });
    fs.copyFileSync(src, dst);
  }
}

function copyDirSync(srcDir, dstDir, filter) {
  fs.mkdirSync(dstDir, { recursive: true });
  for (const entry of fs.readdirSync(srcDir, { withFileTypes: true })) {
    const src = path.join(srcDir, entry.name);
    const dst = path.join(dstDir, entry.name);
    const rel = path.relative(srcDir, src);
    if (filter && !filter(rel + (entry.isDirectory() ? path.sep : ''))) continue;
    if (entry.isDirectory()) copyDirSync(src, dst, (childRel) => filter(path.join(rel, childRel)));
    else if (entry.isFile()) fs.copyFileSync(src, dst);
  }
}

function mtimeMsDeep(target) {
  if (!fs.existsSync(target)) return 0;
  const st = fs.statSync(target);
  if (!st.isDirectory()) return st.mtimeMs;
  let max = st.mtimeMs;
  for (const entry of fs.readdirSync(target, { withFileTypes: true })) {
    if (entry.name === '.git' || entry.name === 'build' || entry.name === 'node_modules') continue;
    max = Math.max(max, mtimeMsDeep(path.join(target, entry.name)));
  }
  return max;
}

function execLocal(cmd) {
  const { exec } = require('child_process');
  return new Promise((resolve, reject) => {
    exec(cmd, { windowsHide: true, maxBuffer: 1024 * 1024 }, (err, stdout, stderr) => {
      if (err) {
        err.message = `${err.message}\n${stderr || stdout || ''}`.trim();
        reject(err);
      } else {
        resolve({ stdout, stderr });
      }
    });
  });
}

function buildCleanupCommand(creds) {
  const hostLabel = safeHostLabel(creds.host);
  const dbRootPassword = creds.opsDbRootPassword || '';
  const body = [
    'set -e',
    `HOST_LABEL=${shQuote(hostLabel)}`,
    `DB_ROOT_PASSWORD=${shQuote(dbRootPassword)}`,
    'echo "正在清理本项目服务和安装痕迹"',
    'for svc in radar-java home-server restore-whitelist wzry-home-server wzry-home-watchdog; do',
    '  systemctl disable --now "$svc.service" >/dev/null 2>&1 || true',
    'done',
    'systemctl disable --now wzry-home-watchdog.timer >/dev/null 2>&1 || true',
    'pkill -9 -f "/www/server/radar-java/wz.jar" >/dev/null 2>&1 || true',
    'pkill -9 -f "home-server-0.0.1-SNAPSHOT.jar" >/dev/null 2>&1 || true',
    'rm -f /etc/systemd/system/radar-java.service',
    'rm -f /etc/systemd/system/home-server.service',
    'rm -f /etc/systemd/system/restore-whitelist.service',
    'rm -f /etc/systemd/system/wzry-home-server.service',
    'rm -f /etc/systemd/system/wzry-home-watchdog.service',
    'rm -f /etc/systemd/system/wzry-home-watchdog.timer',
    'systemctl daemon-reload >/dev/null 2>&1 || true',
    'rm -f /usr/local/bin/ws-whitelist-helper.sh /etc/sudoers.d/ws-whitelist /etc/cron.d/radar-whitelist-cleanup /var/log/radar-whitelist-cleanup.log',
    'ipset destroy ws_whitelist >/dev/null 2>&1 || true',
    'rm -f /etc/nginx/conf.d/00-wzry-space.conf /etc/nginx/conf.d/01-wzry-space-ws.conf',
    'rm -f "/etc/nginx/conf.d/00-radar_${HOST_LABEL}.conf"',
    'rm -f "/etc/nginx/sites-enabled/radar_${HOST_LABEL}.conf" "/etc/nginx/sites-available/radar_${HOST_LABEL}.conf"',
    'rm -f "/www/server/panel/vhost/nginx/${HOST_LABEL}.conf"',
    'RECEIPT=/root/wzry-space-install.env',
    'SRC_DIR=/opt/wzry-space-src',
    'SITE_DIR=/www/wwwroot/wzry-space',
    'DB_NAME=wzry_space',
    'DB_USER=wzry_space',
    'if [ -f "$RECEIPT" ]; then',
    '  while IFS= read -r line; do',
    '    case "$line" in',
    '      SRC_DIR=*) SRC_DIR="${line#SRC_DIR=}" ;;',
    '      SITE_DIR=*) SITE_DIR="${line#SITE_DIR=}" ;;',
    '      DB_NAME=*) DB_NAME="${line#DB_NAME=}" ;;',
    '      DB_USER=*) DB_USER="${line#DB_USER=}" ;;',
    '    esac',
    '  done < "$RECEIPT"',
    'fi',
    'SAFE_SITE_BY_HOST="/www/wwwroot/${HOST_LABEL}"',
    'safe_rm_dir() {',
    '  local target="$1"',
    '  case "$target" in',
    '    /opt/wzry-space-src|/www/wwwroot/wzry-space|"$SAFE_SITE_BY_HOST"|/www/server/radar-java)',
    '      [ -n "$target" ] && rm -rf -- "$target"',
    '      echo "已清理目录: $target"',
    '      ;;',
    '    *)',
    '      echo "跳过非项目目录: $target"',
    '      ;;',
    '  esac',
    '}',
    'safe_rm_dir /www/server/radar-java',
    'safe_rm_dir "$SAFE_SITE_BY_HOST"',
    'safe_rm_dir "$SRC_DIR"',
    'safe_rm_dir "$SITE_DIR"',
    'rm -f "$RECEIPT"',
    'drop_mysql() {',
    '  command -v mysql >/dev/null 2>&1 || return 0',
    '  printf "%s" "$DB_NAME" | grep -Eq "^[A-Za-z0-9_]+$" || return 0',
    '  printf "%s" "$DB_USER" | grep -Eq "^[A-Za-z0-9_]+$" || return 0',
    "  local sql=\"DROP DATABASE IF EXISTS \\`${DB_NAME}\\`; DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'127.0.0.1'; FLUSH PRIVILEGES;\"",
    '  if [ -n "$DB_ROOT_PASSWORD" ]; then MYSQL_PWD="$DB_ROOT_PASSWORD" mysql -u root -e "$sql" || true',
    '  else mysql -u root -e "$sql" || true',
    '  fi',
    '}',
    'drop_mysql',
    'if command -v nginx >/dev/null 2>&1; then nginx -t >/dev/null 2>&1 && (systemctl reload nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1 || true) || true; fi',
    'echo "服务器项目数据清理完成"',
  ].join('\n');
  return `bash -lc ${shQuote(body)}`;
}

function safeHostLabel(host) {
  return String(host || '').replace(/[^a-zA-Z0-9.\-_]/g, '_') || 'server';
}

function createOpsStageTracker(emit) {
  const seen = new Set();
  const hints = [
    { id: 'upload', re: /GitHub|Gitee|源码|下载|clone|curl|项目/i, message: '正在准备项目文件' },
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
