(function () {
    "use strict";

    var API_URL = "api/auth.php";
    var TOKEN_KEY = "wz_card_auth_token";
    var state = {
        card: null,
        started: false,
        checking: false
    };

    function $(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
            return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" })[ch];
        });
    }

    function getToken() {
        return localStorage.getItem(TOKEN_KEY) || "";
    }

    function setToken(token) {
        if (token) {
            localStorage.setItem(TOKEN_KEY, token);
        } else {
            localStorage.removeItem(TOKEN_KEY);
        }
    }

    function injectStyle() {
        if ($("cardAuthStyle")) return;

        var style = document.createElement("style");
        style.id = "cardAuthStyle";
        style.textContent = [
            ".card-auth-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(5,9,20,.9);backdrop-filter:blur(14px);}",
            ".card-auth-box{width:min(430px,100%);background:linear-gradient(135deg,rgba(35,45,62,.98),rgba(13,19,31,.98));border:1px solid rgba(74,158,255,.34);border-radius:14px;padding:26px;box-shadow:0 26px 80px rgba(0,0,0,.6),0 0 42px rgba(74,158,255,.18);color:#eef5ff;}",
            ".card-auth-box h2{margin:0 0 6px;font-size:22px;color:#fff;}",
            ".card-auth-box p{margin:0 0 20px;color:#9fb2d2;font-size:14px;line-height:1.7;}",
            ".card-auth-label{display:block;margin-bottom:8px;color:#cfe0ff;font-size:13px;}",
            ".card-auth-input{width:100%;height:46px;border-radius:10px;border:1px solid rgba(74,158,255,.35);background:rgba(8,12,22,.82);color:#fff;padding:0 13px;font-size:15px;outline:none;text-transform:uppercase;}",
            ".card-auth-input:focus{border-color:#64b5ff;box-shadow:0 0 0 3px rgba(74,158,255,.18);}",
            ".card-auth-btn{width:100%;height:46px;margin-top:14px;border:0;border-radius:10px;background:linear-gradient(135deg,#4a9eff,#257fe5);color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 10px 24px rgba(74,158,255,.28);}",
            ".card-auth-btn:disabled{opacity:.58;cursor:not-allowed;}",
            ".card-auth-msg{min-height:20px;margin-top:12px;color:#ff9d9d;font-size:13px;}",
            ".card-auth-admin{display:block;margin-top:16px;color:#8bc2ff;text-align:center;text-decoration:none;font-size:13px;}",
            ".card-info-grid{display:grid;gap:9px;font-size:13px;color:#dce8ff;}",
            ".card-info-row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.07);}",
            ".card-info-row span:first-child{color:#92a8c8;}",
            ".card-info-row span:last-child{text-align:right;word-break:break-all;}",
            ".card-logout-btn{width:100%;margin-top:13px;padding:10px 12px;border:1px solid rgba(255,84,84,.45);border-radius:9px;background:rgba(255,68,68,.13);color:#ffb0b0;cursor:pointer;}",
            ".card-auth-badge{display:inline-flex;align-items:center;gap:7px;margin-left:12px;padding:5px 9px;border:1px solid rgba(22,183,119,.34);border-radius:999px;background:rgba(22,183,119,.12);color:#9ff0ca;font-size:12px;vertical-align:middle;-webkit-text-fill-color:#9ff0ca;}",
            "@media(max-width:768px){.card-auth-box{padding:22px}.card-auth-badge{display:flex;width:max-content;margin:8px 0 0}.card-info-row{display:block}.card-info-row span:last-child{display:block;text-align:left;margin-top:3px}}"
        ].join("");
        document.head.appendChild(style);
    }

    function api(action, options) {
        options = options || {};
        var token = getToken();
        var headers = { "Content-Type": "application/json" };
        if (token) {
            headers.Authorization = "Bearer " + token;
        }

        return fetch(API_URL + "?action=" + encodeURIComponent(action), {
            method: options.method || "GET",
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
            cache: "no-store"
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, message: "接口返回格式错误" };
            }).then(function (data) {
                if (!res.ok && !data.message) {
                    data.message = "请求失败";
                }
                return data;
            });
        });
    }

    function formatRemain(seconds) {
        seconds = Math.max(0, Number(seconds) || 0);
        var days = Math.floor(seconds / 86400);
        var hours = Math.floor((seconds % 86400) / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);

        if (days > 0) return days + "天 " + hours + "小时";
        if (hours > 0) return hours + "小时 " + minutes + "分钟";
        return minutes + "分钟";
    }

    function row(label, value) {
        return "<div class=\"card-info-row\"><span>" + escapeHtml(label) + "</span><span>" + escapeHtml(value || "-") + "</span></div>";
    }

    function renderCardPanel(card) {
        if (!card) return;

        var sidebar = document.querySelector(".sidebar");
        if (sidebar) {
            var panel = $("cardInfoPanel");
            if (!panel) {
                panel = document.createElement("div");
                panel.id = "cardInfoPanel";
                panel.className = "panel";
                sidebar.insertBefore(panel, sidebar.firstChild);
            }

            panel.innerHTML = [
                "<h3>卡密信息</h3>",
                "<div class=\"card-info-grid\">",
                row("当前卡密", card.key),
                row("到期时间", card.expire_at),
                row("剩余时间", formatRemain(card.remaining_seconds)),
                row("首次使用", card.used_at || "未记录"),
                row("最后登录", card.last_login_at || "未记录"),
                row("登录 IP", card.last_ip || "未记录"),
                "</div>",
                "<button class=\"card-logout-btn\" id=\"cardLogoutBtn\">退出登录</button>"
            ].join("");
            $("cardLogoutBtn").onclick = logout;
        }

        var title = document.querySelector(".header h1");
        if (title && !$("cardAuthBadge")) {
            var badge = document.createElement("span");
            badge.id = "cardAuthBadge";
            badge.className = "card-auth-badge";
            title.appendChild(badge);
        }
        if ($("cardAuthBadge")) {
            $("cardAuthBadge").textContent = "授权剩余 " + formatRemain(card.remaining_seconds);
        }
    }

    function showLogin(message) {
        injectStyle();

        if ($("cardAuthOverlay")) {
            if (message && $("cardAuthMsg")) {
                $("cardAuthMsg").textContent = message;
            }
            return;
        }

        var overlay = document.createElement("div");
        overlay.id = "cardAuthOverlay";
        overlay.className = "card-auth-overlay";
        overlay.innerHTML = [
            "<div class=\"card-auth-box\">",
            "<h2>卡密登录</h2>",
            "<p>请输入后台生成的卡密，授权通过后进入雷达页面。</p>",
            "<label class=\"card-auth-label\" for=\"cardAuthInput\">卡密</label>",
            "<input class=\"card-auth-input\" id=\"cardAuthInput\" autocomplete=\"off\" placeholder=\"WZ-XXXX-XXXX-XXXX-XXXX\">",
            "<button class=\"card-auth-btn\" id=\"cardAuthSubmit\">登录</button>",
            "<div class=\"card-auth-msg\" id=\"cardAuthMsg\"></div>",
            "<a class=\"card-auth-admin\" href=\"admin/\" target=\"_blank\" rel=\"noopener\">进入后台管理</a>",
            "</div>"
        ].join("");

        document.body.appendChild(overlay);
        $("cardAuthMsg").textContent = message || "";
        $("cardAuthSubmit").onclick = login;
        $("cardAuthInput").focus();
        $("cardAuthInput").addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                login();
            }
        });
    }

    function hideLogin() {
        var overlay = $("cardAuthOverlay");
        if (overlay) {
            overlay.remove();
        }
    }

    function login() {
        var input = $("cardAuthInput");
        var button = $("cardAuthSubmit");
        var msg = $("cardAuthMsg");
        var key = input ? input.value.trim().toUpperCase() : "";

        if (!key) {
            msg.textContent = "请输入卡密";
            return;
        }

        button.disabled = true;
        button.textContent = "校验中...";
        msg.textContent = "";

        api("login", { method: "POST", body: { key: key } }).then(function (data) {
            if (!data.ok) {
                throw new Error(data.message || "登录失败");
            }
            setToken(data.token);
            state.card = data.card;
            hideLogin();
            renderCardPanel(data.card);
            startAppOnce();
        }).catch(function (err) {
            msg.textContent = err.message || "登录失败，请确认网站已启用 PHP";
        }).finally(function () {
            button.disabled = false;
            button.textContent = "登录";
        });
    }

    function logout() {
        api("logout", { method: "POST", body: {} }).finally(function () {
            setToken("");
            location.reload();
        });
    }

    function startAppOnce() {
        if (state.started) return;
        state.started = true;

        if (typeof window.__cardOriginalInitApp !== "function" && typeof window.initApp === "function" && window.initApp !== ensureAuthorized) {
            window.__cardOriginalInitApp = window.initApp;
        }

        if (typeof window.__cardOriginalInitApp === "function") {
            window.__cardOriginalInitApp();
        }
    }

    function ensureAuthorized() {
        installGate();
        injectStyle();

        if (!getToken()) {
            showLogin();
            return;
        }

        if (state.checking) return;
        state.checking = true;

        api("me").then(function (data) {
            if (!data.ok) {
                throw new Error(data.message || "请先登录");
            }
            state.card = data.card;
            hideLogin();
            renderCardPanel(data.card);
            startAppOnce();
        }).catch(function (err) {
            setToken("");
            showLogin(err.message || "登录已失效，请重新输入卡密");
        }).finally(function () {
            state.checking = false;
        });
    }

    function installGate() {
        if (window.__cardAuthInstalled) return;

        window.__cardAuthInstalled = true;
        window.__cardOriginalInitApp = window.initApp;
        window.initApp = ensureAuthorized;

        setInterval(function () {
            if (!getToken() || !state.started) return;

            api("me").then(function (data) {
                if (data.ok) {
                    state.card = data.card;
                    renderCardPanel(data.card);
                } else {
                    setToken("");
                    location.reload();
                }
            }).catch(function () {});
        }, 60000);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", installGate);
    } else {
        installGate();
    }

    window.CardAuth = {
        current: function () { return state.card; },
        logout: logout,
        refresh: ensureAuthorized
    };
})();
