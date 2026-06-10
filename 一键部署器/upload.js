#!/usr/bin/env node
'use strict';

const path = require('path');
const fs = require('fs');
const { Client } = require('ssh2');

const REMOTE_DIR = '/opt/radar-deployer';
const WEB_ROOT_NAME = '\u7f51\u9875\u6e90\u7801';
const APP_DIR_NAME = '\u4e00\u952e\u90e8\u7f72\u5668';
const WEB_DIR = path.resolve(__dirname, '..', WEB_ROOT_NAME);
const DEPLOYER_DIR = __dirname;
const REMOTE_WEB_DIR = `${REMOTE_DIR}/${WEB_ROOT_NAME}`;
const REMOTE_APP_DIR = `${REMOTE_DIR}/${APP_DIR_NAME}`;

function parseArgs() {
  const args = {};
  for (const a of process.argv.slice(2)) {
    const m = a.match(/^--([\w-]+)=(.*)$/);
    if (m) {
      const key = m[1].replace(/-([a-z])/g, (_, ch) => ch.toUpperCase());
      args[key] = m[2];
    }
  }
  return args;
}

function sshConnect(host, port, username, password) {
  return new Promise((resolve, reject) => {
    const c = new Client();
    c.on('ready', () => resolve(c));
    c.on('keyboard-interactive', (_name, _instructions, _lang, _prompts, finish) => {
      finish([password]);
    });
    c.on('error', reject);
    c.connect({ host, port: parseInt(port) || 22, username, password, readyTimeout: 15000, tryKeyboard: true });
  });
}

function sshExec(conn, cmd) {
  return new Promise((resolve, reject) => {
    conn.exec(cmd, (err, stream) => {
      if (err) return reject(err);
      let out = '', errOut = '';
      stream.on('data', d => { out += d; process.stdout.write(d); });
      stream.stderr.on('data', d => { errOut += d; process.stderr.write(d); });
      stream.on('close', code => {
        if (code && code !== 0 && !cmd.includes('command -v')) reject(new Error(errOut || 'exit ' + code));
        else resolve(out);
      });
    });
  });
}

function sftpMkdir(sftp, dir) {
  return new Promise(resolve => {
    sftp.mkdir(dir, err => {
      if (!err) return resolve();
      const p = dir.substring(0, dir.lastIndexOf('/'));
      if (!p || p === '/') return resolve();
      sftpMkdir(sftp, p).then(() => sftp.mkdir(dir, () => resolve()));
    });
  });
}

async function uploadDir(sftp, local, remote, prefix) {
  await sftpMkdir(sftp, remote);
  for (const entry of fs.readdirSync(local, { withFileTypes: true })) {
    if (entry.name === 'node_modules') continue;
    const lp = path.join(local, entry.name);
    const rp = remote + '/' + entry.name;
    if (entry.isDirectory()) {
      console.log('  [DIR]', entry.name);
      await uploadDir(sftp, lp, rp);
    } else {
      await new Promise((res, rej) => sftp.fastPut(lp, rp, e => e ? rej(e) : res()));
    }
  }
}

