#!/usr/bin/env bash
# WZRY Space cloud updater.
# Run as root in SSH to pull the latest public source and redeploy the web app.

set -Eeuo pipefail

SOURCE="${REPO_SOURCE:-gitee}"
BRANCH="${REPO_BRANCH:-main}"
SRC_DIR="${SRC_DIR:-/opt/wzry-space-src}"
SITE_DIR="${SITE_DIR:-/www/wwwroot/wzry-space}"
LICENSE_SERVER="${LICENSE_SERVER:-http://ld.llqq520.xyz}"
LICENSE_GROUP_URL="${LICENSE_GROUP_URL:-https://qm.qq.com/q/VcaTE1qumQ}"
LICENSE_HOST="${LICENSE_HOST:-}"
LICENSE_PERMANENT="${LICENSE_PERMANENT:-0}"
REPO_URL="${REPO_URL:-}"
SKIP_DB_MIGRATE=0
SKIP_SERVICE_RESTART=0
SOURCE_SET=0
BRANCH_SET=0
SRC_DIR_SET=0
SITE_DIR_SET=0

red() { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[1;33m%s\033[0m\n' "$*"; }
blue() { printf '\033[0;36m%s\033[0m\n' "$*"; }
die() { red "错误：$*"; exit 1; }

usage() {
    cat <<'EOF'
用法：
  bash scripts/cloud-update.sh
  bash scripts/cloud-update.sh --source github

参数：
  --source github|gitee        更新源，默认 gitee
  --repo-url URL               自定义 Git 仓库地址
  --branch NAME                分支，默认 main
  --src-dir DIR                源码目录，默认 /opt/wzry-space-src
  --site-dir DIR               网站目录，默认 /www/wwwroot/wzry-space
  --license-server URL         授权服务器地址
  --license-host HOST          授权绑定 IP/域名
  --license-group-url URL      未授权提示里的群链接
  --license-permanent          写入本地永久授权
  --skip-db-migrate            跳过数据库升级 SQL
  --skip-service-restart       跳过 Nginx/Java 服务重启
  -h, --help                   查看帮助
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source) SOURCE="${2:-}"; SOURCE_SET=1; shift 2 ;;
        --source=*) SOURCE="${1#*=}"; SOURCE_SET=1; shift ;;
        --repo-url) REPO_URL="${2:-}"; shift 2 ;;
        --repo-url=*) REPO_URL="${1#*=}"; shift ;;
        --branch) BRANCH="${2:-}"; BRANCH_SET=1; shift 2 ;;
        --branch=*) BRANCH="${1#*=}"; BRANCH_SET=1; shift ;;
        --src-dir) SRC_DIR="${2:-}"; SRC_DIR_SET=1; shift 2 ;;
        --src-dir=*) SRC_DIR="${1#*=}"; SRC_DIR_SET=1; shift ;;
        --site-dir) SITE_DIR="${2:-}"; SITE_DIR_SET=1; shift 2 ;;
        --site-dir=*) SITE_DIR="${1#*=}"; SITE_DIR_SET=1; shift ;;
        --license-server) LICENSE_SERVER="${2:-}"; shift 2 ;;
        --license-server=*) LICENSE_SERVER="${1#*=}"; shift ;;
        --license-host) LICENSE_HOST="${2:-}"; shift 2 ;;
        --license-host=*) LICENSE_HOST="${1#*=}"; shift ;;
        --license-group-url) LICENSE_GROUP_URL="${2:-}"; shift 2 ;;
        --license-group-url=*) LICENSE_GROUP_URL="${1#*=}"; shift ;;
        --license-permanent) LICENSE_PERMANENT=1; shift ;;
        --skip-db-migrate) SKIP_DB_MIGRATE=1; shift ;;
        --skip-service-restart) SKIP_SERVICE_RESTART=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "未知参数：$1" ;;
    esac
done

[[ "$(id -u)" -eq 0 ]] || die "请使用 root 执行，例如：sudo bash scripts/cloud-update.sh"

