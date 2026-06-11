'use strict';

/**
 * SSH 远程部署核心模块
 *
 *   步骤（每一步都会 emit.step 与 emit.log）：
 *    1. 连接 SSH
 *    2. 检测操作系统 + 包管理器
 *    3. 安装 Java (OpenJDK 8)
 *    4. 安装 Nginx
 *    5. 创建站点目录 /www/wwwroot/<host>/
 *    6. SFTP 上传源码
 *    7. 改写 .user.ini 中的 open_basedir
 *    8. 写入 Nginx 站点配置（监听 80）并 reload
 *    9. 写入 systemd 服务并启动 Java jar（监听 8888）
 *   10. 放行防火墙 80 / 8888
 *   11. 健康检查
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { Client } = require('ssh2');

const STEPS = [
  { id: 'connect', label: '连接 SSH' },
  { id: 'detect', label: '检测系统环境' },
  { id: 'install-java', label: '安装 Java 运行环境' },
  { id: 'install-nginx', label: '安装 Nginx' },
  { id: 'install-php', label: '配置 PHP（卡密版）' },
  { id: 'prepare-dir', label: '创建站点目录' },
  { id: 'upload', label: '上传源码' },
  { id: 'nginx-config', label: '配置 Nginx' },
  { id: 'java-service', label: '部署 Java 服务 (8888)' },
  { id: 'firewall', label: '放行防火墙端口' },
  { id: 'health', label: '健康检查' },
];

async function runDeployment({ creds, payloadDir, emit, shouldCancel }) {
  emit.log('info', '==============================');
  emit.log('info', '一键远程部署开始');
  emit.log('info', '==============================');
  for (const s of STEPS) emit.step(s.id, 'pending', s.label);

  const conn = new Client();
  const ctx = {
    conn,
    creds,
    payloadDir,
    emit,
    shouldCancel,
    sudo: '',          // 非 root 用户时填 "sudo -S -p '' "
    osFamily: null,    // 'debian' | 'rhel'
    pkgMgr: null,      // 'apt' | 'yum' | 'dnf'
    hostLabel: null,   // 用作站点目录名
    osRelease: '',
    osId: '',
    osVersionId: '',
    repoFixed: false,
  };

  try {
    // 0. 预清理：先干掉旧 Java 进程和 8888 端口占用，防止 systemd 自动重启抢端口
    await stepConnect(ctx);
    if (shouldCancel()) throw new Error('已取消');
    await stepPreCleanup(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 2. 检测系统
    await stepDetect(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 3. 装 Java
    await stepInstallJava(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 4. 装 Nginx
    await stepInstallNginx(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 4.5 卡密版需要 PHP-FPM 执行后台接口
    await stepInstallPhp(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 5. 建目录
    await stepPrepareDir(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 6. 上传
    await stepUpload(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 7. Nginx 配置
    await stepNginxConfig(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 8. Java 服务
    await stepJavaService(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 9. 防火墙
    await stepFirewall(ctx);
    if (shouldCancel()) throw new Error('已取消');

    // 10. 健康检查
    await stepHealth(ctx);
    if (shouldCancel()) throw new Error('已取消');

    emit.progress(100, '部署完成');
    emit.log('success', '==============================');
    emit.log('success', '部署完成！');
    emit.log('success', `静态页面: ${buildSiteUrl(creds.host, creds.sitePort)}`);
    emit.log('success', '------------------------------');
    emit.log('success', `当前雷达共享IP: ${creds.host}`);
    emit.log('success', '==============================');
  } finally {
    try { conn.end(); } catch (_) {}
  }
}

// ---------------------------------------------------------------------------
// 步骤实现
// ---------------------------------------------------------------------------

function stepConnect(ctx) {
  const { conn, creds, emit } = ctx;
  emit.step('connect', 'running', '连接 SSH');
  return new Promise((resolve, reject) => {
    let settled = false;
    const done = (err) => {
      if (settled) return;
      settled = true;
      if (err) {
        emit.step('connect', 'failed', err.message);
        reject(err);
      } else {
        emit.step('connect', 'success', '已连接');
        resolve();
      }
    };

    conn
      .on('ready', () => {
        emit.log('info', `SSH 连接成功 → ${creds.username}@${creds.host}:${creds.port}`);
        done();
      })
      .on('error', (err) => {
        emit.log('error', `SSH 连接失败: ${err.message}`);
        done(err);
      })
      .on('close', () => {
        emit.log('info', 'SSH 连接已关闭');
      });

    try {
      conn.connect({
        host: creds.host,
        port: creds.port,
        username: creds.username,
        password: creds.password,
        readyTimeout: 20000,
        keepaliveInterval: 15000,
        tryKeyboard: true,
      });
    } catch (err) {
      done(err);
    }

    // 键盘交互认证（某些服务器需要）
    conn.on('keyboard-interactive', (_name, _instr, _lang, _prompts, finish) => {
      finish([creds.password]);
    });
  });
}

async function stepPreCleanup(ctx) {
  const { emit } = ctx;
  emit.log('info', '===== 预清理：停止旧服务、释放 8888/9999 端口 =====');

  // 1. 先看当前 8888/9999 是谁在占
  const before8888 = await execSudo(ctx, 'ss -lntp | grep :8888 || echo "8888-空闲"', { allowFail: true });
  emit.log('info', `[诊断] 8888 端口当前状态: ${before8888.stdout.trim()}`);
  const before9999 = await execSudo(ctx, 'ss -lntp | grep :9999 || echo "9999-空闲"', { allowFail: true });
  emit.log('info', `[诊断] 9999 端口当前状态: ${before9999.stdout.trim()}`);

  // 2. 停服务 + mask 阻止自动重启 + 重置失败状态
  //    ★ mask 必须在这里做，否则 preCleanup 和 stepJavaService 之间的空档期
  //      RestartSec=5s 会重启 Java 抢回 8888 端口
  await execSudo(ctx, 'systemctl mask radar-java.service', { allowFail: true, silent: true });
  await execSudo(ctx, 'systemctl stop radar-java.service', { allowFail: true });
  await execSudo(ctx, 'systemctl reset-failed radar-java.service', { allowFail: true });

  // 3. 杀掉所有 Java 进程（pkill 替代 killall，CentOS 8 默认无 killall）
  await execSudo(ctx, 'pkill -9 -f wz.jar 2>/dev/null || true', { allowFail: true });
  emit.log('info', '已执行 pkill -9 -f wz.jar');

  // 4. 等 2 秒让端口释放
  await sleep(2000);

  // 5. 再查一次
  const after1 = await execSudo(ctx, 'ss -lntp | grep :8888 || echo "8888-空闲"', { allowFail: true });
  emit.log('info', `[诊断] pkill 后 8888 状态: ${after1.stdout.trim()}`);

  // 6. 如果还有残留，用 ss 提取 pid 再杀
  if (!/8888-空闲/.test(after1.stdout)) {
    const pids = await execSudo(ctx, 'ss -lntp | grep :8888 | grep -o "pid=[0-9]*" | cut -d= -f2 | sort -u', { allowFail: true });
    if (pids.stdout.trim()) {
      emit.log('warn', `残留进程 PID: ${pids.stdout.trim()}, 再次 kill -9`);
      for (const pid of pids.stdout.trim().split('\n')) {
        const p = pid.trim();
        if (p && /^\d+$/.test(p)) {
          await execSudo(ctx, `kill -9 ${p}`, { allowFail: true });
        }
      }
      await sleep(2000);
    }
  }

  // 7. 如果 9999 被 Nginx 占用，清理 Nginx 中监听 9999 的配置
  const after9999 = await execSudo(ctx, 'ss -lntp | grep :9999 || echo "9999-空闲"', { allowFail: true });
  if (!/9999-空闲/.test(after9999.stdout)) {
    emit.log('warn', `9999 被 Nginx 占用，清理 listen 9999 配置`);
    await execSudo(ctx, `grep -rl 'listen.*9999' /etc/nginx/ 2>/dev/null | while read f; do sed -i 's/listen.*9999/# &/' "$f"; done`, { allowFail: true });
    await execSudo(ctx, 'nginx -t 2>/dev/null && systemctl reload nginx || true', { allowFail: true });
    await sleep(2000);
  }

  // 8. 最终确认
  const after2_8888 = await execSudo(ctx, 'ss -lntp | grep :8888 || echo "8888-空闲"', { allowFail: true });
  const after2_9999 = await execSudo(ctx, 'ss -lntp | grep :9999 || echo "9999-空闲"', { allowFail: true });
  if (/8888-空闲/.test(after2_8888.stdout) && /9999-空闲/.test(after2_9999.stdout)) {
    emit.log('success', '预清理完成，8888/9999 端口已释放');
  } else {
    if (!/8888-空闲/.test(after2_8888.stdout)) emit.log('warn', `预清理后 8888 仍被占用: ${after2_8888.stdout.trim()}`);
    if (!/9999-空闲/.test(after2_9999.stdout)) emit.log('warn', `预清理后 9999 仍被占用: ${after2_9999.stdout.trim()}`);
  }
}

async function stepDetect(ctx) {
  const { emit, creds } = ctx;
  emit.step('detect', 'running', '检测系统与权限');

  // 是否 root
  const who = (await exec(ctx, 'id -u', { silent: true })).stdout.trim();
  const isRoot = who === '0';
  if (!isRoot) {
    ctx.sudo = `echo '${shEscape(creds.password)}' | sudo -S -p '' `;
    emit.log('warn', `当前用户非 root (uid=${who}), 将使用 sudo 提权（密码已注入）`);
  } else {
    emit.log('info', '当前用户为 root');
  }

  // os-release
  const osRel = (await exec(ctx, 'cat /etc/os-release', { silent: true })).stdout;
  ctx.osRelease = osRel;
  ctx.osId = readOsReleaseValue(osRel, 'ID');
  ctx.osVersionId = readOsReleaseValue(osRel, 'VERSION_ID');
  emit.log('info', osRel.split('\n').slice(0, 3).join(' | '));

  if (/ID=.*(debian|ubuntu)/i.test(osRel) || /ID_LIKE=.*debian/i.test(osRel)) {
    ctx.osFamily = 'debian';
    ctx.pkgMgr = 'apt';
  } else if (/ID=.*(centos|rhel|rocky|almalinux|fedora)/i.test(osRel) || /ID_LIKE=.*rhel|fedora/i.test(osRel)) {
    ctx.osFamily = 'rhel';
    // 优先 dnf
    const hasDnf = (await exec(ctx, 'command -v dnf', { silent: true, allowFail: true })).code === 0;
    ctx.pkgMgr = hasDnf ? 'dnf' : 'yum';
  } else {
    throw new Error('不支持的操作系统发行版，请使用 Debian/Ubuntu 或 CentOS/RHEL 系列');
  }
  emit.log('info', `识别为 ${ctx.osFamily} 系列，包管理器: ${ctx.pkgMgr}`);
  if (ctx.osId || ctx.osVersionId) {
    emit.log('info', `发行版: ${ctx.osId || 'unknown'} ${ctx.osVersionId || ''}`.trim());
  }

  // 站点目录：优先用 host 字符串（和原项目 .user.ini 风格一致）
  ctx.hostLabel = creds.host.replace(/[^a-zA-Z0-9\.\-\_]/g, '_');

  emit.step('detect', 'success', `${ctx.osFamily} / ${ctx.pkgMgr}`);
}

async function stepInstallJava(ctx) {
  const { emit } = ctx;
  emit.step('install-java', 'running', '检查 Java');

  const JDK_DIR = '/www/server/java/jdk1.8.0_371';
  const JAVA_BIN = `${JDK_DIR}/bin/java`;

  // 1. 检查指定路径的 JDK 是否存在
  const check = await exec(ctx, `test -x ${JAVA_BIN} && ${JAVA_BIN} -version`, { silent: true, allowFail: true });
  if (check.code === 0) {
    emit.log('info', `JDK 已存在: ${JDK_DIR}`);
    emit.log('info', `Java 版本: ${(check.stderr || check.stdout).split('\n')[0]}`);
    emit.step('install-java', 'success', '已存在');
    return;
  }

  // 2. 不存在 → 优先用包管理器安装 JDK 8，再软链接；下载作为最后备选
  emit.log('info', `未检测到 ${JDK_DIR}，尝试配置 Java 运行环境...`);

  // 2a. 优先：通过包管理器安装 JDK 8（最快最稳，国内源秒级完成）
  emit.log('info', '通过包管理器安装 JDK 8...');
  if (ctx.pkgMgr === 'apt') {
    await execSudo(ctx, 'DEBIAN_FRONTEND=noninteractive apt-get update -qq', { allowFail: true, silent: true });
    await execSudo(ctx, 'DEBIAN_FRONTEND=noninteractive apt-get install -y openjdk-8-jdk-headless', { allowFail: true });
  } else {
    // CentOS/RHEL: 先确保仓库可用
    await ensureRhelPackageRepos(ctx, 'java-1.8.0-openjdk-devel');
    await execSudo(ctx, `${ctx.pkgMgr} install -y java-1.8.0-openjdk-devel`, { allowFail: true });
  }

  // 安装后尝试软链接
  const sysJavaPath = await exec(ctx, 'readlink -f $(which java) 2>/dev/null', { silent: true, allowFail: true });
  if (sysJavaPath.code === 0 && sysJavaPath.stdout.trim()) {
    const javaReal = sysJavaPath.stdout.trim();
    let srcDir = javaReal.replace(/\/jre\/bin\/java$/, '').replace(/\/bin\/java$/, '');
    emit.log('info', `系统 Java 路径: ${javaReal}，JDK 根目录: ${srcDir}`);
    const srcBin = `${srcDir}/bin/java`;
    const srcBinAlt = `${srcDir}/jre/bin/java`;
    const verCheck = await exec(ctx, `${srcBin} -version 2>&1 || ${srcBinAlt} -version 2>&1`, { silent: true, allowFail: true });
    if (verCheck.code === 0 && /1\.8\.0/.test(verCheck.stdout || verCheck.stderr || '')) {
      emit.log('info', `系统 JDK 8 可用: ${srcDir}，创建软链接到 ${JDK_DIR}`);
      await execSudo(ctx, `rm -rf ${JDK_DIR} && mkdir -p /www/server/java && ln -sf "${srcDir}" ${JDK_DIR}`);
      const verify = await exec(ctx, `${JAVA_BIN} -version`, { silent: true, allowFail: true });
      if (verify.code === 0) {
        emit.log('success', `JDK 已就绪 (软链接): ${(verify.stderr || verify.stdout).split('\\n')[0]}`);
        emit.step('install-java', 'success', '已链接系统JDK');
        return;
      }
      // bin/java 不存在，尝试 jre/bin/java
      const verifyAlt = await exec(ctx, `${srcDir}/jre/bin/java -version`, { silent: true, allowFail: true });
      if (verifyAlt.code === 0) {
        emit.log('info', `${JDK_DIR}/bin/java 不可用，改用 jre 目录`);
        await execSudo(ctx, `rm -rf ${JDK_DIR} && ln -sf "${srcDir}/jre" ${JDK_DIR}`);
        const verify2 = await exec(ctx, `${JAVA_BIN} -version`, { silent: true, allowFail: true });
        if (verify2.code === 0) {
          emit.log('success', `JDK 已就绪 (JRE 软链接): ${(verify2.stderr || verify2.stdout).split('\\n')[0]}`);
          emit.step('install-java', 'success', '已链接系统JDK');
          return;
        }
      }
    }
  }

  // 2b. 包管理器安装失败 → 下载 JDK 8
  emit.log('info', '系统无可用 JDK 8，开始下载...');

  // 创建目录
  await execSudo(ctx, `mkdir -p /www/server/java`);

  // 检查下载工具
  const hasWget = (await exec(ctx, 'command -v wget', { silent: true, allowFail: true })).code === 0;
  const hasCurl = (await exec(ctx, 'command -v curl', { silent: true, allowFail: true })).code === 0;
  if (!hasWget && !hasCurl) {
    emit.log('info', '安装 wget 下载工具...');
    if (ctx.pkgMgr === 'apt') {
      await execSudo(ctx, 'DEBIAN_FRONTEND=noninteractive apt-get install -y wget');
    } else {
      await ensureRhelPackageRepos(ctx, 'wget');
      await execSudo(ctx, `${ctx.pkgMgr} install -y wget`);
    }
  }

  // Adoptium API（最可靠，自动重定向到可用的 JDK 8）
  const mirrors = [
    'https://api.adoptium.net/v3/binary/latest/8/ga/linux/x64/jdk/hotspot/normal/eclipse',
  ];

  let downloadOk = false;
  for (const url of mirrors) {
    emit.log('info', `下载 JDK 8: ${url}`);
    await execSudo(ctx, 'rm -f /tmp/jdk8.tar.gz', { allowFail: true, silent: true });

    if (hasWget) {
      const r = await execSudo(ctx, `cd /tmp && wget --timeout=30 --tries=2 --no-check-certificate -O jdk8.tar.gz "${url}"`, { allowFail: true, silent: true });
      if (r.code === 0) {
        const validate = await exec(ctx, 'file /tmp/jdk8.tar.gz | grep -q "gzip compressed"', { silent: true, allowFail: true });
        if (validate.code === 0) { downloadOk = true; break; }
        emit.log('warn', '下载的文件不是有效的 gzip，跳过');
      } else {
        emit.log('warn', `wget 失败: ${(r.stderr || r.stdout).split('\\n').slice(-2).join(' ')}`);
      }
    }
    if (!downloadOk && hasCurl) {
      const r = await execSudo(ctx, `cd /tmp && curl -kL --fail --connect-timeout 30 --max-time 300 --retry 1 -o jdk8.tar.gz "${url}"`, { allowFail: true, silent: true });
      if (r.code === 0) {
        const validate = await exec(ctx, 'file /tmp/jdk8.tar.gz | grep -q "gzip compressed"', { silent: true, allowFail: true });
        if (validate.code === 0) { downloadOk = true; break; }
        emit.log('warn', '下载的文件不是有效的 gzip，跳过');
      } else {
        emit.log('warn', `curl 失败: ${(r.stderr || r.stdout).split('\\n').slice(-2).join(' ')}`);
      }
    }
  }

  if (!downloadOk) {
    throw new Error('JDK 下载失败，请手动安装 JDK 8 到 /www/server/java/jdk1.8.0_371');
  }

  emit.log('info', 'JDK 下载完成，正在解压...');

  // 解压
  await execSudo(ctx, `tar -xzf /tmp/jdk8.tar.gz -C /www/server/java/`);

  // 查找解压后的目录名 (jdk1.8.0_371)
  const findJdk = await exec(ctx, `ls -d /www/server/java/jdk1.8.0_* 2>/dev/null | head -1`, { silent: true, allowFail: true });
  if (!findJdk.stdout.trim()) {
    throw new Error('JDK 解压后未找到目录');
  }

  const extractedDir = findJdk.stdout.trim();

  // 重命名为标准路径 jdk1.8.0_371
  if (extractedDir !== JDK_DIR) {
    await execSudo(ctx, `rm -rf ${JDK_DIR} 2>/dev/null; mv "${extractedDir}" ${JDK_DIR}`);
  }

  // 清理临时文件
  await execSudo(ctx, 'rm -f /tmp/jdk8.tar.gz', { allowFail: true });

  // 验证
  const verify = await exec(ctx, `${JAVA_BIN} -version`, { silent: true, allowFail: true });
  if (verify.code !== 0) throw new Error('JDK 安装验证失败');
  emit.log('success', `JDK 1.8.0_371 安装成功: ${(verify.stderr || verify.stdout).split('\n')[0]}`);
  emit.step('install-java', 'success', '安装完成');
}

async function stepInstallNginx(ctx) {
  const { emit } = ctx;
  emit.step('install-nginx', 'running', '检查 Nginx');

  const check = await exec(ctx, 'command -v nginx', { silent: true, allowFail: true });
  if (check.code === 0) {
    emit.log('info', `Nginx 已安装: ${check.stdout.trim()}`);
  } else {
    emit.log('info', '未检测到 Nginx，开始安装 ...');
    if (ctx.pkgMgr === 'apt') {
      await execSudo(ctx, 'DEBIAN_FRONTEND=noninteractive apt-get install -y nginx');
    } else {
      await ensureRhelPackageRepos(ctx, 'Nginx');
      // CentOS/RHEL
      await execSudo(ctx, `${ctx.pkgMgr} install -y epel-release || true`);
      await execSudo(ctx, `${ctx.pkgMgr} install -y nginx`);
    }
  }
  await execSudo(ctx, 'systemctl enable nginx && systemctl start nginx', { allowFail: true });
  emit.step('install-nginx', 'success', 'Nginx 就绪');
}

async function stepInstallPhp(ctx) {
  const { emit, creds } = ctx;
  emit.step('install-php', 'running', '检查 PHP-FPM');

  if (creds.deployMode !== 'card') {
    ctx.phpFastcgiPass = '';
    ctx.phpUser = '';
    emit.log('info', '纯净版无需 PHP-FPM，跳过 PHP 配置');
    emit.step('install-php', 'success', '纯净版无需 PHP');
    return;
  }

  const check = await exec(ctx, 'command -v php-fpm || command -v php-fpm8.3 || command -v php-fpm8.2 || command -v php-fpm8.1 || command -v php-fpm8.0 || command -v php-fpm7.4', { silent: true, allowFail: true });
  if (check.code === 0) {
    emit.log('info', `PHP-FPM 已安装: ${check.stdout.trim()}`);
  } else {
    emit.log('info', '卡密版需要 PHP-FPM，开始安装 php-fpm php-cli...');
    if (ctx.pkgMgr === 'apt') {
      await execSudo(ctx, 'DEBIAN_FRONTEND=noninteractive apt-get update -qq', { allowFail: true, silent: true });
      await execSudo(ctx, 'DEBIAN_FRONTEND=noninteractive apt-get install -y php-fpm php-cli');
    } else {
      await ensureRhelPackageRepos(ctx, 'php-fpm');
      await execSudo(ctx, `${ctx.pkgMgr} install -y php-fpm php-cli`);
    }
  }

  await execSudo(ctx, 'systemctl enable php-fpm 2>/dev/null || systemctl enable php*-fpm 2>/dev/null || true', { allowFail: true });
  await execSudo(ctx, 'systemctl restart php-fpm 2>/dev/null || systemctl restart php*-fpm 2>/dev/null || true', { allowFail: true });
  await sleep(1500);

  ctx.phpFastcgiPass = await detectPhpFastcgiPass(ctx);
  ctx.phpUser = await detectPhpUser(ctx);

  emit.log('success', `PHP-FPM 就绪: ${ctx.phpFastcgiPass}`);
  if (ctx.phpUser) emit.log('info', `PHP-FPM 运行用户: ${ctx.phpUser}`);
  emit.step('install-php', 'success', ctx.phpFastcgiPass);
}

async function stepPrepareDir(ctx) {
  const { emit, hostLabel } = ctx;
  emit.step('prepare-dir', 'running', '创建站点目录');

  const base = `/www/wwwroot/${hostLabel}`;
  ctx.siteRoot = base;

  const quotedBase = shQuote(base);
  await execSudo(
    ctx,
    `mkdir -p ${quotedBase} && (command -v chattr >/dev/null 2>&1 && chattr -R -i ${quotedBase} 2>/dev/null || true) && find ${quotedBase} -mindepth 1 -maxdepth 1 -exec rm -rf {} + && mkdir -p /www/server/radar-java`
  );
  // 让 nginx 可以读取（大多数发行版 nginx 以 www-data / nginx 用户运行）
  await execSudo(ctx, `chmod -R 755 /www/wwwroot && chmod -R 755 /www/server`, { allowFail: true });

  emit.log('info', `站点目录: ${base}`);
  emit.step('prepare-dir', 'success', base);
}

async function stepUpload(ctx) {
  const { emit, conn, payloadDir, siteRoot, creds } = ctx;
  emit.step('upload', 'running', '通过 SFTP 上传源码');

  // 如果非 root，需要先把目录的所有者改为当前用户，SFTP 才能写
  if (ctx.sudo) {
    await execSudo(ctx, `chown -R ${ctx.creds.username} ${siteRoot} /www/server/radar-java`, { allowFail: true });
  }

  const sftp = await new Promise((resolve, reject) => {
    conn.sftp((err, s) => (err ? reject(err) : resolve(s)));
  });

  try {
    const files = walk(payloadDir);
    const total = files.length;
    let done = 0;
    emit.log('info', `准备上传 ${total} 个文件 ...`);
    emit.log('info', creds.deployMode === 'card'
      ? '部署版本: 卡密版，上传完整 PHP 卡密后台与登录页面'
      : '部署版本: 纯净版，上传纯净版页面');

    for (const rel of files) {
      if (ctx.shouldCancel()) throw new Error('已取消');
      const localPath = path.join(payloadDir, rel);
      const remoteRel = rel.split(path.sep).join('/');

      // jar 放到独立目录，其他放到站点根
      let remoteAbs;
      if (remoteRel === 'home-server-0.0.1-SNAPSHOT.jar' || remoteRel === 'wz.jar') {
        remoteAbs = '/www/server/radar-java/wz.jar';
      } else {
        remoteAbs = `${siteRoot}/${remoteRel}`;
      }

      await sftpMkdirp(sftp, posixDirname(remoteAbs));
      if (remoteRel === 'index.html') {
        const html = fs.readFileSync(localPath, 'utf8');
        await sftpWriteText(sftp, remoteAbs, injectLicenseScriptTag(html));
      } else if (remoteRel === 'auth_config.php' && creds.deployMode === 'card') {
        const config = fs.readFileSync(localPath, 'utf8');
        await sftpWriteText(sftp, remoteAbs, injectCardAdminPassword(config, creds.cardAdminPassword));
      } else {
        await sftpPut(sftp, localPath, remoteAbs);
      }
      done += 1;
      const percent = Math.round((done / total) * 100);
      emit.progress(percent, `${done}/${total} ${remoteRel}`);
      if (done % 5 === 0 || done === total) {
        emit.log('info', `  [${done}/${total}] ${remoteRel}`);
      }
    }
  } finally {
    try { sftp.end(); } catch (_) {}
  }

  // 改回所有者 + 改写 .user.ini
  if (ctx.sudo) {
    await execSudo(ctx, `chown -R root:root ${siteRoot} /www/server/radar-java`, { allowFail: true });
  }
  const userIni = `${siteRoot}/.user.ini`;
  const newLine = `open_basedir=${siteRoot}/:/tmp/`;
  await execSudo(ctx, `echo '${shEscape(newLine)}' > '${userIni}'`, { allowFail: true });
  emit.log('info', `已更新 ${userIni}`);

  if (creds.deployMode === 'card') {
    await configureCardRuntimeFiles(ctx);
  }
  await installLicenseRuntime(ctx);

  emit.step('upload', 'success', `${siteRoot}`);
}

async function stepNginxConfig(ctx) {
  const { emit, siteRoot, hostLabel, creds } = ctx;
  const sitePort = creds.sitePort || 80;
  emit.step('nginx-config', 'running', `写入 Nginx 站点配置 (${sitePort})`);

  const btNginx = await execSudo(
    ctx,
    'test -d /www/server/panel/vhost/nginx -a -x /www/server/nginx/sbin/nginx && echo bt || true',
    { allowFail: true, silent: true }
  );
  const isBtNginx = /\bbt\b/.test(btNginx.stdout || '');

  if (isBtNginx) {
    emit.log('info', '检测到宝塔 Nginx，使用 /www/server/panel/vhost/nginx');
  } else if (ctx.osFamily === 'rhel') {
    await execSudo(ctx, `find /etc/nginx/conf.d -maxdepth 1 -type f -name '00-radar_*.conf' ! -name '00-radar_${hostLabel}.conf' -exec mv -f {} {}.disabled-by-radar \\;`, { allowFail: true, silent: true });
  }

  const confPath = isBtNginx
    ? `/www/server/panel/vhost/nginx/${hostLabel}.conf`
    : (ctx.osFamily === 'debian'
      ? `/etc/nginx/sites-available/radar_${hostLabel}.conf`
      : `/etc/nginx/conf.d/00-radar_${hostLabel}.conf`);

  const conf = buildNginxConf(siteRoot, ctx.osFamily === 'rhel', sitePort, creds.deployMode === 'card', ctx.phpFastcgiPass);

  // 写入配置
  await execSudo(ctx, `mkdir -p '${path.dirname(confPath)}'`);
  const heredoc = `cat > '${confPath}' <<'RADAR_NGINX_EOF'\n${conf}\nRADAR_NGINX_EOF\n`;
  await execSudo(ctx, heredoc);

  if (isBtNginx) {
    await execSudo(ctx, `rm -f /www/server/panel/vhost/nginx/0.default.conf`, { allowFail: true });
  } else if (ctx.osFamily === 'debian') {
    await execSudo(ctx, 'mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled');
    await execSudo(ctx, `ln -sf '${confPath}' /etc/nginx/sites-enabled/radar_${hostLabel}.conf`);
    await execSudo(ctx, `rm -f /etc/nginx/sites-enabled/default`, { allowFail: true });
  } else {
    await execSudo(ctx, `cp -af /etc/nginx/nginx.conf /etc/nginx/nginx.conf.radar-bak 2>/dev/null || true`, { allowFail: true, silent: true });
    await execSudo(ctx, `cat > /etc/nginx/nginx.conf <<'RADAR_MAIN_NGINX_EOF'\n${buildRhelMainNginxConf()}\nRADAR_MAIN_NGINX_EOF\n`);
    await execSudo(ctx, `rm -f /etc/nginx/default.d/*.conf`, { allowFail: true });
    await execSudo(ctx, `mv -f /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf.disabled-by-radar`, { allowFail: true });
    await execSudo(ctx, `mv -f /etc/nginx/conf.d/welcome.conf /etc/nginx/conf.d/welcome.conf.disabled-by-radar`, { allowFail: true });
  }

  const nginxBin = isBtNginx ? '/www/server/nginx/sbin/nginx' : 'nginx';
  const test = await execSudo(ctx, `${nginxBin} -t`, { allowFail: true });
  if (test.code !== 0) {
    emit.log('error', test.stderr || test.stdout);
    throw new Error('Nginx 配置校验失败');
  }
  await execSudo(ctx, isBtNginx
    ? `${nginxBin} -s reload || /etc/init.d/nginx reload || systemctl reload nginx || systemctl restart nginx`
    : 'systemctl reload nginx || systemctl restart nginx');

  // ★ 确保没有 Nginx 配置监听 9999（Java Netty 需要 9999）
  const nginx9999 = await execSudo(ctx, 'ss -lntp | grep :9999 || echo "9999-free"', { allowFail: true, silent: true });
  if (!/9999-free/.test(nginx9999.stdout)) {
    emit.log('warn', 'Nginx 仍在监听 9999，清理所有含 listen 9999 的配置');
    await execSudo(ctx, `grep -rl 'listen.*9999' /etc/nginx/ /www/server/panel/vhost/nginx/ /www/server/nginx/conf/ 2>/dev/null | while read f; do sed -i 's/listen.*9999/# &/' "$f"; done`, { allowFail: true });
    await execSudo(ctx, isBtNginx
      ? `${nginxBin} -t 2>/dev/null && (${nginxBin} -s reload || /etc/init.d/nginx reload) || true`
      : 'nginx -t 2>/dev/null && systemctl reload nginx || true', { allowFail: true });
  }

  emit.log('success', `Nginx 配置已生效: ${confPath} (listen ${sitePort})`);
  emit.step('nginx-config', 'success', confPath);
}

async function stepJavaService(ctx) {
  const { emit, creds } = ctx;
  emit.step('java-service', 'running', '注册 systemd 服务');

  const unitPath = '/etc/systemd/system/radar-java.service';

  // ★ 关键：停掉旧服务并打断 RestartSec=5s 的自动重启循环
  //    mask 会把 unit 文件变成指向 /dev/null 的软链接，阻止 systemd restart
  //    但写入新 unit 前必须先 unmask，否则 cat > 写到 /dev/null！
  emit.log('info', '停止旧服务并清理残留进程...');
  await execSudo(ctx, 'systemctl mask radar-java.service', { allowFail: true, silent: true });
  await execSudo(ctx, 'systemctl stop radar-java.service', { allowFail: true });
  await execSudo(ctx, 'systemctl reset-failed radar-java.service', { allowFail: true, silent: true });
  await execSudo(ctx, 'pkill -9 -f wz.jar 2>/dev/null || true', { allowFail: true });

  // 等待端口释放（超过 RestartSec=5s）
  await sleep(6000);

  // 确认 8888 端口已释放
  const portCheck = await execSudo(ctx, 'ss -lntp | grep :8888 || echo "8888-free"', { allowFail: true, silent: true });
  if (!/8888-free/.test(portCheck.stdout)) {
    emit.log('warn', `8888 端口仍被占用: ${portCheck.stdout.trim()}, 强制清理`);
    await execSudo(ctx, 'ss -lntp | grep :8888 | grep -o "pid=[0-9]*" | cut -d= -f2 | sort -u | xargs -r kill -9 2>/dev/null || true', { allowFail: true });
    await sleep(3000);
  }

  // ★ 先 unmask 再写文件（mask 会把 unit 文件变成 /dev/null 软链接）
  await execSudo(ctx, 'systemctl unmask radar-java.service', { allowFail: true, silent: true });

  // 写入新 unit
  const unit = buildSystemdUnit();
  await execSudo(ctx, `cat > '${unitPath}' <<'RADAR_UNIT_EOF'\n${unit}\nRADAR_UNIT_EOF\n`);
  await execSudo(ctx, 'systemctl daemon-reload');
  await execSudo(ctx, 'systemctl enable radar-java.service');

  // ★ 启动前全面诊断
  // 检查 JAR 是否存在
  const jarCheck2 = await exec(ctx, 'ls -la /www/server/radar-java/wz.jar 2>&1', { allowFail: true, silent: true });
  emit.log('info', `[诊断] JAR 文件: ${jarCheck2.stdout.trim()}`);

  // 检查所有监听端口
  const allPorts = await execSudo(ctx, 'ss -lntp', { allowFail: true, silent: true });
  emit.log('info', `[诊断] 所有监听端口:\n${allPorts.stdout.trim()}`);

  // 确认 8888 空闲
  const finalCheck8888 = await execSudo(ctx, 'ss -lntp | grep :8888 || echo "8888-free"', { allowFail: true, silent: true });
  if (!/8888-free/.test(finalCheck8888.stdout)) {
    emit.log('warn', `启动前 8888 仍被占用: ${finalCheck8888.stdout.trim()}, 强制清理`);
    await execSudo(ctx, 'fuser -k 8888/tcp 2>/dev/null || true', { allowFail: true });
    await execSudo(ctx, 'ss -lntp | grep :8888 | grep -o "pid=[0-9]*" | cut -d= -f2 | sort -u | xargs -r kill -9 2>/dev/null || true', { allowFail: true });
    await sleep(3000);
  }

  // ★ 确认 9999 空闲（Java Netty 需要 9999）
  const finalCheck9999 = await execSudo(ctx, 'ss -lntp | grep :9999 || echo "9999-free"', { allowFail: true, silent: true });
  if (!/9999-free/.test(finalCheck9999.stdout)) {
    emit.log('warn', `启动前 9999 仍被占用: ${finalCheck9999.stdout.trim()}, 清理 Nginx 监听`);
    // 如果是 Nginx 占的，注释掉 listen 9999 并 reload
    await execSudo(ctx, `grep -rl 'listen.*9999' /etc/nginx/ 2>/dev/null | while read f; do sed -i 's/listen.*9999/# &/' "$f"; done`, { allowFail: true });
    await execSudo(ctx, 'nginx -t 2>/dev/null && systemctl reload nginx || true', { allowFail: true });
    await sleep(2000);
    // 非 Nginx 占用则强杀
    const recheck9999 = await execSudo(ctx, 'ss -lntp | grep :9999 || echo "9999-free"', { allowFail: true, silent: true });
    if (!/9999-free/.test(recheck9999.stdout)) {
      await execSudo(ctx, 'fuser -k 9999/tcp 2>/dev/null || true', { allowFail: true });
      await sleep(2000);
    }
  }
  emit.log('info', '已写入 unit，正在启动 ...');
  await execSudo(ctx, 'systemctl start radar-java.service');

  // 给足够时间：ExecStartPre 清理 + Java 启动
  await sleep(12000);
  const status = await execSudo(ctx, 'systemctl is-active radar-java.service', { allowFail: true, silent: true });
  if (status.stdout.trim() !== 'active') {
    // 详细诊断：日志 + 端口状态 + JAR 状态
    const log = await execSudo(ctx, 'journalctl -u radar-java.service -n 80 --no-pager', { allowFail: true, silent: true });
    emit.log('error', log.stdout || log.stderr);
    const sitePort = creds.sitePort || 80;
    const portNow = await execSudo(ctx, `ss -lntp | grep -E ":(8888|8080|${sitePort}|9999)" || echo "no-match"`, { allowFail: true, silent: true });
    emit.log('error', `[诊断] 当前端口: ${portNow.stdout.trim()}`);
    const jarNow = await exec(ctx, 'ls -la /www/server/radar-java/wz.jar 2>&1', { allowFail: true, silent: true });
    emit.log('error', `[诊断] JAR: ${jarNow.stdout.trim()}`);
    throw new Error('Java 服务未能启动 (radar-java.service)');
  }
  emit.log('success', 'radar-java.service active');
  emit.step('java-service', 'success', '已启动');
}

async function stepFirewall(ctx) {
  const { emit, creds } = ctx;
  const sitePort = creds.sitePort || 80;
  const ports = Array.from(new Set([sitePort, 8888, 9999]));
  const portLabel = ports.join(' / ');
  emit.step('firewall', 'running', `放行端口 ${portLabel}`);

  emit.log('info', `开始开放端口: ${ports.join(', ')}`);

  // firewalld
  const firewalld = await execSudo(ctx, 'systemctl is-active firewalld', { silent: true, allowFail: true });
  if (firewalld.stdout.trim() === 'active') {
    for (const p of ports) {
      await execSudo(ctx, `firewall-cmd --permanent --add-port=${p}/tcp`, { allowFail: true });
    }
    await execSudo(ctx, 'firewall-cmd --reload', { allowFail: true });
    emit.log('success', `firewalld 已放行 ${portLabel}`);
  }

  // ufw
  const ufw = await execSudo(ctx, 'command -v ufw', { silent: true, allowFail: true });
  if (ufw.code === 0) {
    const ufwStatus = await execSudo(ctx, 'ufw status', { silent: true, allowFail: true });
    if (/Status: active/i.test(ufwStatus.stdout)) {
      for (const p of ports) {
        await execSudo(ctx, `ufw allow ${p}/tcp`, { allowFail: true });
      }
      emit.log('success', `ufw 已放行 ${portLabel}`);
    }
  }

  // iptables 兜底：只追加 INPUT ACCEPT（不保存，避免污染）
  for (const p of ports) {
    await execSudo(ctx, `iptables -C INPUT -p tcp --dport ${p} -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport ${p} -j ACCEPT`, { allowFail: true });
  }
  emit.log('success', `iptables 已处理 ${portLabel}`);

  emit.log('warn', `提示：云服务商的安全组（阿里云/腾讯云/华为云等）需要在控制台另行放行 ${portLabel}`);
  emit.step('firewall', 'success', '已处理');
}

async function stepHealth(ctx) {
  const { emit, creds } = ctx;
  const sitePort = creds.sitePort || 80;
  emit.step('health', 'running', '检查服务状态');

  const checks = [
    { name: `Nginx :${sitePort}`, cmd: `curl --connect-timeout 3 --max-time 8 -s -o /dev/null -w '%{http_code}' http://127.0.0.1:${sitePort}/ || echo 000` },
    { name: 'Java :8888', cmd: "ss -lnt 2>/dev/null | awk '{print $4}' | grep -Eq '(:|])8888$' && echo 200 || echo 000" },
  ];
  if (creds.deployMode === 'card') {
    checks.push({ name: 'Card login API', cmd: `curl --connect-timeout 3 --max-time 8 -s -o /dev/null -w '%{http_code}' http://127.0.0.1:${sitePort}/api/auth.php?action=me || echo 000` });
  }

  for (const c of checks) {
    const r = await exec(ctx, c.cmd, { silent: true, allowFail: true });
    const code = r.stdout.trim();
    if (/^(2|3|4)\d\d$/.test(code)) {
      emit.log('success', `${c.name} → HTTP ${code}`);
    } else {
      emit.log('warn', `${c.name} 未响应（code=${code || 'n/a'}），如首次启动请稍等`);
    }
  }

  emit.step('health', 'success', '已输出');
}

async function detectPhpFastcgiPass(ctx) {
  const socket = await execSudo(
    ctx,
    "find /run /var/run -type s \\( -name 'php*-fpm.sock' -o -name 'www.sock' \\) 2>/dev/null | sort | head -1",
    { silent: true, allowFail: true }
  );
  const sock = socket.stdout.trim().split('\n').filter(Boolean)[0];
  if (sock) return `unix:${sock}`;

  const port = await execSudo(ctx, "ss -lntp | grep ':9000' || true", { silent: true, allowFail: true });
  if (port.stdout.trim()) return '127.0.0.1:9000';

  return '127.0.0.1:9000';
}

async function detectPhpUser(ctx) {
  const running = await execSudo(
    ctx,
    "ps -eo user,comm | awk '$2 ~ /php-fpm/ && $1 != \"root\" {print $1; exit}'",
    { silent: true, allowFail: true }
  );
  const user = running.stdout.trim();
  if (user) return user;

  for (const candidate of ['www-data', 'nginx', 'apache']) {
    const exists = await execSudo(ctx, `id -u ${candidate} >/dev/null 2>&1`, { silent: true, allowFail: true });
    if (exists.code === 0) return candidate;
  }
  return '';
}

async function configureCardRuntimeFiles(ctx) {
  const { emit, siteRoot } = ctx;
  const dataDir = `${siteRoot}/data`;
  const phpUser = ctx.phpUser || await detectPhpUser(ctx);

  await execSudo(ctx, `chmod 755 '${siteRoot}' '${siteRoot}/api' '${siteRoot}/admin' '${dataDir}'`, { allowFail: true });
  await execSudo(ctx, `chmod 644 '${siteRoot}/auth_config.php' '${siteRoot}/api/'*.php '${siteRoot}/admin/'*.php`, { allowFail: true });
  await execSudo(ctx, `chmod 660 '${dataDir}/'*.db.php 2>/dev/null || true`, { allowFail: true });

  if (phpUser) {
    await execSudo(ctx, `chown -R ${phpUser} '${dataDir}'`, { allowFail: true });
    emit.log('info', `卡密数据目录已授权给 PHP 用户: ${phpUser}`);
  } else {
    await execSudo(ctx, `chmod -R 777 '${dataDir}'`, { allowFail: true });
    emit.log('warn', '未识别 PHP-FPM 用户，已临时放宽 data 目录权限');
  }
}

async function installLicenseRuntime(ctx) {
  const { emit, siteRoot, creds } = ctx;
  const guardPath = `${siteRoot}/radar-license.js`;
  const js = buildLicenseGuardJs(buildLicenseRuntimeConfig(creds));
  await execSudo(ctx, `cat > ${shQuote(guardPath)} <<'RADAR_LICENSE_JS'\n${js}\nRADAR_LICENSE_JS\nchmod 644 ${shQuote(guardPath)}`);
  emit.log('info', `已写入服务器授权校验脚本: ${guardPath}`);
}

function buildLicenseRuntimeConfig(creds) {
  const input = creds.licenseConfig || {};
  return {
    serverUrl: String(input.serverUrl || 'http://ld.llqq520.xyz').replace(/\/+$/, ''),
    host: String(input.host || creds.host || '').trim(),
    mode: String(input.mode || creds.deployMode || 'clean').trim(),
    permanent: !!input.permanent,
    groupUrl: String(input.groupUrl || 'https://qm.qq.com/q/VcaTE1qumQ').trim(),
    groupName: String(input.groupName || '王者雷达共享开黑组队群').trim(),
  };
}

function injectLicenseScriptTag(html) {
  const text = String(html || '');
  if (/radar-license\.js/i.test(text)) return text;
  const tag = '<script src="/radar-license.js?v=20260611"></script>';
  if (/<\/body>/i.test(text)) return text.replace(/<\/body>/i, `${tag}\n</body>`);
  return `${text}\n${tag}\n`;
}

function buildLicenseGuardJs(config) {
  if (config.permanent) {
    return `(function(){'use strict';
window.RadarServerLicense={check:function(){return Promise.resolve(true);},isAuthorized:function(){return true;},last:function(){return {permanent:true,local:true};},showBlock:function(){}};
try{localStorage.setItem('wzry.server.license.permanent.'+(${JSON.stringify(config.host || '')}||location.hostname||'server'),'1');}catch(e){}
})();`;
  }
  const cfg = JSON.stringify(config).replace(/</g, '\\u003c');
  return `(function(){'use strict';
var cfg=${cfg};
var nativeInitApp=null,nativeInitWebSocket=null,authorized=!!cfg.permanent,trialOpen=false,checking=null,lastResult=null;
var baseKey='wzry.server.license.'+(cfg.host||location.hostname||'server');
var storageKey=baseKey+'.permanent';
var trialKey=baseKey+'.trialStart';
var trialMs=24*60*60*1000;
function readPermanent(){try{return localStorage.getItem(storageKey)==='1';}catch(e){return false;}}
function savePermanent(){try{localStorage.setItem(storageKey,'1');}catch(e){}}
function trialStart(){var now=Date.now();try{var old=Number(localStorage.getItem(trialKey)||0);if(!old){localStorage.setItem(trialKey,String(now));return now;}return old;}catch(e){return now;}}
function trialLeft(){return Math.max(0,trialMs-(Date.now()-trialStart()));}
if(readPermanent()) authorized=true;
function esc(s){return String(s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function closeSocket(){try{if(window.socket&&window.socket.readyState!==3){window.socket.close();}}catch(e){}}
function removeNotice(){var old=document.getElementById('radarLicenseNotice');if(old)old.remove();}
function showTrialNotice(message){trialOpen=true;var left=trialLeft();var hours=Math.max(0,Math.ceil(left/3600000));var old=document.getElementById('radarLicenseNotice');if(!old){old=document.createElement('div');old.id='radarLicenseNotice';old.style.cssText='position:fixed;left:12px;right:12px;top:12px;z-index:2147483000;background:rgba(15,23,42,.92);border:1px solid rgba(251,191,36,.55);border-radius:10px;color:#f8fafc;padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;font-size:13px;line-height:1.5;box-shadow:0 10px 28px rgba(0,0,0,.28);';document.body.appendChild(old);}
old.innerHTML='当前服务器未授权，已开启 1 天试用，剩余约 <b style="color:#fde68a">'+hours+'</b> 小时。'+esc(message||'试用结束前请联系管理员授权。')+' <a href="'+esc(cfg.groupUrl||'#')+'" target="_blank" rel="noopener" style="color:#7dd3fc;font-weight:700">加入群聊找授权码</a>';return true;}
function block(message){authorized=false;closeSocket();try{if(typeof window.updateConnectionStatus==='function')window.updateConnectionStatus('error','服务器未授权');}catch(e){}try{if(typeof window.showError==='function')window.showError('当前服务器未授权，请找管理员开通授权',10000);}catch(e){}
var old=document.getElementById('radarLicenseBlocker');if(old)old.remove();
var box=document.createElement('div');box.id='radarLicenseBlocker';box.style.cssText='position:fixed;inset:0;z-index:2147483647;background:rgba(4,8,18,.92);display:flex;align-items:center;justify-content:center;padding:18px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;color:#e8eefc;';
box.innerHTML='<div style="width:min(520px,94vw);background:#111827;border:1px solid rgba(96,165,250,.35);border-radius:14px;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.45);text-align:center"><h2 style="margin:0 0 12px;font-size:24px;color:#fef3c7">试用已结束，需要授权</h2><p style="margin:0 0 18px;line-height:1.7;color:#cbd5e1">'+esc(message||'未授权试用期为 1 天，试用结束后需要授权才能继续使用。')+'</p><p style="margin:0 0 20px;line-height:1.7;color:#dbeafe">请点击链接加入群聊【'+esc(cfg.groupName||'王者雷达共享开黑组队群')+'】，找我获取授权码。</p><a href="'+esc(cfg.groupUrl||'#')+'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border-radius:8px;background:#38bdf8;color:#06111f;font-weight:800;text-decoration:none">加入群聊找授权码</a></div>';
document.body.appendChild(box);return false;}
function allow(data){authorized=true;trialOpen=false;lastResult=data||{};var old=document.getElementById('radarLicenseBlocker');if(old)old.remove();removeNotice();if(data&&data.permanent)savePermanent();return true;}
function allowTrial(data){lastResult=data||{};if(trialLeft()>0)return showTrialNotice(data&&data.message?data.message:'试用结束前请联系管理员授权。');return block(data&&data.message?data.message:'未授权试用已结束，需要授权后才能继续使用。');}
function checkLicense(force){if(authorized&&!force)return Promise.resolve(true);if(cfg.permanent||readPermanent())return Promise.resolve(allow({permanent:true,local:true}));if(checking)return checking;
var url=String(cfg.serverUrl||'').replace(/\\/+$/,'')+'/api/license/check?host='+encodeURIComponent(cfg.host||location.hostname||'')+'&domain='+encodeURIComponent(location.hostname||'')+'&mode='+encodeURIComponent(cfg.mode||'all')+'&_='+(Date.now());
checking=fetch(url,{cache:'no-store'}).then(function(r){return r.json();}).then(function(data){checking=null;if(data&&data.authorized)return allow(data);return allowTrial(data||{});}).catch(function(){checking=null;return allowTrial({message:'授权服务器暂时连接失败，试用期内仍可使用；请尽快联系管理员授权。'});});
return checking;}
function gated(fn,ctx,args){if(authorized||trialOpen||cfg.permanent||readPermanent()||trialLeft()>0)return fn.apply(ctx,args);checkLicense(false).then(function(ok){if(ok)return fn.apply(ctx,args);});return undefined;}
function wrap(){if(typeof window.initApp==='function'&&!window.initApp.__licenseWrapped){nativeInitApp=window.initApp;window.initApp=function(){return gated(nativeInitApp,this,arguments);};window.initApp.__licenseWrapped=true;}
if(typeof window.initWebSocket==='function'&&!window.initWebSocket.__licenseWrapped){nativeInitWebSocket=window.initWebSocket;window.initWebSocket=function(){return gated(nativeInitWebSocket,this,arguments);};window.initWebSocket.__licenseWrapped=true;}}
wrap();setTimeout(wrap,0);document.addEventListener('DOMContentLoaded',function(){wrap();checkLicense(false);});
if(!cfg.permanent){setInterval(function(){checkLicense(true);},60000);setInterval(function(){if(!authorized&&trialLeft()<=0)block('未授权试用已结束，需要授权后才能继续使用。');else if(!authorized)showTrialNotice('试用结束前请联系管理员授权。');},300000);}
window.RadarServerLicense={check:checkLicense,isAuthorized:function(){return authorized;},last:function(){return lastResult;},showBlock:block};
})();`;
}

async function ensureRhelPackageRepos(ctx, targetName) {
  const { emit, pkgMgr } = ctx;
  if (ctx.osFamily !== 'rhel') return;

  const cache = await execSudo(ctx, `${pkgMgr} makecache`, { allowFail: true, silent: true });
  if (cache.code === 0) return;

  const combined = `${cache.stdout || ''}\n${cache.stderr || ''}`;
  if (!isCentos8Repo404(ctx, combined)) {
    emit.log('warn', `${targetName} 安装前检测到软件源异常，请检查服务器网络或镜像源配置`);
    return;
  }

  if (ctx.repoFixed) {
    emit.log('warn', '已尝试修复 CentOS 8 软件源，但缓存仍异常');
    return;
  }

  emit.log('warn', '检测到 CentOS Linux 8 官方仓库已失效，正在自动切换到 vault.centos.org ...');
  await execSudo(ctx, "mkdir -p /etc/yum.repos.d/backup-radar && cp -af /etc/yum.repos.d/CentOS* /etc/yum.repos.d/backup-radar/ 2>/dev/null || true", { allowFail: true, silent: true });
  await execSudo(ctx, "bash -lc 'shopt -s nullglob; for f in /etc/yum.repos.d/CentOS*; do case \"$f\" in *.disabled-by-radar|*/backup-radar) continue ;; esac; mv -f \"$f\" \"$f.disabled-by-radar\"; done'", { allowFail: true, silent: true });
  await execSudo(ctx, "rm -f /etc/yum.repos.d/CentOS-Base.repo /etc/yum.repos.d/CentOS-Base.repo.disabled-by-radar", { allowFail: true, silent: true });
  await execSudo(ctx, `cat > /etc/yum.repos.d/CentOS-Base.repo <<'RADAR_CENTOS8_REPO'\n${buildCentos8VaultRepo()}\nRADAR_CENTOS8_REPO\n`);
  await execSudo(ctx, `${pkgMgr} clean all`, { allowFail: true, silent: true });
  const retry = await execSudo(ctx, `${pkgMgr} makecache`, { allowFail: true, silent: true });
  if (retry.code !== 0) {
    const retryMsg = `${retry.stdout || ''}\n${retry.stderr || ''}`.trim();
    throw new Error(`CentOS 8 软件源已失效，自动切换 vault 后仍不可用，请手工检查 /etc/yum.repos.d/ 下 repo 配置\n${retryMsg}`);
  }

  ctx.repoFixed = true;
  emit.log('success', 'CentOS 8 软件源已自动切换为 vault，继续安装');
}

