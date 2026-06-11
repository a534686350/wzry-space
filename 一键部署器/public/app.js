(function () {
    'use strict';

    const STEPS_DEF = [
        { id: 'connect',       label: '连接 SSH' },
        { id: 'detect',        label: '检测系统环境' },
        { id: 'install-java',  label: '安装 Java 运行环境' },
        { id: 'install-nginx', label: '安装 Nginx' },
        { id: 'install-php',   label: '配置 PHP（卡密版）' },
        { id: 'prepare-dir',   label: '创建站点目录' },
        { id: 'upload',        label: '上传/拉取源码' },
        { id: 'nginx-config',  label: '配置 Nginx (自选端口)' },
        { id: 'java-service',  label: '启动 Java 服务 (8888)' },
        { id: 'firewall',      label: '放行防火墙端口' },
        { id: 'health',        label: '健康检查' },
    ];

    // ------------------------------------------------------------------
    // DOM refs
    // ------------------------------------------------------------------
    const $ = (sel) => document.querySelector(sel);
    const els = {
        form:            $('#deployForm'),
        btnDeploy:       $('#btnDeploy'),
        btnCancel:       $('#btnCancel'),
        btnTestConn:     $('#btnTestConn'),
        btnClearServer:  $('#btnClearServer'),
        testResult:      $('#testResult'),
        doneMsg:         $('#doneMsg'),
        doneIp:          $('#doneIp'),
        btnClearLog:     $('#btnClearLog'),
        btnDownloadLog:  $('#btnDownloadLog'),
        stepsList:       $('#stepsList'),
        logbox:          $('#logbox'),
        logEmpty:        $('#logEmpty'),
        autoScroll:      $('#autoScroll'),
        progressFill:    $('#progressFill'),
        progressPercent: $('#progressPercent'),
        progressText:    $('#progressText'),
        connChip:        $('#connChip'),
        connState:       $('#connState'),
        pwIcon:          $('#pwIcon'),
        deployCard:      $('#f-deploy-card'),
        cardAdminField:  $('#cardAdminField'),
        cardAdminPass:   $('#f-card-admin-password'),
        opsConfig:       $('#opsConfig'),
        opsInstallCode:  $('#f-ops-install-code'),
    };

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------
    const state = {
        deploying: false,
        rawLog: [],
    };
    const modeLabels = {
        clean: '纯净版',
        card: '卡密版',
        ops: '运营版',
    };

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------
    renderSteps();
    bindDeployMode();
    bootMeta();

    function bindDeployMode() {
        const radios = document.querySelectorAll('input[name="deployMode"]');
        const sync = () => {
            const mode = document.querySelector('input[name="deployMode"]:checked');
            const isCard = mode && mode.value === 'card';
            const isOps = mode && mode.value === 'ops';
            if (els.cardAdminField) els.cardAdminField.classList.toggle('hidden', !isCard);
            if (els.cardAdminPass) els.cardAdminPass.required = !!isCard;
            if (els.opsConfig) els.opsConfig.classList.toggle('hidden', !isOps);
            if (els.opsInstallCode) els.opsInstallCode.required = !!isOps;
        };
        radios.forEach((r) => r.addEventListener('change', sync));
        sync();
    }

    async function bootMeta() {
        try {
            await fetch('/api/meta', { cache: 'no-store' });
            initSocket();
        } catch (err) {
            // 即使 /api/meta 失败，也尝试直接连 socket
            initSocket();
        }
    }

    // ------------------------------------------------------------------
    // Socket.IO
    // ------------------------------------------------------------------
    let socket = null;
    function initSocket() {
        if (typeof io === 'undefined') {
            setConnState('err', 'Socket.IO 未加载');
            appendLog('error', '请通过 http://localhost:3000/ 打开，而不是双击文件');
            return;
        }
        socket = io({
            transports: ['websocket', 'polling'],
        });

        socket.on('connect', () => {
            setConnState('ok', '已连接 · ' + socket.id.substring(0, 6));
        });
        socket.on('disconnect', (reason) => {
            setConnState('', '已断开 (' + reason + ')');
            if (state.deploying) lockForm(false);
        });
        socket.on('connect_error', (err) => {
            setConnState('', '连接失败');
        });

        socket.on('deploy:log',      ({ level, message, ts }) => appendLog(level, message, ts));
        socket.on('deploy:step',     ({ id, status, message }) => setStep(id, status, message));
        socket.on('deploy:progress', ({ percent, message }) => setProgress(percent, message));
        socket.on('deploy:done',     ({ urls }) => {
            setProgress(100, '部署完成');
            els.doneMsg.style.display = 'block';
            els.doneIp.textContent = '部署信息已保存到后台管理，当前卡密已失效。';
            lockForm(false);
            state.deploying = false;
        });
        socket.on('deploy:error', ({ message }) => {
            appendLog('error', message || '未知错误');
            lockForm(false);
            state.deploying = false;
        });
        socket.on('clear:done', () => {
            setProgress(100, '清理完成');
            appendLog('success', '服务器项目数据清理完成，可以重新部署或关闭页面');
            lockForm(false);
            state.deploying = false;
        });
        socket.on('clear:error', ({ message }) => {
            appendLog('error', message || '清理失败');
            lockForm(false);
            state.deploying = false;
        });

        socket.on('test:result', (data) => {
            els.btnTestConn.disabled = false;
            els.btnTestConn.textContent = '🔗 测试连接';
            if (data.ok) {
                els.testResult.className = 'test-result ok';
                els.testResult.innerHTML = '✅ SSH 连接成功！<br>系统版本：<span class="os-info">' + (data.osInfo || '未知') + '</span>';
            } else {
                els.testResult.className = 'test-result fail';
                els.testResult.innerHTML = '❌ 连接失败：' + (data.error || '未知错误');
            }
            els.testResult.style.display = 'block';
        });
    }

    // ------------------------------------------------------------------
    // Steps timeline
    // ------------------------------------------------------------------
    function renderSteps() {
        els.stepsList.innerHTML = '';
        STEPS_DEF.forEach((s, idx) => {
            const li = document.createElement('li');
            li.className = 'pending';
            li.dataset.id = s.id;
            li.innerHTML =
                '<span class="t-dot">' + (idx + 1) + '</span>' +
                '<div>' +
                '  <div class="t-label">' + s.label + '</div>' +
                '  <div class="t-sub" data-role="sub">等待中</div>' +
                '</div>';
            els.stepsList.appendChild(li);
        });
    }
    function setStep(id, status, message) {
        const li = els.stepsList.querySelector('li[data-id="' + id + '"]');
        if (!li) return;
        li.classList.remove('pending', 'running', 'success', 'failed');
        li.classList.add(status);
        const dot = li.querySelector('.t-dot');
        const sub = li.querySelector('[data-role="sub"]');
        if (status === 'running')      dot.textContent = '•';
        else if (status === 'success') dot.textContent = '✓';
        else if (status === 'failed')  dot.textContent = '✗';
        if (sub) {
            sub.textContent = message ||
                ({ running: '进行中...', success: '完成', failed: '失败', pending: '等待中' }[status] || '');
        }
    }

    // ------------------------------------------------------------------
    // Progress bar
    // ------------------------------------------------------------------
    function setProgress(percent, text) {
        const p = Math.max(0, Math.min(100, Math.round(percent || 0)));
        els.progressFill.style.width = p + '%';
        els.progressPercent.textContent = p + '%';
        if (text) els.progressText.textContent = text;
    }

    // ------------------------------------------------------------------
    // Log
    // ------------------------------------------------------------------
    function appendLog(level, message, ts) {
        if (els.logEmpty && els.logEmpty.parentNode) els.logEmpty.remove();

        const t = new Date(ts || Date.now());
        const pad = (n) => String(n).padStart(2, '0');
        const tsStr = '[' + pad(t.getHours()) + ':' + pad(t.getMinutes()) + ':' + pad(t.getSeconds()) + ']';

        state.rawLog.push(tsStr + ' ' + (level || 'info').toUpperCase() + ' ' + message);

        const line = document.createElement('span');
        line.className = 'logline ' + (level || 'info');

        const tsEl = document.createElement('span');
        tsEl.className = 'ts';
        tsEl.textContent = tsStr;

        const tag = document.createElement('span');
        tag.className = 'tag';
        tag.textContent = (level || 'info').toUpperCase();

        const msg = document.createElement('span');
        msg.className = 'msg';
        msg.textContent = message;

        line.appendChild(tsEl);
        line.appendChild(tag);
        line.appendChild(msg);
        line.appendChild(document.createTextNode('\n'));

        els.logbox.appendChild(line);
        if (els.autoScroll.checked) {
            els.logbox.scrollTop = els.logbox.scrollHeight;
        }
    }

    function clearLog() {
        els.logbox.innerHTML =
            '<div class="log-empty" id="logEmpty">' +
            '  <div class="emo">🛰️</div><div>日志已清空</div>' +
            '</div>';
        els.logEmpty = $('#logEmpty');
        state.rawLog = [];
    }

    function downloadLog() {
        if (!state.rawLog.length) {
            appendLog('warn', '当前没有日志可下载');
            return;
        }
        const blob = new Blob([state.rawLog.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const ts = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
        a.href = url;
        a.download = 'radar-deploy-' + ts + '.log';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 0);
    }

    // ------------------------------------------------------------------
    // Conn state chip
    // ------------------------------------------------------------------
    function setConnState(state, text) {
        els.connChip.className = 'conn-chip ' + (state || '');
        els.connState.textContent = text;
    }

    // ------------------------------------------------------------------
    // Form lock / button spinner
    // ------------------------------------------------------------------
    function lockForm(locked) {
        state.deploying = locked;
        Array.from(els.form.elements).forEach((el) => {
            if (el.tagName === 'BUTTON') return;
            el.disabled = locked;
        });
        els.btnDeploy.disabled = locked;
        if (els.btnClearServer) els.btnClearServer.disabled = locked;
        els.btnCancel.disabled = !locked;

        const ic = els.btnDeploy.querySelector('.btn-icon');
        const tx = els.btnDeploy.querySelector('.btn-text');
        const ar = els.btnDeploy.querySelector('.btn-arrow');
        if (locked) {
            if (ic) ic.outerHTML = '<span class="spin btn-icon"></span>';
            if (tx) tx.textContent = '正在部署...';
            if (ar) ar.style.display = 'none';
        } else {
            const spin = els.btnDeploy.querySelector('.spin');
            if (spin) spin.outerHTML = '<span class="btn-icon">🚀</span>';
            const tx2 = els.btnDeploy.querySelector('.btn-text');
            if (tx2) tx2.textContent = '开始一键部署';
            const ar2 = els.btnDeploy.querySelector('.btn-arrow');
            if (ar2) ar2.style.display = '';
        }
    }

    // ------------------------------------------------------------------
    // Done message
    // ------------------------------------------------------------------

    function copyText(text) {
        const fallback = () => {
            const ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (_) {}
            ta.remove();
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(fallback);
        } else {
            fallback();
        }
    }

    function scrollToElement(el) {
        if (!el) return;
        try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (_) {}
    }

    function formPayload() {
        const fd = new FormData(els.form);
        return {
            deployCard: String(fd.get('deployCard') || '').trim(),
            host: String(fd.get('host') || '').trim(),
            port: Number(fd.get('port') || 22),
            sitePort: Number(fd.get('sitePort') || 80),
            username: String(fd.get('username') || '').trim(),
            password: String(fd.get('password') || ''),
            deployMode: String(fd.get('deployMode') || 'clean'),
            cardAdminPassword: String(fd.get('cardAdminPassword') || ''),
            opsInstallCode: String(fd.get('opsInstallCode') || '').trim(),
            opsServerName: String(fd.get('opsServerName') || '_').trim(),
            opsDbRootPassword: String(fd.get('opsDbRootPassword') || ''),
            opsDbPassword: String(fd.get('opsDbPassword') || ''),
            opsAdminUser: String(fd.get('opsAdminUser') || 'admin').trim(),
            opsAdminPassword: String(fd.get('opsAdminPassword') || ''),
        };
    }

    // ------------------------------------------------------------------
    // Form submit
    // ------------------------------------------------------------------
    els.form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!socket || !socket.connected) {
            appendLog('error', 'Socket 未连接，请刷新页面');
            return;
        }

        const payload = formPayload();
        if (!payload.deployCard) {
            appendLog('error', '请先填写部署卡密');
            return;
        }
        if (!payload.host || !payload.username || !payload.password) {
            appendLog('error', '请完整填写服务器信息');
            return;
        }
        if (!Number.isInteger(payload.sitePort) || payload.sitePort < 1 || payload.sitePort > 65535) {
            appendLog('error', '网站访问端口不合法');
            return;
        }
        if (payload.sitePort === 8888 || payload.sitePort === 9999) {
            appendLog('error', '网站访问端口不能使用 8888 或 9999');
            return;
        }
        if (payload.deployMode === 'card' && payload.cardAdminPassword.length < 6) {
            appendLog('error', '卡密版需要设置至少 6 位后台管理密码');
            return;
        }
        if (payload.deployMode === 'ops') {
            if (!payload.opsInstallCode) {
                appendLog('error', '运营版需要填写安装授权码');
                return;
            }
            if (!payload.opsAdminUser) {
                appendLog('error', '运营版需要填写后台用户名，默认可填 admin');
                return;
            }
            if (payload.opsAdminPassword && payload.opsAdminPassword.length < 6) {
                appendLog('error', '后台密码至少 6 位；不想手动设置可留空自动生成');
                return;
            }
        }

        // Reset UI
        renderSteps();
        setProgress(0, '准备中...');
        clearLog();
        if (els.logEmpty && els.logEmpty.parentNode) els.logEmpty.remove();
        els.doneMsg.style.display = 'none';
        lockForm(true);

        appendLog('info', '发起部署，部署卡密将在成功后自动失效，版本 ' + (modeLabels[payload.deployMode] || payload.deployMode));
        socket.emit('deploy:start', payload);
    });

    els.btnCancel.addEventListener('click', () => {
        if (!socket) return;
        socket.emit('deploy:cancel');
        appendLog('warn', '已发送取消请求，等待当前命令结束...');
    });

    if (els.btnClearServer) {
        els.btnClearServer.addEventListener('click', () => {
            if (!socket || !socket.connected) {
                appendLog('error', 'Socket 未连接，请刷新页面');
                return;
            }
            const payload = formPayload();
            if (!payload.host || !payload.username || !payload.password) {
                appendLog('error', '清理前请先填写服务器地址、SSH 用户名和密码');
                return;
            }
            const typed = prompt('此操作会清理该服务器上的本项目服务、站点目录、Nginx 配置和运营版数据库。请输入目标服务器地址确认：');
            if (typed !== payload.host) {
                appendLog('warn', '清理已取消：确认地址不一致');
                return;
            }
            if (!confirm('再次确认：只清理本项目数据，但仍建议确认目标服务器无误。是否开始清理？')) {
                appendLog('warn', '清理已取消');
                return;
            }

            renderSteps();
            setProgress(0, '准备清理...');
            clearLog();
            if (els.logEmpty && els.logEmpty.parentNode) els.logEmpty.remove();
            els.doneMsg.style.display = 'none';
            lockForm(true);
            appendLog('warn', '开始清理服务器项目数据，清理完成后可重新部署');
            socket.emit('clear:start', payload);
        });
    }

    els.btnTestConn.addEventListener('click', () => {
        if (!socket || !socket.connected) {
            appendLog('error', 'Socket 未连接，请刷新页面');
            return;
        }
        const fd = new FormData(els.form);
        const payload = {
            deployCard: String(fd.get('deployCard') || '').trim(),
            host: String(fd.get('host') || '').trim(),
            port: Number(fd.get('port') || 22),
            sitePort: Number(fd.get('sitePort') || 80),
            username: String(fd.get('username') || '').trim(),
            password: String(fd.get('password') || ''),
        };
        if (!payload.deployCard) {
            els.testResult.className = 'test-result fail';
            els.testResult.innerHTML = '❌ 请先填写部署卡密';
            els.testResult.style.display = 'block';
            return;
        }
        if (!payload.host || !payload.username || !payload.password) {
            els.testResult.className = 'test-result fail';
            els.testResult.innerHTML = '❌ 请先填写完整的服务器信息';
            els.testResult.style.display = 'block';
            return;
        }
        els.btnTestConn.disabled = true;
        els.btnTestConn.textContent = '⏳ 测试中...';
        els.testResult.style.display = 'none';
        socket.emit('test:connect', payload);
    });
    els.btnClearLog.addEventListener('click', clearLog);
    els.btnDownloadLog.addEventListener('click', downloadLog);


    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.copy-command');
        if (!btn) return;
        const target = document.getElementById(btn.dataset.copyTarget || '');
        if (!target) return;
        copyText(target.textContent.trim());
        const old = btn.textContent;
        btn.classList.add('copied');
        btn.textContent = '已复制';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.textContent = old;
        }, 1400);
    });

    // Password toggle
    document.addEventListener('click', (e) => {
        const t = e.target.closest('.pw-toggle');
        if (!t) return;
        const input = t.parentElement.querySelector('input');
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        // 切换图标简易版：眼睛 / 眼睛-划掉
        if (input.type === 'text' && els.pwIcon) {
            els.pwIcon.innerHTML =
                '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
                '<path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.62 18.62 0 0 1-2.16 3.19"/>' +
                '<path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>';
        } else if (els.pwIcon) {
            els.pwIcon.innerHTML =
                '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }
    });

    // 提示：若以 file:// 打开则提醒
    if (window.location.protocol === 'file:') {
        document.body.innerHTML =
            '<div style="max-width:500px;margin:100px auto;padding:30px;' +
            'background:#1a1f3a;color:#e6ecff;border-radius:12px;' +
            'font-family:sans-serif;line-height:1.7;">' +
            '<h2 style="margin-bottom:12px;color:#ff8a8a">⚠️ 打开方式错误</h2>' +
            '<p>请通过 <code style="background:#000;padding:2px 8px;border-radius:4px;color:#6fd3ff">http://localhost:3000/</code> 访问，' +
            '不要直接双击打开 index.html 文件。</p>' +
            '<p style="margin-top:12px;color:#8f99b8">在命令行执行：<br><code style="background:#000;padding:4px 10px;border-radius:4px;display:inline-block;margin-top:8px;color:#78f1bb">npm start</code></p>' +
            '</div>';
    }
})();