read_install_receipt() {
    local receipt="/root/wzry-space-install.env"
    [[ -f "$receipt" ]] || return 0
    local key value
    while IFS='=' read -r key value; do
        case "$key" in
            SOURCE) [[ "$SOURCE_SET" == "0" && -n "$value" ]] && SOURCE="$value" ;;
            BRANCH) [[ "$BRANCH_SET" == "0" && -n "$value" ]] && BRANCH="$value" ;;
            SRC_DIR) [[ "$SRC_DIR_SET" == "0" && -n "$value" ]] && SRC_DIR="$value" ;;
            SITE_DIR) [[ "$SITE_DIR_SET" == "0" && -n "$value" ]] && SITE_DIR="$value" ;;
            LICENSE_SERVER) [[ -n "$value" ]] && LICENSE_SERVER="$value" ;;
            LICENSE_GROUP_URL) [[ -n "$value" ]] && LICENSE_GROUP_URL="$value" ;;
            LICENSE_HOST) [[ -n "$value" ]] && LICENSE_HOST="$value" ;;
            LICENSE_PERMANENT) [[ -n "$value" ]] && LICENSE_PERMANENT="$value" ;;
        esac
    done < "$receipt"
}

repo_url() {
    if [[ -n "$REPO_URL" ]]; then
        printf '%s\n' "$REPO_URL"
        return
    fi
    case "$SOURCE" in
        github) printf 'https://github.com/a534686350/wzry-space.git\n' ;;
        gitee) printf 'https://gitee.com/hl515/wzry-space.git\n' ;;
        *) die "更新源只能是 github 或 gitee" ;;
    esac
}

ensure_tools() {
    green "正在检查更新工具..."
    if command -v git >/dev/null 2>&1 && command -v rsync >/dev/null 2>&1; then
        return
    fi
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update
        apt-get install -y git rsync ca-certificates
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y git rsync ca-certificates
    elif command -v yum >/dev/null 2>&1; then
        yum install -y git rsync ca-certificates
    else
        die "缺少 git/rsync，且未识别包管理器"
    fi
}

sync_source() {
    green "正在通过 SSH 远程更新源码..."
    local url
    url="$(repo_url)"
    if [[ -d "$SRC_DIR/.git" ]]; then
        git -C "$SRC_DIR" remote set-url origin "$url"
        git -C "$SRC_DIR" fetch --prune origin "$BRANCH"
        git -C "$SRC_DIR" checkout -B "$BRANCH" "origin/$BRANCH"
    else
        [[ ! -e "$SRC_DIR" ]] || die "$SRC_DIR 已存在，但不是 Git 仓库"
        mkdir -p "$(dirname "$SRC_DIR")"
        git clone --branch "$BRANCH" --depth 1 "$url" "$SRC_DIR"
    fi
    green "源码更新完成"
}