function isCentos8Repo404(ctx, text) {
  if (ctx.osId !== 'centos') return false;
  if (!/^8([._-]|$)/.test(ctx.osVersionId || '')) return false;
  return /Failed to download metadata for repo/i.test(text) || /Status code: 404/i.test(text) || /repomd\.xml/i.test(text);
}

function readOsReleaseValue(osRel, key) {
  const m = String(osRel).match(new RegExp(`^${key}=(.*)$`, 'm'));
  if (!m) return '';
  return m[1].trim().replace(/^['\"]|['\"]$/g, '');
}

function buildCentos8VaultRepo() {
  return [
    '[BaseOS]',
    'name=CentOS-8 - BaseOS - vault',
    'baseurl=http://vault.centos.org/8.5.2111/BaseOS/$basearch/os/',
    'enabled=1',
    'gpgcheck=0',
    '',
    '[AppStream]',
    'name=CentOS-8 - AppStream - vault',
    'baseurl=http://vault.centos.org/8.5.2111/AppStream/$basearch/os/',
    'enabled=1',
    'gpgcheck=0',
    '',
    '[extras]',
    'name=CentOS-8 - Extras - vault',
    'baseurl=http://vault.centos.org/8.5.2111/extras/$basearch/os/',
    'enabled=1',
    'gpgcheck=0',
    '',
    '[PowerTools]',
    'name=CentOS-8 - PowerTools - vault',
    'baseurl=http://vault.centos.org/8.5.2111/PowerTools/$basearch/os/',
    'enabled=1',
    'gpgcheck=0',
    '',
  ].join('\n');
}

function buildRhelMainNginxConf() {
  return [
    'user nginx;',
    'worker_processes auto;',
    'error_log /var/log/nginx/error.log;',
    'pid /run/nginx.pid;',
    '',
    'events {',
    '    worker_connections 1024;',
    '}',
    '',
    'http {',
    '    log_format main  "$remote_addr - $remote_user [$time_local] \\\"$request\\\" "',
    '                      "$status $body_bytes_sent \\\"$http_referer\\\" "',
    '                      "\\\"$http_user_agent\\\" \\\"$http_x_forwarded_for\\\"";',
    '    access_log  /var/log/nginx/access.log  main;',
    '    sendfile            on;',
    '    tcp_nopush          on;',
    '    tcp_nodelay         on;',
    '    keepalive_timeout   65;',
    '    types_hash_max_size 4096;',
    '    include             /etc/nginx/mime.types;',
    '    default_type        application/octet-stream;',
    '    include /etc/nginx/conf.d/*.conf;',
    '}',
    '',
  ].join('\n');
}

// ---------------------------------------------------------------------------
// 配置模板
// ---------------------------------------------------------------------------

function buildNginxConf(siteRoot, useDefaultServer, sitePort = 80, enablePhp = false, phpFastcgiPass = '') {
  const lines = [
    'server {',
    useDefaultServer ? `    listen ${sitePort} default_server;` : `    listen ${sitePort};`,
    '    server_name _;',
    '',
    `    root ${siteRoot};`,
    '    index index.php index.html index.htm;',
    '',
    '    location / {',
    '        try_files $uri $uri/ /index.html;',
    '    }',
    '',
    '    # 隐藏 .user.ini 等隐藏文件',
    '    location ~ /\\.(?!well-known) {',
    '        deny all;',
    '    }',
    '',
    '    # Java 后端反代（可选，通过 /api/ 前缀转发到 8888）',
    '    location /api/ {',
    '        proxy_pass http://127.0.0.1:8888/;',
    '        proxy_set_header Host $host;',
    '        proxy_set_header X-Real-IP $remote_addr;',
    '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
    '    }',
  ];
  if (enablePhp) {
    lines.push(
      '',
      '    # PHP support for card-login admin/API',
      '    location ~ \\.php$ {',
      '        include fastcgi_params;',
      `        fastcgi_pass ${phpFastcgiPass || '127.0.0.1:9000'};`,
      '        fastcgi_index index.php;',
      '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
      '        fastcgi_param DOCUMENT_ROOT $document_root;',
      '    }'
    );
  }
  lines.push('}', '');
  return lines.join('\n');
}

function buildSiteUrl(host, port) {
  const suffix = Number(port) === 80 ? '' : `:${port}`;
  return `http://${host}${suffix}/`;
}

function buildSystemdUnit() {
  return [
    '[Unit]',
    'Description=Radar Java Backend (home-server)',
    'After=network.target',
    '',
    '[Service]',
    'Type=simple',
    'WorkingDirectory=/www/server/radar-java',
    // ★ 启动前原子清理：杀残留 Java + 等端口释放，避免 BindException
    //    - 前缀表示该行失败不阻断后续 ExecStart
    //    - pkill 替代 killall（killall 在 CentOS 8 默认未安装）
    //    - {1..30} 替代 $(seq 1 15)（systemd 不做 $() 展开，原写法循环体为空）
    'ExecStartPre=-/usr/bin/pkill -9 -f wz.jar',
    'ExecStartPre=-/usr/bin/fuser -k 8888/tcp',
    'ExecStartPre=-/usr/bin/fuser -k 9999/tcp',
    'ExecStartPre=/usr/bin/sleep 3',
    "ExecStartPre=-/usr/bin/bash -c 'for i in {1..30}; do (ss -antp | grep -q :8888 || ss -antp | grep -q :9999) && sleep 1 || exit 0; done'",
    'ExecStart=/www/server/java/jdk1.8.0_371/bin/java -Djava.net.preferIPv4Stack=true -Xmx1024M -Xms256M -jar /www/server/radar-java/wz.jar --server.port=8888',
    'SuccessExitStatus=143',
    'Restart=on-failure',
    'RestartSec=5',
    'StandardOutput=journal',
    'StandardError=journal',
    'LimitNOFILE=65535',
    '',
    '[Install]',
    'WantedBy=multi-user.target',
    '',
  ].join('\n');
}

// ---------------------------------------------------------------------------
// 工具函数
// ---------------------------------------------------------------------------

function exec(ctx, cmd, opts = {}) {
  const { conn, emit } = ctx;
  const { silent = false, allowFail = false } = opts;
  return new Promise((resolve, reject) => {
    conn.exec(cmd, { pty: true }, (err, stream) => {
      if (err) return reject(err);
      let stdout = '';
      let stderr = '';
      stream
        .on('data', (d) => {
          const s = d.toString();
          stdout += s;
          if (!silent) streamOut(emit, s, 'info');
        })
        .stderr.on('data', (d) => {
          const s = d.toString();
          stderr += s;
          if (!silent) streamOut(emit, s, 'info'); // 大量命令会把正常输出写到 stderr，这里统一 info
        });
      stream.on('close', (code) => {
        if (code !== 0 && !allowFail) {
          const msg = `命令失败 (code=${code}): ${cmd}\n${stderr || stdout}`;
          reject(new Error(msg));
        } else {
          resolve({ code, stdout, stderr });
        }
      });
    });
  });
}

function execSudo(ctx, cmd, opts = {}) {
  if (ctx.sudo) {
    // 用 bash -c 包一层避免复杂命令被截断
    const wrapped = `${ctx.sudo}bash -c ${shQuote(cmd)}`;
    return exec(ctx, wrapped, opts);
  }
  return exec(ctx, `bash -c ${shQuote(cmd)}`, opts);
}

function streamOut(emit, raw, level) {
  const lines = raw.replace(/\r/g, '').split('\n');
  for (const line of lines) {
    if (!line) continue;
    emit.log(level, line);
  }
}

function walk(dir, baseRel = '') {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const rel = baseRel ? path.join(baseRel, entry.name) : entry.name;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...walk(full, rel));
    } else if (entry.isFile()) {
      out.push(rel);
    }
  }
  return out;
}