async function main() {
  const args = parseArgs();
  const host = args.host;
  const user = args.user || 'root';
  const port = args.port || 22;
  const password = args.password || process.env.RADAR_UPLOAD_PASSWORD || process.env.SSH_PASSWORD;
  const adminUser = args.adminUser || process.env.RADAR_ADMIN_USER || 'admin';
  const adminPassword =
    args.adminPassword ||
    process.env.RADAR_ADMIN_PASSWORD ||
    args.code ||
    process.env.RADAR_UPLOAD_ADMIN_PASSWORD ||
    '';

  if (!host || !password) {
    console.log('Usage: node upload.js --host=IP --password=PWD [--user=root] [--port=22] [--admin-user=admin] [--admin-password=PWD]');
    process.exit(1);
  }

  if (!fs.existsSync(WEB_DIR)) { console.error('Missing web source dir:', WEB_DIR); process.exit(1); }
  for (const variant of ['\u7eaf\u51c0\u7248', '\u5361\u5bc6\u7248']) {
    const dir = path.join(WEB_DIR, variant);
    if (!fs.existsSync(path.join(dir, 'wz.jar'))) { console.error(`Missing ${variant}/wz.jar`); process.exit(1); }
    if (!fs.existsSync(path.join(dir, 'index.html'))) { console.error(`Missing ${variant}/index.html`); process.exit(1); }
  }

  console.log('Connecting', host + '...');
  const conn = await sshConnect(host, port, user, password);
  console.log('Connected');

  try {
    console.log('Creating remote dirs...');
    await sshExec(conn, `mkdir -p '${REMOTE_WEB_DIR}' '${REMOTE_APP_DIR}/public'`);

    console.log('Uploading web source...');
    const sftp = await new Promise((res, rej) => conn.sftp((e, s) => e ? rej(e) : res(s)));
    await uploadDir(sftp, WEB_DIR, REMOTE_WEB_DIR);

    console.log('Uploading deployer...');
    await uploadDir(sftp, DEPLOYER_DIR, REMOTE_APP_DIR);

    console.log('Checking Node.js...');
    try {
      const v = await sshExec(conn, 'node -v 2>/dev/null');
      console.log('Node.js:', v.trim());
    } catch (_) {
      const os = (await sshExec(conn, 'cat /etc/os-release 2>/dev/null | grep ^ID=')).trim();
      if (os.includes('ubuntu') || os.includes('debian')) {
        await sshExec(conn, 'curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs');
      } else {
        await sshExec(conn, 'curl -fsSL https://rpm.nodesource.com/setup_22.x | bash - && (yum install -y nodejs || dnf install -y nodejs)');
      }
      console.log('Node.js installed');
    }

    console.log('npm install...');
    await sshExec(conn, `cd '${REMOTE_APP_DIR}' && npm install --omit=dev`);

    console.log('Creating systemd service for deployer...');
    const serviceContent = `[Unit]
Description=Radar Deployer
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${REMOTE_APP_DIR}
Environment=NODE_ENV=production
Environment=PAYLOAD_DIR=${REMOTE_WEB_DIR}
Environment=ADMIN_USERNAME=${adminUser}
${adminPassword ? `Environment=ADMIN_PASSWORD=${adminPassword}` : ''}
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=10
StandardOutput=file:/tmp/radar.log
StandardError=file:/tmp/radar.log

[Install]
WantedBy=multi-user.target`;
    await sshExec(conn, `cat > /etc/systemd/system/radar-deployer.service << 'EOF'\n${serviceContent}\nEOF`);
    await sshExec(conn, 'systemctl daemon-reload');
    await sshExec(conn, 'systemctl enable radar-deployer.service');
    await sshExec(conn, 'systemctl stop radar-deployer.service 2>/dev/null || true');
    await sshExec(conn, 'systemctl start radar-deployer.service');
    await sshExec(conn, 'sleep 3');

    console.log('Testing server startup (foreground)...');
    const envParts = [`ADMIN_USERNAME=${shellQuote(adminUser)}`];
    if (adminPassword) envParts.push(`ADMIN_PASSWORD=${shellQuote(adminPassword)}`);
    envParts.push(`PAYLOAD_DIR=${shellQuote(REMOTE_WEB_DIR)}`);
    const foregroundCmd = `${envParts.join(' ')} node server.js`;
    try {
      await sshExec(conn, `cd '${REMOTE_APP_DIR}' && timeout 5 bash -lc ${shellQuote(foregroundCmd)} 2>&1 || true`);
    } catch (_) {}

    console.log('Starting server (background)...');
    await sshExec(conn, 'systemctl restart radar-deployer.service');
    await sshExec(conn, 'sleep 5');

    console.log('Opening firewall port 3000...');
    await sshExec(conn, 'firewall-cmd --permanent --add-port=3000/tcp 2>/dev/null || ufw allow 3000/tcp 2>/dev/null || iptables -I INPUT -p tcp --dport 3000 -j ACCEPT 2>/dev/null || true');
    await sshExec(conn, 'firewall-cmd --reload 2>/dev/null || true');

    const status = await sshExec(conn, 'systemctl is-active radar-deployer.service 2>/dev/null || echo inactive');
    if (status.includes('active')) {
      console.log('Server is running (systemd active)');
    } else {
      console.log('Server NOT running, checking log:');
      await sshExec(conn, 'journalctl -u radar-deployer.service -n 30 --no-pager 2>/dev/null || cat /tmp/radar.log 2>/dev/null || echo NO_LOG');
    }

    const portCheck = await sshExec(conn, 'ss -tlnp | grep :3000 || echo NO_PORT');
    if (!portCheck.includes('NO_PORT')) console.log('Port 3000 listening');

    console.log('');
    console.log('Done: http://' + host + ':3000');
    console.log('Admin user:', adminUser);

  } catch (err) {
    console.error('Error:', err.message);
  } finally {
    conn.end();
  }
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\\''`)}'`;
}

main();