deploy_web_files() {
    green "正在更新前台和后台文件..."
    local web_package="$SRC_DIR/网页前后台"
    [[ -d "$web_package" ]] || die "未找到发布包目录：$web_package"
    [[ -d "$SITE_DIR" ]] || mkdir -p "$SITE_DIR"

    rsync -a --delete \
        --exclude 'auth/config.php' \
        --exclude 'auth/install.lock' \
        --exclude 'logs/' \
        --exclude '*.log' \
        --exclude '.git/' \
        --exclude '.well-known/' \
        --exclude 'apk/ALinRadar-v6-1.2.apk' \
        "$web_package"/ "$SITE_DIR"/

    for file in install-services.sh start-server.sh restore-whitelist.sh ws-whitelist-helper.sh setup-whitelist-iptables.sh nginx-whitelist-ws.conf; do
        if [[ -f "$SRC_DIR/APP/$file" ]]; then
            cp -f "$SRC_DIR/APP/$file" "$SITE_DIR/$file"
        fi
    done

    chmod +x "$SITE_DIR"/*.sh 2>/dev/null || true
    install_license_runtime
    green "前台和后台文件更新完成"
}

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

sed_replacement_escape() {
    printf '%s' "$1" | sed 's/[\\&|]/\\&/g'
}

license_host_value() {
    if [[ -n "$LICENSE_HOST" ]]; then
        printf '%s\n' "$LICENSE_HOST"
        return
    fi
    hostname -I 2>/dev/null | awk '{print $1}'
}

inject_license_tag() {
    local file="$1"
    [[ -f "$file" ]] || return 0
    grep -q 'radar-license.js' "$file" && return 0
    if grep -qi '</body>' "$file"; then
        sed -i '0,/<\/body>/I{s#</body>#<script src="/radar-license.js?v=20260611"></script>\n</body>#}' "$file"
    else
        printf '\n<script src="/radar-license.js?v=20260611"></script>\n' >> "$file"
    fi
}

install_license_runtime() {
    local license_file host server group permanent
    license_file="$SITE_DIR/radar-license.js"
    host="$(license_host_value)"
    server="${LICENSE_SERVER%/}"
    group="$LICENSE_GROUP_URL"
    permanent="$LICENSE_PERMANENT"

    if [[ "$permanent" == "1" ]]; then
        cat > "$license_file" <<'EOF'
(function(){'use strict';
var host="__RADAR_LICENSE_HOST__";
window.RadarServerLicense={check:function(){return Promise.resolve(true);},isAuthorized:function(){return true;},last:function(){return {permanent:true,local:true};},showBlock:function(){}};
try{localStorage.setItem('wzry.server.license.permanent.'+host,'1');}catch(e){}
})();
EOF
        sed -i "s|__RADAR_LICENSE_HOST__|$(sed_replacement_escape "$(json_escape "$host")")|g" "$license_file"
        chmod 644 "$license_file"
        inject_license_tag "$SITE_DIR/index.html"
        inject_license_tag "$SITE_DIR/index.php"
        green "服务器已保留本地永久授权，不依赖远程授权服务器"
        return
    fi

    cat > "$license_file" <<'EOF'
(function(){'use strict';
var cfg={serverUrl:"__RADAR_LICENSE_SERVER__",host:"__RADAR_LICENSE_HOST__",mode:"ops",permanent:__RADAR_LICENSE_PERMANENT__,groupUrl:"__RADAR_LICENSE_GROUP_URL__",groupName:"王者雷达共享开黑组队群"};
var nativeInitApp=null,nativeInitWebSocket=null,authorized=!!cfg.permanent,trialOpen=false,checking=null,lastResult=null;
var baseKey='wzry.server.license.'+(cfg.host||location.hostname||'server'),storageKey=baseKey+'.permanent',trialKey=baseKey+'.trialStart',trialMs=24*60*60*1000;
function readPermanent(){try{return localStorage.getItem(storageKey)==='1';}catch(e){return false;}}
function savePermanent(){try{localStorage.setItem(storageKey,'1');}catch(e){}}
function trialStart(){var now=Date.now();try{var old=Number(localStorage.getItem(trialKey)||0);if(!old){localStorage.setItem(trialKey,String(now));return now;}return old;}catch(e){return now;}}
function trialLeft(){return Math.max(0,trialMs-(Date.now()-trialStart()));}
if(readPermanent())authorized=true;
function esc(s){return String(s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function closeSocket(){try{if(window.socket&&window.socket.readyState!==3)window.socket.close();}catch(e){}}
function removeNotice(){var old=document.getElementById('radarLicenseNotice');if(old)old.remove();}
function showTrialNotice(message){trialOpen=true;var hours=Math.max(0,Math.ceil(trialLeft()/3600000));var old=document.getElementById('radarLicenseNotice');if(!old){old=document.createElement('div');old.id='radarLicenseNotice';old.style.cssText='position:fixed;left:12px;right:12px;top:12px;z-index:2147483000;background:rgba(15,23,42,.92);border:1px solid rgba(251,191,36,.55);border-radius:10px;color:#f8fafc;padding:10px 12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;font-size:13px;line-height:1.5;box-shadow:0 10px 28px rgba(0,0,0,.28);';document.body.appendChild(old);}old.innerHTML='当前服务器未授权，已开启 1 天试用，剩余约 <b style="color:#fde68a">'+hours+'</b> 小时。'+esc(message||'试用结束前请联系管理员授权。')+' <a href="'+esc(cfg.groupUrl||'#')+'" target="_blank" rel="noopener" style="color:#7dd3fc;font-weight:700">加入群聊找授权码</a>';return true;}
function block(message){authorized=false;closeSocket();try{if(typeof window.updateConnectionStatus==='function')window.updateConnectionStatus('error','服务器未授权');}catch(e){}try{if(typeof window.showError==='function')window.showError('当前服务器未授权，请找管理员开通授权',10000);}catch(e){}var old=document.getElementById('radarLicenseBlocker');if(old)old.remove();var box=document.createElement('div');box.id='radarLicenseBlocker';box.style.cssText='position:fixed;inset:0;z-index:2147483647;background:rgba(4,8,18,.92);display:flex;align-items:center;justify-content:center;padding:18px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;color:#e8eefc;';box.innerHTML='<div style="width:min(520px,94vw);background:#111827;border:1px solid rgba(96,165,250,.35);border-radius:14px;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.45);text-align:center"><h2 style="margin:0 0 12px;font-size:24px;color:#fef3c7">试用已结束，需要授权</h2><p style="margin:0 0 18px;line-height:1.7;color:#cbd5e1">'+esc(message||'未授权试用期为 1 天，试用结束后需要授权才能继续使用。')+'</p><p style="margin:0 0 20px;line-height:1.7;color:#dbeafe">请点击链接加入群聊【'+esc(cfg.groupName||'王者雷达共享开黑组队群')+'】，找我获取授权码。</p><a href="'+esc(cfg.groupUrl||'#')+'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border-radius:8px;background:#38bdf8;color:#06111f;font-weight:800;text-decoration:none">加入群聊找授权码</a></div>';document.body.appendChild(box);return false;}
function allow(data){authorized=true;trialOpen=false;lastResult=data||{};var old=document.getElementById('radarLicenseBlocker');if(old)old.remove();removeNotice();if(data&&data.permanent)savePermanent();return true;}
function allowTrial(data){lastResult=data||{};if(trialLeft()>0)return showTrialNotice(data&&data.message?data.message:'试用结束前请联系管理员授权。');return block(data&&data.message?data.message:'未授权试用已结束，需要授权后才能继续使用。');}
function checkLicense(force){if(authorized&&!force)return Promise.resolve(true);if(cfg.permanent||readPermanent())return Promise.resolve(allow({permanent:true,local:true}));if(checking)return checking;var url=String(cfg.serverUrl||'').replace(/\/+$/,'')+'/api/license/check?host='+encodeURIComponent(cfg.host||location.hostname||'')+'&domain='+encodeURIComponent(location.hostname||'')+'&mode='+encodeURIComponent(cfg.mode||'all')+'&_='+(Date.now());checking=fetch(url,{cache:'no-store'}).then(function(r){return r.json();}).then(function(data){checking=null;if(data&&data.authorized)return allow(data);return allowTrial(data||{});}).catch(function(){checking=null;return allowTrial({message:'授权服务器暂时连接失败，试用期内仍可使用；请尽快联系管理员授权。'});});return checking;}
function gated(fn,ctx,args){if(authorized||trialOpen||cfg.permanent||readPermanent()||trialLeft()>0)return fn.apply(ctx,args);checkLicense(false).then(function(ok){if(ok)return fn.apply(ctx,args);});return undefined;}
function wrap(){if(typeof window.initApp==='function'&&!window.initApp.__licenseWrapped){nativeInitApp=window.initApp;window.initApp=function(){return gated(nativeInitApp,this,arguments);};window.initApp.__licenseWrapped=true;}if(typeof window.initWebSocket==='function'&&!window.initWebSocket.__licenseWrapped){nativeInitWebSocket=window.initWebSocket;window.initWebSocket=function(){return gated(nativeInitWebSocket,this,arguments);};window.initWebSocket.__licenseWrapped=true;}}
wrap();setTimeout(wrap,0);document.addEventListener('DOMContentLoaded',function(){wrap();checkLicense(false);});if(!cfg.permanent){setInterval(function(){checkLicense(true);},60000);setInterval(function(){if(!authorized&&trialLeft()<=0)block('未授权试用已结束，需要授权后才能继续使用。');else if(!authorized)showTrialNotice('试用结束前请联系管理员授权。');},300000);}
window.RadarServerLicense={check:checkLicense,isAuthorized:function(){return authorized;},last:function(){return lastResult;},showBlock:block};
})();
EOF
    sed -i \
        -e "s|__RADAR_LICENSE_SERVER__|$(sed_replacement_escape "$(json_escape "$server")")|g" \
        -e "s|__RADAR_LICENSE_HOST__|$(sed_replacement_escape "$(json_escape "$host")")|g" \
        -e "s|__RADAR_LICENSE_GROUP_URL__|$(sed_replacement_escape "$(json_escape "$group")")|g" \
        -e "s|__RADAR_LICENSE_PERMANENT__|$( [[ "$permanent" == "1" ]] && printf true || printf false )|g" \
        "$license_file"
    chmod 644 "$license_file"
    inject_license_tag "$SITE_DIR/index.html"
    inject_license_tag "$SITE_DIR/index.php"
    green "服务器授权校验已更新，未授权可试用 1 天"
}

load_db_env() {
    local cfg="$SITE_DIR/auth/config.php"
    [[ -f "$cfg" ]] || return 1
    eval "$(php -r "
        \$c = require '$cfg';
        echo 'DB_HOST=' . escapeshellarg(\$c['db_host'] ?? '127.0.0.1') . \"\n\";
        echo 'DB_PORT=' . escapeshellarg(\$c['db_port'] ?? '3306') . \"\n\";
        echo 'DB_NAME=' . escapeshellarg(\$c['db_name'] ?? '') . \"\n\";
        echo 'DB_USER=' . escapeshellarg(\$c['db_user'] ?? '') . \"\n\";
        echo 'DB_PASS=' . escapeshellarg(\$c['db_password'] ?? '') . \"\n\";
    " 2>/dev/null)"
    [[ -n "${DB_NAME:-}" && -n "${DB_USER:-}" ]]
}

mysql_app() {
    MYSQL_PWD="${DB_PASS:-}" mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" "$@"
}

import_sql_file() {
    local file="$1"
    [[ -f "$file" ]] || return 0
    mysql_app --force < "$file"
}

migrate_database() {
    if [[ "$SKIP_DB_MIGRATE" == "1" ]]; then
        yellow "已跳过数据库升级"
        return
    fi
    if ! command -v php >/dev/null 2>&1 || ! command -v mysql >/dev/null 2>&1; then
        yellow "缺少 php 或 mysql 命令，跳过数据库升级"
        return
    fi
    if ! load_db_env; then
        yellow "未找到可用数据库配置，跳过数据库升级"
        return
    fi

    green "正在执行数据库升级 SQL..."
    import_sql_file "$SITE_DIR/auth/install_extra.sql"
    import_sql_file "$SITE_DIR/auth/upgrade_agent.sql"
    import_sql_file "$SITE_DIR/auth/upgrade_all.sql"
    import_sql_file "$SRC_DIR/APP/auth/upgrade_whitelist.sql"
    green "数据库升级完成"
}

restart_services() {
    if [[ "$SKIP_SERVICE_RESTART" == "1" ]]; then
        yellow "已跳过服务重启"
        return
    fi

    green "正在重载 Nginx 和重启 Java 服务..."
    if command -v nginx >/dev/null 2>&1; then
        nginx -t
        systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
    fi
    if systemctl list-unit-files home-server.service >/dev/null 2>&1; then
        systemctl restart home-server.service || true
    fi
    if systemctl list-unit-files restore-whitelist.service >/dev/null 2>&1; then
        systemctl restart restore-whitelist.service || true
    fi
    green "服务处理完成"
}

server_host() {
    local host ip receipt="/root/wzry-space-install.env"
    host=""
    if [[ -f "$receipt" ]]; then
        host="$(grep '^SERVER_NAME=' "$receipt" | head -n1 | cut -d= -f2- || true)"
    fi
    if [[ -z "$host" || "$host" == "_" ]]; then
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
        [[ -n "$ip" ]] || ip="服务器IP"
        printf '%s\n' "$ip"
    else
        printf '%s\n' "$host"
    fi
}

latest_apk_name() {
    find "$SITE_DIR/apk" -maxdepth 1 -type f -name 'ALinRadar-v*.apk' -printf '%f\n' 2>/dev/null | sort -V | tail -n 1
}

print_summary() {
    local host app_file commit
    host="$(server_host)"
    app_file="$(latest_apk_name)"
    commit="$(git -C "$SRC_DIR" rev-parse --short HEAD 2>/dev/null || true)"

    green "========================================"
    green "  远程更新完成"
    green "========================================"
    printf '当前源码版本：      %s\n' "${commit:-unknown}"
    printf '你的前台地址是：    http://%s/\n' "$host"
    printf '你的后台地址是：    http://%s/admin/\n' "$host"
    if [[ -n "$app_file" ]]; then
        printf 'APP 下载路径是：    http://%s/apk/%s\n' "$host" "$app_file"
    fi
    printf '网站目录是：        %s\n' "$SITE_DIR"
    printf '源码目录是：        %s\n' "$SRC_DIR"
}

blue "========================================"
blue "  王者荣耀空间 - SSH 远程更新"
blue "========================================"
read_install_receipt
ensure_tools
sync_source
deploy_web_files
migrate_database
restart_services
print_summary