function injectCardAdminPassword(config, password) {
  const escaped = phpSingleQuoted(password);
  const next = String(config).replace(
    /(define\(\s*['"]AUTH_ADMIN_PASSWORD['"]\s*,\s*)['"][^'"]*['"](\s*\)\s*;)/,
    `$1'${escaped}'$2`
  );
  if (next === config) {
    throw new Error('auth_config.php 中未找到 AUTH_ADMIN_PASSWORD 配置');
  }
  return next;
}

function phpSingleQuoted(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function parseCardKeys(raw) {
  return String(raw || '')
    .split(/[\r\n,，;；\s]+/)
    .map((s) => s.trim())
    .filter(Boolean);
}

function sha256Hex(value) {
  return crypto.createHash('sha256').update(value, 'utf8').digest('hex');
}

function injectCardGate(html, rawKeys) {
  const keys = parseCardKeys(rawKeys);
  if (!keys.length) throw new Error('卡密版缺少卡密');
  const hashes = Array.from(new Set(keys.map(sha256Hex)));
  const payload = JSON.stringify({
    hashes,
    storageKey: `radar.card.auth.${hashes[0].slice(0, 12)}`,
  });
  const marker = '<!-- RADAR_CARD_GATE_INJECTED -->';
  const cleanHtml = String(html).replace(new RegExp(`${marker}[\\s\\S]*?<!-- /RADAR_CARD_GATE_INJECTED -->`, 'g'), '');
  const gate = buildCardGateSnippet(payload, marker);
  if (/<body\b[^>]*>/i.test(cleanHtml)) {
    return cleanHtml.replace(/<body\b[^>]*>/i, (m) => `${m}\n${gate}`);
  }
  return `${gate}\n${cleanHtml}`;
}

function buildCardGateSnippet(payloadJson, marker) {
  return `${marker}
<style id="radar-card-gate-style">
html:not(.radar-card-unlocked) body > :not(#radarCardGate):not(#radar-card-gate-style):not(#radar-card-gate-script) { display: none !important; }
#radarCardGate { position: fixed; inset: 0; z-index: 2147483647; display: grid; place-items: center; min-height: 100vh; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif; color: #e8f2ff; background: radial-gradient(circle at 18% 12%, rgba(52, 211, 153, .18), transparent 34%), radial-gradient(circle at 82% 22%, rgba(56, 189, 248, .18), transparent 32%), linear-gradient(135deg, #050816 0%, #0e172a 50%, #111827 100%); }
.radar-card-panel { width: min(420px, 100%); padding: 28px; border: 1px solid rgba(125, 211, 252, .22); border-radius: 14px; background: rgba(8, 13, 28, .78); box-shadow: 0 24px 80px rgba(0, 0, 0, .45); backdrop-filter: blur(18px); }
.radar-card-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.radar-card-logo { width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; color: #67e8f9; background: rgba(14, 165, 233, .16); border: 1px solid rgba(103, 232, 249, .28); font-size: 18px; }
.radar-card-title { margin: 0; font-size: 20px; line-height: 1.25; letter-spacing: 0; }
.radar-card-sub { margin-top: 4px; color: #8da2c0; font-size: 12px; letter-spacing: .08em; }
.radar-card-label { display: block; margin-bottom: 8px; color: #93c5fd; font-size: 13px; font-weight: 600; }
.radar-card-input { width: 100%; height: 48px; padding: 0 14px; color: #eef6ff; background: rgba(2, 6, 23, .72); border: 1px solid rgba(125, 211, 252, .24); border-radius: 10px; outline: none; font-size: 15px; }
.radar-card-input:focus { border-color: #38bdf8; box-shadow: 0 0 0 4px rgba(56, 189, 248, .14); }
.radar-card-btn { width: 100%; height: 48px; margin-top: 14px; color: #fff; border: 0; border-radius: 10px; background: linear-gradient(135deg, #38bdf8, #2563eb); font-size: 15px; font-weight: 700; cursor: pointer; }
.radar-card-msg { min-height: 20px; margin-top: 10px; color: #fda4af; font-size: 13px; }
</style>
<div id="radarCardGate" role="dialog" aria-modal="true" aria-labelledby="radarCardTitle">
  <form class="radar-card-panel" id="radarCardForm">
    <div class="radar-card-brand">
      <div class="radar-card-logo">◆</div>
      <div><h1 class="radar-card-title" id="radarCardTitle">卡密访问验证</h1><div class="radar-card-sub">PRIVATE ACCESS</div></div>
    </div>
    <label class="radar-card-label" for="radarCardInput">请输入卡密</label>
    <input class="radar-card-input" id="radarCardInput" type="password" autocomplete="off" required>
    <button class="radar-card-btn" type="submit">进入页面</button>
    <div class="radar-card-msg" id="radarCardMsg" aria-live="polite"></div>
  </form>
</div>
<script id="radar-card-gate-script">
(function(){
  var cfg = ${payloadJson};
  var okSet = {};
  for (var i = 0; i < cfg.hashes.length; i += 1) okSet[cfg.hashes[i]] = true;
  function unlock(){ document.documentElement.classList.add('radar-card-unlocked'); var g = document.getElementById('radarCardGate'); if (g) g.remove(); }
  function rotr(n,x){ return (x>>>n)|(x<<(32-n)); }
  function sha256(ascii){ var mathPow=Math.pow,maxWord=mathPow(2,32),result='',words=[],asciiBitLength=ascii.length*8,hash=sha256.h=sha256.h||[],k=sha256.k=sha256.k||[],primeCounter=k.length,isComposite={}; for(var candidate=2; primeCounter<64; candidate++){ if(!isComposite[candidate]){ for(var j=0;j<313;j+=candidate){ isComposite[j]=candidate; } hash[primeCounter]=(mathPow(candidate,.5)*maxWord)|0; k[primeCounter++]=(mathPow(candidate,1/3)*maxWord)|0; } } ascii += '\\x80'; while(ascii.length%64-56) ascii += '\\x00'; for(var i=0;i<ascii.length;i++){ var j=ascii.charCodeAt(i); if(j>>8) return ''; words[i>>2] |= j << ((3-i)%4)*8; } words[words.length]=((asciiBitLength/maxWord)|0); words[words.length]=(asciiBitLength); for(var j=0;j<words.length;){ var w=words.slice(j,j+=16),oldHash=hash.slice(0); for(var i=0;i<64;i++){ var w15=w[i-15],w2=w[i-2],a=hash[0],e=hash[4],temp1=hash[7]+(rotr(6,e)^rotr(11,e)^rotr(25,e))+((e&hash[5])^((~e)&hash[6]))+k[i]+(w[i]=(i<16)?w[i]:((w[i-16]+(rotr(7,w15)^rotr(18,w15)^(w15>>>3))+w[i-7]+(rotr(17,w2)^rotr(19,w2)^(w2>>>10)))|0)),temp2=(rotr(2,a)^rotr(13,a)^rotr(22,a))+((a&hash[1])^(a&hash[2])^(hash[1]&hash[2])); hash=[(temp1+temp2)|0].concat(hash); hash[4]=(hash[4]+temp1)|0; } for(var i=0;i<8;i++){ hash[i]=(hash[i]+oldHash[i])|0; } } for(var i=0;i<8;i++){ for(var j=3;j+1;j--){ var b=(hash[i]>>(j*8))&255; result += ((b<16)?0:'')+b.toString(16); } } return result; }
  try { if (localStorage.getItem(cfg.storageKey) === '1') return unlock(); } catch(e) {}
  var form = document.getElementById('radarCardForm'), input = document.getElementById('radarCardInput'), msg = document.getElementById('radarCardMsg');
  if (input) setTimeout(function(){ input.focus(); }, 30);
  if (form) form.addEventListener('submit', function(e){ e.preventDefault(); var key = (input.value || '').trim(); if (okSet[sha256(unescape(encodeURIComponent(key)))]) { try { localStorage.setItem(cfg.storageKey, '1'); } catch(_) {} unlock(); } else { msg.textContent = '卡密不正确，请重新输入'; input.select(); } });
})();
</script>
<!-- /RADAR_CARD_GATE_INJECTED -->`;
}

function sftpMkdirp(sftp, remoteDir) {
  return new Promise((resolve, reject) => {
    sftp.stat(remoteDir, (err) => {
      if (!err) return resolve();
      const parent = posixDirname(remoteDir);
      const done = () => sftp.mkdir(remoteDir, (e) => (e ? reject(e) : resolve()));
      if (parent === remoteDir || parent === '/' || parent === '.' || parent === '') {
        done();
      } else {
        sftpMkdirp(sftp, parent).then(done).catch(reject);
      }
    });
  });
}

function sftpPut(sftp, localPath, remotePath) {
  return new Promise((resolve, reject) => {
    sftp.fastPut(localPath, remotePath, {}, (err) => (err ? reject(err) : resolve()));
  });
}

function sftpWriteText(sftp, remotePath, content) {
  return new Promise((resolve, reject) => {
    sftp.writeFile(remotePath, content, 'utf8', (err) => (err ? reject(err) : resolve()));
  });
}

function posixDirname(p) {
  const idx = p.lastIndexOf('/');
  if (idx <= 0) return '/';
  return p.substring(0, idx);
}

function shQuote(s) {
  return `'${String(s).replace(/'/g, `'\\''`)}'`;
}
function shEscape(s) {
  return String(s).replace(/'/g, `'\\''`);
}
function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

module.exports = { runDeployment, STEPS };
