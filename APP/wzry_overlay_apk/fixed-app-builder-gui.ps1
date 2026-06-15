Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$ErrorActionPreference = 'Stop'

$ProjectDir = (Split-Path -Parent $MyInvocation.MyCommand.Path).Trim()
$Gradle = (Join-Path $ProjectDir 'tools\gradle-8.10.2\bin\gradle.bat').Trim()
$JavaHome = (Join-Path $ProjectDir 'tools\jdk17\jdk-17.0.19+10').Trim()
$AndroidSdk = (Join-Path $ProjectDir 'tools\android-sdk').Trim()
$ReleaseDir = Join-Path $ProjectDir 'app\build\outputs\apk\release'
$ReleaseApk = Join-Path $ReleaseDir 'app-release.apk'
$ReleaseMetadata = Join-Path $ReleaseDir 'output-metadata.json'
$BuilderConfigFile = Join-Path $ProjectDir 'apk-builder-config.json'
$script:BuildProcess = $null
$script:LogFile = ''
$script:ErrLogFile = ''
$script:BuildScriptFile = ''
$script:BuildReceiptFile = ''
$script:LastLogText = ''
$script:LastErrLogText = ''
$script:OutputApk = ''
$script:BuildOptions = $null
$script:LastTarget = $null

function Get-DefaultOutputDir {
    $desktop = [Environment]::GetFolderPath('Desktop')
    if (-not [string]::IsNullOrWhiteSpace($desktop) -and (Test-Path -LiteralPath $desktop)) {
        return (Join-Path $desktop 'ALinRadar-APK')
    }
    return (Join-Path $ProjectDir 'generated-apks')
}

$DefaultOutputDir = Get-DefaultOutputDir
$DefaultNoBackendApiBase = 'http://101.200.36.103'
$DefaultAppName = 'LD'
$DefaultHomeTitle = 'LD'
$DefaultPanelTitle = 'LD'
$DefaultPanelChannel = '天青色等烟雨'
$DefaultPanelStatus = '游戏进程已初始化'
$DefaultVersionName = 'v6.1.18'
$DefaultVersionCode = '17'
$DefaultBuyUrl = ''
$DefaultIconPath = 'D:\hl\ALinRadar\图标\ChatGPT Image 2026年6月15日 11_05_51.png'

function Get-ReleaseApkPath {
    if (Test-Path -LiteralPath $ReleaseMetadata) {
        try {
            $metadata = Get-Content -LiteralPath $ReleaseMetadata -Raw | ConvertFrom-Json
            $outputFile = [string]$metadata.elements[0].outputFile
            if (-not [string]::IsNullOrWhiteSpace($outputFile)) {
                $candidate = Join-Path $ReleaseDir $outputFile
                if (Test-Path -LiteralPath $candidate) { return $candidate }
            }
        }
        catch {
        }
    }

    if (Test-Path -LiteralPath $ReleaseApk) { return $ReleaseApk }

    if (Test-Path -LiteralPath $ReleaseDir) {
        $latest = Get-ChildItem -LiteralPath $ReleaseDir -Filter '*.apk' -File -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($latest) { return $latest.FullName }
    }
    return ''
}

function Test-DirectoryWritable {
    param([string]$Path)
    New-Item -ItemType Directory -Force -Path $Path | Out-Null
    $probe = Join-Path $Path ('.write-test-' + [Guid]::NewGuid().ToString('N') + '.tmp')
    try {
        Set-Content -LiteralPath $probe -Value 'ok' -Encoding UTF8
    }
    finally {
        if (Test-Path -LiteralPath $probe) {
            Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue
        }
    }
}

function Test-RequiredTools {
    $missing = New-Object System.Collections.Generic.List[string]
    if (-not (Test-Path -LiteralPath $Gradle)) { $missing.Add("Gradle not found: $Gradle") }
    if (-not (Test-Path -LiteralPath $JavaHome)) { $missing.Add("JDK not found: $JavaHome") }
    if (-not (Test-Path -LiteralPath $AndroidSdk)) { $missing.Add("Android SDK not found: $AndroidSdk") }
    if ($missing.Count -gt 0) {
        throw ($missing -join "`r`n")
    }
}

function Normalize-Target {
    param(
        [string]$HostValue,
        [string]$PortValue,
        [string]$SchemeValue
    )

    $raw = ($HostValue -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($raw)) {
        throw '请输入服务器 IP 或域名。'
    }

    $scheme = ($SchemeValue -as [string]).Trim().ToLowerInvariant()
    if ($scheme -ne 'http' -and $scheme -ne 'https') { $scheme = 'http' }

    $hasScheme = $raw -match '^[a-zA-Z][a-zA-Z0-9+.-]*://'
    $hasExplicitPort = $raw -match ':\d+(?:/|$)'
    if (-not $hasScheme) {
        $raw = $scheme + '://' + $raw.TrimEnd('/')
    }

    try {
        $builder = [System.UriBuilder]$raw
    }
    catch {
        throw '服务器地址格式不正确。'
    }

    if ([string]::IsNullOrWhiteSpace($builder.Host)) {
        throw '服务器地址格式不正确。'
    }

    if (-not $hasExplicitPort -and -not [string]::IsNullOrWhiteSpace($PortValue)) {
        $port = 0
        if (-not [int]::TryParse($PortValue.Trim(), [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
            throw '网页端口必须是 1 到 65535。'
        }
        if (($builder.Scheme -eq 'http' -and $port -eq 80) -or ($builder.Scheme -eq 'https' -and $port -eq 443)) {
            $builder.Port = -1
        }
        else {
            $builder.Port = $port
        }
    }

    $path = ($builder.Path -as [string]).Trim('/')
    $apiBase = $builder.Uri.GetLeftPart([System.UriPartial]::Authority).TrimEnd('/')
    if (-not [string]::IsNullOrWhiteSpace($path)) {
        $apiBase = ($apiBase + '/' + $path).TrimEnd('/')
    }

    $serverHost = $builder.Host.ToLowerInvariant()
    $portPart = if ($builder.Port -gt 0) { '-' + $builder.Port } else { '' }
    $safeHost = (($serverHost + $portPart) -replace '[^a-zA-Z0-9._-]', '_').Trim('_')
    if ([string]::IsNullOrWhiteSpace($safeHost)) { $safeHost = 'server' }

    [pscustomobject]@{
        ApiBase = $apiBase
        Host = $serverHost
        SafeHost = $safeHost
    }
}

function Get-SafeFilePart {
    param([string]$Value)
    $safe = (($Value -as [string]).Trim() -replace '[\\/:*?"<>|]', '_').Trim()
    if ([string]::IsNullOrWhiteSpace($safe)) { return 'ALinRadar' }
    return $safe
}

function Escape-CmdValue {
    param([string]$Value)
    return (($Value -as [string]).Replace('%', '%%'))
}

function ConvertTo-PowerShellLiteral {
    param([string]$Value)
    return "'" + (($Value -as [string]).Replace("'", "''")) + "'"
}

function Get-OptionalUrl {
    param(
        [string]$Value,
        [string]$Label
    )
    $url = ($Value -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($url)) { return '' }
    if ($url.Contains('"')) {
        throw "$Label 不能包含英文双引号。"
    }
    if ($url -notmatch '^[a-zA-Z][a-zA-Z0-9+.-]*://') {
        throw "$Label 必须以 http:// 或 https:// 开头。"
    }
    try {
        $uri = [System.Uri]$url
        if ([string]::IsNullOrWhiteSpace($uri.Host)) { throw 'bad uri' }
    }
    catch {
        throw "$Label 格式不正确。"
    }
    return $url
}

function Get-SelectedLoginMode {
    if ($null -eq $loginModeInput -or $null -eq $loginModeInput.SelectedItem) { return 'auto' }
    $text = [string]$loginModeInput.SelectedItem
    if ($text.StartsWith('带后台')) { return 'backend' }
    if ($text.StartsWith('不带后台')) { return 'frontend' }
    if ($text.StartsWith('强制后台')) { return 'backend' }
    if ($text.StartsWith('强制免登录')) { return 'frontend' }
    return 'auto'
}

function Get-WebSocketPort {
    $port = 0
    $raw = ($wsPortInput.Text -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($raw)) { $raw = '8888' }
    if (-not [int]::TryParse($raw, [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
        throw 'WebSocket 端口必须是 1 到 65535。'
    }
    return $port
}

function Get-AllowCustomServerIp {
    return ($null -ne $allowCustomServerIpInput -and $allowCustomServerIpInput.Checked)
}

function Get-BrandingOptions {
    $appName = ($appNameInput.Text -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($appName)) {
        throw '请输入 APP 名称。'
    }
    if ($appName.Contains('"')) {
        throw 'APP 名称不能包含英文双引号。'
    }
    $homeTitle = ($homeTitleInput.Text -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($homeTitle)) {
        $homeTitle = $appName
    }
    if ($homeTitle.Contains('"')) {
        throw '主页标题不能包含英文双引号。'
    }
    $panelTitle = ($panelTitleInput.Text -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($panelTitle)) { $panelTitle = $homeTitle }
    if ($panelTitle.Contains('"')) {
        throw '面板标题不能包含英文双引号。'
    }
    $panelChannel = ($panelChannelInput.Text -as [string]).Trim()
    if ($panelChannel.Contains('"')) {
        throw '频道文字不能包含英文双引号。'
    }
    $panelStatus = ($panelStatusInput.Text -as [string]).Trim()
    if ($panelStatus.Contains('"')) {
        throw '状态文字不能包含英文双引号。'
    }

    $iconFile = ($iconInput.Text -as [string]).Trim()
    if (-not [string]::IsNullOrWhiteSpace($iconFile)) {
        if ($iconFile.Contains('"')) {
            throw '图标路径不能包含英文双引号。'
        }
        if (-not (Test-Path -LiteralPath $iconFile)) {
            throw '图标图片不存在，请重新选择。'
        }
    }

    [pscustomobject]@{
        AppName = $appName
        HomeTitle = $homeTitle
        PanelTitle = $panelTitle
        PanelChannel = $panelChannel
        PanelStatus = $panelStatus
        IconFile = $iconFile
        SafeName = Get-SafeFilePart $appName
    }
}

function Get-VersionOptions {
    $versionName = ($versionNameInput.Text -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($versionName)) {
        throw '请输入版本名，例如 v6.1.12。'
    }
    if ($versionName.Contains('"')) {
        throw '版本名不能包含英文双引号。'
    }

    $versionCode = 0
    $rawCode = ($versionCodeInput.Text -as [string]).Trim()
    if (-not [int]::TryParse($rawCode, [ref]$versionCode) -or $versionCode -lt 1) {
        throw '版本号必须是大于 0 的整数，并且新版本要比后台旧版本更大。'
    }

    [pscustomobject]@{
        VersionName = $versionName
        VersionCode = $versionCode
        SafeName = Get-SafeFilePart $versionName
    }
}

function Append-Log {
    param([string]$Text)
    if ([string]::IsNullOrEmpty($Text)) { return }
    $logBox.AppendText($Text)
    if (-not $Text.EndsWith("`r`n")) {
        $logBox.AppendText("`r`n")
    }
    $logBox.SelectionStart = $logBox.TextLength
    $logBox.ScrollToCaret()
}

function Set-Building {
    param([bool]$IsBuilding)
    $buildButton.Enabled = -not $IsBuilding
    $testButton.Enabled = -not $IsBuilding
    $hostInput.Enabled = -not $IsBuilding
    $portInput.Enabled = -not $IsBuilding
    $wsPortInput.Enabled = -not $IsBuilding
    $schemeInput.Enabled = -not $IsBuilding
    $loginModeInput.Enabled = -not $IsBuilding
    $allowCustomServerIpInput.Enabled = -not $IsBuilding
    $buyUrlInput.Enabled = -not $IsBuilding
    $appNameInput.Enabled = -not $IsBuilding
    $homeTitleInput.Enabled = -not $IsBuilding
    $panelTitleInput.Enabled = -not $IsBuilding
    $panelChannelInput.Enabled = -not $IsBuilding
    $panelStatusInput.Enabled = -not $IsBuilding
    $versionNameInput.Enabled = -not $IsBuilding
    $versionCodeInput.Enabled = -not $IsBuilding
    $iconInput.Enabled = -not $IsBuilding
    $browseIconButton.Enabled = -not $IsBuilding
    $outputInput.Enabled = -not $IsBuilding
    $browseButton.Enabled = -not $IsBuilding
    $progress.Style = if ($IsBuilding) { 'Marquee' } else { 'Blocks' }
    $progress.Value = if ($IsBuilding) { 0 } else { 0 }
}

function Refresh-Log {
    if ($script:LogFile -and (Test-Path -LiteralPath $script:LogFile)) {
        $text = Get-Content -LiteralPath $script:LogFile -Raw -ErrorAction SilentlyContinue
        if ($null -eq $text) { $text = '' }
        if ($text.Length -gt $script:LastLogText.Length) {
            $delta = $text.Substring($script:LastLogText.Length)
            $script:LastLogText = $text
            Append-Log $delta
        }
    }
    if ($script:ErrLogFile -and (Test-Path -LiteralPath $script:ErrLogFile)) {
        $text = Get-Content -LiteralPath $script:ErrLogFile -Raw -ErrorAction SilentlyContinue
        if ($null -eq $text) { $text = '' }
        if ($text.Length -gt $script:LastErrLogText.Length) {
            $delta = $text.Substring($script:LastErrLogText.Length)
            $script:LastErrLogText = $text
            Append-Log $delta
        }
    }
}

function Update-Preview {
    try {
        $target = Normalize-Target $hostInput.Text $portInput.Text $schemeInput.SelectedItem
        $wsPort = Get-WebSocketPort
        $selectedLoginMode = Get-SelectedLoginMode
        $apiBase = if ($selectedLoginMode -eq 'frontend') { $DefaultNoBackendApiBase } else { $target.ApiBase }
        $customIpText = if (Get-AllowCustomServerIp) { '开' } else { '关' }
        $script:LastTarget = $target
        $previewLabel.Text = "后台/API：$apiBase    房间/雷达WS：ws://$($target.Host):$wsPort/ws    APP改IP：$customIpText"
        $previewLabel.ForeColor = [System.Drawing.Color]::FromArgb(28, 99, 52)
    }
    catch {
        $script:LastTarget = $null
        $previewLabel.Text = $_.Exception.Message
        $previewLabel.ForeColor = [System.Drawing.Color]::FromArgb(180, 47, 47)
    }
}

function Invoke-HttpGetText {
    param([string]$Url)

    $request = [System.Net.HttpWebRequest][System.Net.WebRequest]::Create($Url)
    $request.Method = 'GET'
    $request.Timeout = 10000
    $request.UserAgent = 'ALinRadar-Local-Apk-Builder'
    $response = $request.GetResponse()
    try {
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        $body = $reader.ReadToEnd()
        [pscustomobject]@{
            StatusCode = [int]$response.StatusCode
            StatusDescription = $response.StatusDescription
            Body = $body
        }
    }
    finally {
        $response.Close()
    }
}

function Test-BackendAvailability {
    param(
        [pscustomobject]$Target,
        [bool]$WriteLog = $true
    )

    $webUrl = $Target.ApiBase.TrimEnd('/') + '/'
    $apiUrl = $Target.ApiBase.TrimEnd('/') + '/api/index.php?module=app_remote_config&action=public'
    $result = [pscustomobject]@{
        WebOk = $false
        HasBackend = $false
        LoginMode = 'frontend'
        Message = ''
        ApiPreview = ''
    }

    if ($WriteLog) {
        Append-Log ''
        Append-Log "正在测试网页：$webUrl"
    }
    $web = Invoke-HttpGetText $webUrl
    $result.WebOk = $true
    if ($WriteLog) {
        Append-Log "网页 HTTP $($web.StatusCode) $($web.StatusDescription)，内容大小：$($web.Body.Length) 字符"
        Append-Log "正在检测后台 API：$apiUrl"
    }

    try {
        $api = Invoke-HttpGetText $apiUrl
        $body = $api.Body
        if ($body.Length -gt 260) { $body = $body.Substring(0, 260) + '...' }
        $result.ApiPreview = $body
        if ($WriteLog) {
            Append-Log "API HTTP $($api.StatusCode) $($api.StatusDescription)"
            Append-Log $body
        }
        $apiJson = $api.Body | ConvertFrom-Json -ErrorAction Stop
        if ($null -ne $apiJson.code -or $null -ne $apiJson.data) {
            $result.HasBackend = $true
            $result.LoginMode = 'backend'
            $result.Message = '检测到后台 API，APK 将走后台登录流程。'
        }
    }
    catch {
        if ($WriteLog) {
            Append-Log "未检测到有效后台 API：$($_.Exception.Message)"
        }
    }

    if (-not $result.HasBackend) {
        $result.LoginMode = 'frontend'
        $result.Message = '未检测到后台 API，APK 将免登录进入主页。'
    }
    return $result
}

function Invoke-BackendTest {
    try {
        $target = Normalize-Target $hostInput.Text $portInput.Text $schemeInput.SelectedItem
        $check = Test-BackendAvailability $target $true
        if ($check.HasBackend) {
            $statusLabel.Text = "测试通过：有后台，APK 走登录流程。"
            [System.Windows.Forms.MessageBox]::Show("网页可访问，并检测到后台 API。`r`nAPK 会走后台登录流程。", 'ALinRadar APK 打包器', 'OK', 'Information') | Out-Null
        }
        else {
            $statusLabel.Text = "测试通过：仅前端，APK 免登录。"
            [System.Windows.Forms.MessageBox]::Show("网页可访问，但未检测到有效后台 API。`r`nAPK 会免登录进入主页。", 'ALinRadar APK 打包器', 'OK', 'Information') | Out-Null
        }
        Append-Log "检测结果：$($check.Message)"
    }
    catch {
        $statusLabel.Text = '测试失败，请检查 IP、端口或服务器状态。'
        Append-Log "测试失败：$($_.Exception.Message)"
        [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, 'ALinRadar APK 打包器', 'OK', 'Warning') | Out-Null
    }
}

function Finish-Build {
    $timer.Stop()
    Refresh-Log
    Set-Building $false

    $exitCode = $script:BuildProcess.ExitCode
    $buildLog = ''
    if ($script:LogFile -and (Test-Path -LiteralPath $script:LogFile)) {
        $buildLog = Get-Content -LiteralPath $script:LogFile -Raw -ErrorAction SilentlyContinue
        if ($null -eq $buildLog) { $buildLog = '' }
    }
    $gradleSucceeded = $buildLog -match 'BUILD SUCCESSFUL'
    $builtApk = Get-ReleaseApkPath
    if ($exitCode -ne 0 -and -not ($gradleSucceeded -and (-not [string]::IsNullOrWhiteSpace($builtApk)) -and (Test-Path -LiteralPath $builtApk))) {
        $statusLabel.Text = "打包失败，退出码：$exitCode"
        [System.Windows.Forms.MessageBox]::Show("打包失败，请查看下方日志。", 'ALinRadar APK 打包器', 'OK', 'Error') | Out-Null
        return
    }
    if ([string]::IsNullOrWhiteSpace($builtApk) -or -not (Test-Path -LiteralPath $builtApk)) {
        $statusLabel.Text = '打包结束，但没有找到 release APK。'
        [System.Windows.Forms.MessageBox]::Show('没有找到 release APK。', 'ALinRadar APK 打包器', 'OK', 'Error') | Out-Null
        return
    }

    Copy-Item -LiteralPath $builtApk -Destination $script:OutputApk -Force
    $releaseNamedApk = Join-Path $ReleaseDir ([System.IO.Path]::GetFileName($script:OutputApk))
    if ($releaseNamedApk -ne $builtApk) {
        Copy-Item -LiteralPath $builtApk -Destination $releaseNamedApk -Force
    }
    $hash = Get-FileHash -Algorithm SHA256 -LiteralPath $script:OutputApk
    $statusLabel.Text = "完成：$script:OutputApk"
    $openApkButton.Enabled = $true
    Append-Log ''
    if ($exitCode -ne 0 -and $gradleSucceeded) {
        Append-Log "提示：Gradle 已显示 BUILD SUCCESSFUL，已忽略 PowerShell 对 stderr 提示的误判。"
    }
    Append-Log "APK 已生成：$script:OutputApk"
    Append-Log "release 目录副本：$releaseNamedApk"
    Append-Log "SHA256: $($hash.Hash)"
    [System.Windows.Forms.MessageBox]::Show("APK 打包成功。`r`n$script:OutputApk", 'ALinRadar APK 打包器', 'OK', 'Information') | Out-Null
}

function Start-Build {
    try {
        $logBox.Clear()
        Test-RequiredTools
        $target = Normalize-Target $hostInput.Text $portInput.Text $schemeInput.SelectedItem
        $branding = Get-BrandingOptions
        $version = Get-VersionOptions
        $wsPort = Get-WebSocketPort
        $allowCustomServerIp = Get-AllowCustomServerIp
        $buyUrl = Get-OptionalUrl $buyUrlInput.Text '购买卡密链接'
        $selectedLoginMode = Get-SelectedLoginMode
        $loginMode = $selectedLoginMode
        if ($selectedLoginMode -eq 'auto') {
            Append-Log ''
            Append-Log '登录模式为自动检测，正在判断目标是否有后台...'
            $backendCheck = Test-BackendAvailability $target $true
            $loginMode = $backendCheck.LoginMode
            Append-Log "自动检测结果：$($backendCheck.Message)"
        }
        $apkApiBase = if ($loginMode -eq 'frontend') { $DefaultNoBackendApiBase } else { $target.ApiBase }
        $outputDir = $outputInput.Text.Trim()
        if ([string]::IsNullOrWhiteSpace($outputDir)) {
            $outputDir = $DefaultOutputDir
        }
        New-Item -ItemType Directory -Force -Path $outputDir | Out-Null

        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $namePart = $branding.SafeName + "-" + $version.SafeName + "-" + $target.SafeHost + "-" + $stamp
        $script:OutputApk = Join-Path $outputDir ($namePart + ".apk")
        $script:LogFile = Join-Path $outputDir ("build-" + $namePart + ".log")
        $script:ErrLogFile = Join-Path $outputDir ("build-" + $namePart + ".err.log")
        $script:BuildScriptFile = Join-Path $outputDir ("build-" + $namePart + ".ps1")
        $script:LastLogText = ''
        $script:LastErrLogText = ''
        $openApkButton.Enabled = $false

        $gradleArgs = New-Object System.Collections.Generic.List[string]
        $gradleArgs.Add('--no-daemon') | Out-Null
        $gradleArgs.Add('clean') | Out-Null
        $gradleArgs.Add('assembleRelease') | Out-Null
        $gradleArgs.Add('-PAPP_API_BASE=' + $apkApiBase) | Out-Null
        $gradleArgs.Add('-PAPP_SERVER_HOST=' + $target.Host) | Out-Null
        $gradleArgs.Add('-PAPP_SERVER_PORT=' + $wsPort) | Out-Null
        $gradleArgs.Add('-PAPP_FIXED=true') | Out-Null
        $gradleArgs.Add('-PAPP_ALLOW_CUSTOM_SERVER_IP=' + $allowCustomServerIp.ToString().ToLowerInvariant()) | Out-Null
        $gradleArgs.Add('-PAPP_LOGIN_MODE=' + $loginMode) | Out-Null
        $gradleArgs.Add('-PAPP_NAME=' + $branding.AppName) | Out-Null
        $gradleArgs.Add('-PAPP_HOME_TITLE=' + $branding.HomeTitle) | Out-Null
        $gradleArgs.Add('-PAPP_PANEL_TITLE=' + $branding.PanelTitle) | Out-Null
        $gradleArgs.Add('-PAPP_PANEL_CHANNEL=' + $branding.PanelChannel) | Out-Null
        $gradleArgs.Add('-PAPP_PANEL_STATUS=' + $branding.PanelStatus) | Out-Null
        $gradleArgs.Add('-PAPP_VERSION_NAME=' + $version.VersionName) | Out-Null
        $gradleArgs.Add('-PAPP_VERSION_CODE=' + $version.VersionCode) | Out-Null
        if (-not [string]::IsNullOrWhiteSpace($branding.IconFile)) {
            $gradleArgs.Add('-PAPP_ICON_FILE=' + $branding.IconFile) | Out-Null
        }
        if (-not [string]::IsNullOrWhiteSpace($buyUrl)) {
            $gradleArgs.Add('-PAPP_BUY_URL=' + $buyUrl) | Out-Null
        }

        $buildScriptLines = New-Object System.Collections.Generic.List[string]
        $buildScriptLines.Add('$ErrorActionPreference = ''Continue''') | Out-Null
        $buildScriptLines.Add('$PSNativeCommandUseErrorActionPreference = $false') | Out-Null
        $buildScriptLines.Add('$env:JAVA_HOME = ' + (ConvertTo-PowerShellLiteral $JavaHome)) | Out-Null
        $buildScriptLines.Add('$env:ANDROID_HOME = ' + (ConvertTo-PowerShellLiteral $AndroidSdk)) | Out-Null
        $buildScriptLines.Add('$env:ANDROID_SDK_ROOT = ' + (ConvertTo-PowerShellLiteral $AndroidSdk)) | Out-Null
        $buildScriptLines.Add('$env:PATH = $env:JAVA_HOME + ''\bin;'' + $env:ANDROID_HOME + ''\platform-tools;'' + $env:PATH') | Out-Null
        $buildScriptLines.Add('Set-Location -LiteralPath ' + (ConvertTo-PowerShellLiteral $ProjectDir)) | Out-Null
        $buildScriptLines.Add('$gradle = ' + (ConvertTo-PowerShellLiteral $Gradle)) | Out-Null
        $buildScriptLines.Add('$outLog = ' + (ConvertTo-PowerShellLiteral $script:LogFile)) | Out-Null
        $buildScriptLines.Add('$errLog = ' + (ConvertTo-PowerShellLiteral $script:ErrLogFile)) | Out-Null
        $buildScriptLines.Add('$gradleArgs = @(') | Out-Null
        for ($i = 0; $i -lt $gradleArgs.Count; $i++) {
            $suffix = if ($i -lt $gradleArgs.Count - 1) { ',' } else { '' }
            $buildScriptLines.Add('    ' + (ConvertTo-PowerShellLiteral $gradleArgs[$i]) + $suffix) | Out-Null
        }
        $buildScriptLines.Add(')') | Out-Null
        $buildScriptLines.Add('try {') | Out-Null
        $buildScriptLines.Add('    & $gradle @gradleArgs 1>> $outLog 2>> $errLog') | Out-Null
        $buildScriptLines.Add('    $code = $LASTEXITCODE') | Out-Null
        $buildScriptLines.Add('    if ($null -eq $code) { $code = 0 }') | Out-Null
        $buildScriptLines.Add('}') | Out-Null
        $buildScriptLines.Add('catch {') | Out-Null
        $buildScriptLines.Add('    $_ | Out-File -LiteralPath $errLog -Append -Encoding utf8') | Out-Null
        $buildScriptLines.Add('    $code = if ($null -ne $LASTEXITCODE) { $LASTEXITCODE } else { 1 }') | Out-Null
        $buildScriptLines.Add('}') | Out-Null
        $buildScriptLines.Add('exit $code') | Out-Null
        $utf8Bom = New-Object System.Text.UTF8Encoding($true)
        [System.IO.File]::WriteAllLines($script:BuildScriptFile, $buildScriptLines, $utf8Bom)

        Append-Log "APP 名称：$($branding.AppName)"
        Append-Log "主页标题：$($branding.HomeTitle)"
        Append-Log "面板标题：$($branding.PanelTitle)"
        Append-Log "频道文字：$($branding.PanelChannel)"
        Append-Log "状态文字：$($branding.PanelStatus)"
        Append-Log "APP 版本：$($version.VersionName) ($($version.VersionCode))"
        if (-not [string]::IsNullOrWhiteSpace($branding.IconFile)) {
            Append-Log "APP 图标：$($branding.IconFile)"
        }
        else {
            Append-Log 'APP 图标：使用默认图标'
        }
        Append-Log "APK 后台/API：$apkApiBase"
        Append-Log "房间/雷达 WebSocket：ws://$($target.Host):$wsPort/ws"
        Append-Log "APP 内自定义房间服务器 IP：$(if ($allowCustomServerIp) { '开启' } else { '关闭' })"
        Append-Log "是否带后台：$loginMode（backend=带后台登录/API，frontend=不带后台免登录）"
        if (-not [string]::IsNullOrWhiteSpace($buyUrl)) {
            Append-Log "购买卡密链接兜底：$buyUrl"
        }
        Append-Log "输出 APK：$script:OutputApk"
        Append-Log "打包日志：$script:LogFile"
        Append-Log "打包脚本：$script:BuildScriptFile"
        Append-Log '正在本机调用 Gradle 打包，请稍等...'
        $statusLabel.Text = '正在本机打包 release APK...'
        Set-Building $true

        $psArgs = @(
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script:BuildScriptFile
        )
        $script:BuildProcess = Start-Process -FilePath 'powershell.exe' -ArgumentList $psArgs -WorkingDirectory $ProjectDir -WindowStyle Hidden -PassThru
        $timer.Start()
    }
    catch {
        Set-Building $false
        $statusLabel.Text = $_.Exception.Message
        [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, 'ALinRadar APK 打包器', 'OK', 'Error') | Out-Null
    }
}

$form = New-Object System.Windows.Forms.Form
$form.Text = 'ALinRadar 本地 APK 打包器'
$form.Size = New-Object System.Drawing.Size(860, 850)
$form.StartPosition = 'CenterScreen'
$form.MinimumSize = New-Object System.Drawing.Size(840, 810)
$form.BackColor = [System.Drawing.Color]::FromArgb(238, 244, 252)

$title = New-Object System.Windows.Forms.Label
$title.Text = 'LD APK 打包工作台'
$title.Font = New-Object System.Drawing.Font('Microsoft YaHei UI', 18, [System.Drawing.FontStyle]::Bold)
$title.ForeColor = [System.Drawing.Color]::White
$title.AutoSize = $true
$title.Location = New-Object System.Drawing.Point(22, 18)
$form.Controls.Add($title)

$subtitle = New-Object System.Windows.Forms.Label
$subtitle.Text = '配置服务器、品牌信息与图标，本机一键生成加固混淆 Release APK。'
$subtitle.Font = New-Object System.Drawing.Font('Microsoft YaHei UI', 9)
$subtitle.ForeColor = [System.Drawing.Color]::FromArgb(203, 213, 225)
$subtitle.AutoSize = $true
$subtitle.Location = New-Object System.Drawing.Point(24, 50)
$form.Controls.Add($subtitle)

$schemeLabel = New-Object System.Windows.Forms.Label
$schemeLabel.Text = '协议'
$schemeLabel.Location = New-Object System.Drawing.Point(24, 88)
$schemeLabel.Size = New-Object System.Drawing.Size(80, 24)
$form.Controls.Add($schemeLabel)

$schemeInput = New-Object System.Windows.Forms.ComboBox
$schemeInput.DropDownStyle = 'DropDownList'
$schemeInput.Items.Add('http') | Out-Null
$schemeInput.Items.Add('https') | Out-Null
$schemeInput.SelectedIndex = 0
$schemeInput.Location = New-Object System.Drawing.Point(112, 85)
$schemeInput.Size = New-Object System.Drawing.Size(88, 26)
$schemeInput.Add_SelectedIndexChanged({ Update-Preview })
$form.Controls.Add($schemeInput)

$hostLabel = New-Object System.Windows.Forms.Label
$hostLabel.Text = '服务器IP/域名'
$hostLabel.Location = New-Object System.Drawing.Point(214, 88)
$hostLabel.Size = New-Object System.Drawing.Size(120, 24)
$form.Controls.Add($hostLabel)

$hostInput = New-Object System.Windows.Forms.TextBox
$hostInput.Location = New-Object System.Drawing.Point(340, 85)
$hostInput.Size = New-Object System.Drawing.Size(210, 26)
$hostInput.Text = '101.200.36.103'
$hostInput.Add_TextChanged({ Update-Preview })
$form.Controls.Add($hostInput)

$portLabel = New-Object System.Windows.Forms.Label
$portLabel.Text = '网页端口'
$portLabel.Location = New-Object System.Drawing.Point(565, 88)
$portLabel.Size = New-Object System.Drawing.Size(64, 24)
$form.Controls.Add($portLabel)

$portInput = New-Object System.Windows.Forms.TextBox
$portInput.Location = New-Object System.Drawing.Point(628, 85)
$portInput.Size = New-Object System.Drawing.Size(55, 26)
$portInput.Text = '80'
$portInput.Add_TextChanged({ Update-Preview })
$form.Controls.Add($portInput)

$wsPortLabel = New-Object System.Windows.Forms.Label
$wsPortLabel.Text = 'WS端口'
$wsPortLabel.Location = New-Object System.Drawing.Point(690, 88)
$wsPortLabel.Size = New-Object System.Drawing.Size(54, 24)
$form.Controls.Add($wsPortLabel)

$wsPortInput = New-Object System.Windows.Forms.TextBox
$wsPortInput.Location = New-Object System.Drawing.Point(742, 85)
$wsPortInput.Size = New-Object System.Drawing.Size(48, 26)
$wsPortInput.Text = '8888'
$wsPortInput.Add_TextChanged({ Update-Preview })
$form.Controls.Add($wsPortInput)

$previewLabel = New-Object System.Windows.Forms.Label
$previewLabel.Location = New-Object System.Drawing.Point(24, 122)
$previewLabel.Size = New-Object System.Drawing.Size(790, 24)
$previewLabel.Font = New-Object System.Drawing.Font('Microsoft YaHei UI', 9, [System.Drawing.FontStyle]::Bold)
$previewLabel.ForeColor = [System.Drawing.Color]::FromArgb(4, 120, 87)
$form.Controls.Add($previewLabel)

$loginModeLabel = New-Object System.Windows.Forms.Label
$loginModeLabel.Text = '是否带后台'
$loginModeLabel.Location = New-Object System.Drawing.Point(24, 158)
$loginModeLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($loginModeLabel)

$loginModeInput = New-Object System.Windows.Forms.ComboBox
$loginModeInput.DropDownStyle = 'DropDownList'
$loginModeInput.Items.Add('带后台（登录/API）') | Out-Null
$loginModeInput.Items.Add('不带后台（免登录）') | Out-Null
$loginModeInput.SelectedIndex = 0
$loginModeInput.Location = New-Object System.Drawing.Point(130, 155)
$loginModeInput.Size = New-Object System.Drawing.Size(220, 26)
$loginModeInput.Add_SelectedIndexChanged({ Update-Preview })
$form.Controls.Add($loginModeInput)

$allowCustomServerIpInput = New-Object System.Windows.Forms.CheckBox
$allowCustomServerIpInput.Text = 'APP可改IP'
$allowCustomServerIpInput.Location = New-Object System.Drawing.Point(724, 156)
$allowCustomServerIpInput.Size = New-Object System.Drawing.Size(110, 24)
$allowCustomServerIpInput.Checked = $false
$allowCustomServerIpInput.Add_CheckedChanged({ Update-Preview })
$form.Controls.Add($allowCustomServerIpInput)

$loginModeHint = New-Object System.Windows.Forms.Label
$loginModeHint.Text = '不带后台时管理/API 固定走 101.200.36.103；房间数据仍走输入IP的8888。'
$loginModeHint.Location = New-Object System.Drawing.Point(366, 158)
$loginModeHint.Size = New-Object System.Drawing.Size(350, 24)
$loginModeHint.ForeColor = [System.Drawing.Color]::FromArgb(82, 94, 112)
$form.Controls.Add($loginModeHint)

$buyUrlLabel = New-Object System.Windows.Forms.Label
$buyUrlLabel.Text = '购买链接'
$buyUrlLabel.Location = New-Object System.Drawing.Point(24, 194)
$buyUrlLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($buyUrlLabel)

$buyUrlInput = New-Object System.Windows.Forms.TextBox
$buyUrlInput.Location = New-Object System.Drawing.Point(130, 191)
$buyUrlInput.Size = New-Object System.Drawing.Size(626, 26)
$buyUrlInput.Text = $DefaultBuyUrl
$form.Controls.Add($buyUrlInput)

$buyUrlHint = New-Object System.Windows.Forms.Label
$buyUrlHint.Text = '可选。后台没有配置购买卡密链接时，APP 使用这里打包进去的兜底链接。'
$buyUrlHint.Location = New-Object System.Drawing.Point(130, 222)
$buyUrlHint.Size = New-Object System.Drawing.Size(626, 22)
$buyUrlHint.ForeColor = [System.Drawing.Color]::FromArgb(82, 94, 112)
$form.Controls.Add($buyUrlHint)

$appNameLabel = New-Object System.Windows.Forms.Label
$appNameLabel.Text = 'APP名称'
$appNameLabel.Location = New-Object System.Drawing.Point(24, 262)
$appNameLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($appNameLabel)

$appNameInput = New-Object System.Windows.Forms.TextBox
$appNameInput.Location = New-Object System.Drawing.Point(130, 259)
$appNameInput.Size = New-Object System.Drawing.Size(285, 26)
$appNameInput.Text = $DefaultAppName
$form.Controls.Add($appNameInput)

$homeTitleLabel = New-Object System.Windows.Forms.Label
$homeTitleLabel.Text = '主页标题'
$homeTitleLabel.Location = New-Object System.Drawing.Point(24, 298)
$homeTitleLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($homeTitleLabel)

$homeTitleInput = New-Object System.Windows.Forms.TextBox
$homeTitleInput.Location = New-Object System.Drawing.Point(130, 295)
$homeTitleInput.Size = New-Object System.Drawing.Size(285, 26)
$homeTitleInput.Text = $DefaultHomeTitle
$form.Controls.Add($homeTitleInput)

$panelTitleLabel = New-Object System.Windows.Forms.Label
$panelTitleLabel.Text = '面板标题'
$panelTitleLabel.Location = New-Object System.Drawing.Point(428, 298)
$panelTitleLabel.Size = New-Object System.Drawing.Size(70, 24)
$form.Controls.Add($panelTitleLabel)

$panelTitleInput = New-Object System.Windows.Forms.TextBox
$panelTitleInput.Location = New-Object System.Drawing.Point(502, 295)
$panelTitleInput.Size = New-Object System.Drawing.Size(254, 26)
$panelTitleInput.Text = $DefaultPanelTitle
$form.Controls.Add($panelTitleInput)

$panelChannelLabel = New-Object System.Windows.Forms.Label
$panelChannelLabel.Text = '频道文字'
$panelChannelLabel.Location = New-Object System.Drawing.Point(24, 334)
$panelChannelLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($panelChannelLabel)

$panelChannelInput = New-Object System.Windows.Forms.TextBox
$panelChannelInput.Location = New-Object System.Drawing.Point(130, 331)
$panelChannelInput.Size = New-Object System.Drawing.Size(626, 26)
$panelChannelInput.Text = $DefaultPanelChannel
$form.Controls.Add($panelChannelInput)

$panelStatusLabel = New-Object System.Windows.Forms.Label
$panelStatusLabel.Text = '状态文字'
$panelStatusLabel.Location = New-Object System.Drawing.Point(24, 370)
$panelStatusLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($panelStatusLabel)

$panelStatusInput = New-Object System.Windows.Forms.TextBox
$panelStatusInput.Location = New-Object System.Drawing.Point(130, 367)
$panelStatusInput.Size = New-Object System.Drawing.Size(626, 26)
$panelStatusInput.Text = $DefaultPanelStatus
$form.Controls.Add($panelStatusInput)

$versionNameLabel = New-Object System.Windows.Forms.Label
$versionNameLabel.Text = '版本名'
$versionNameLabel.Location = New-Object System.Drawing.Point(428, 262)
$versionNameLabel.Size = New-Object System.Drawing.Size(54, 24)
$form.Controls.Add($versionNameLabel)

$versionNameInput = New-Object System.Windows.Forms.TextBox
$versionNameInput.Location = New-Object System.Drawing.Point(482, 259)
$versionNameInput.Size = New-Object System.Drawing.Size(92, 26)
$versionNameInput.Text = $DefaultVersionName
$form.Controls.Add($versionNameInput)

$versionCodeLabel = New-Object System.Windows.Forms.Label
$versionCodeLabel.Text = '版本号'
$versionCodeLabel.Location = New-Object System.Drawing.Point(588, 262)
$versionCodeLabel.Size = New-Object System.Drawing.Size(54, 24)
$form.Controls.Add($versionCodeLabel)

$versionCodeInput = New-Object System.Windows.Forms.TextBox
$versionCodeInput.Location = New-Object System.Drawing.Point(642, 259)
$versionCodeInput.Size = New-Object System.Drawing.Size(114, 26)
$versionCodeInput.Text = $DefaultVersionCode
$form.Controls.Add($versionCodeInput)

$iconLabel = New-Object System.Windows.Forms.Label
$iconLabel.Text = 'APP图标'
$iconLabel.Location = New-Object System.Drawing.Point(24, 406)
$iconLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($iconLabel)

$iconInput = New-Object System.Windows.Forms.TextBox
$iconInput.Location = New-Object System.Drawing.Point(130, 403)
$iconInput.Size = New-Object System.Drawing.Size(515, 26)
$iconInput.Text = $DefaultIconPath
$form.Controls.Add($iconInput)

$browseIconButton = New-Object System.Windows.Forms.Button
$browseIconButton.Text = '选择图片'
$browseIconButton.Location = New-Object System.Drawing.Point(658, 401)
$browseIconButton.Size = New-Object System.Drawing.Size(98, 30)
$browseIconButton.Add_Click({
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Filter = '图片文件|*.png;*.jpg;*.jpeg;*.bmp|所有文件|*.*'
    $dialog.Title = '选择 APP 图标图片'
    if (-not [string]::IsNullOrWhiteSpace($iconInput.Text)) {
        $dir = Split-Path -Parent $iconInput.Text
        if ($dir -and (Test-Path -LiteralPath $dir)) { $dialog.InitialDirectory = $dir }
    }
    if ($dialog.ShowDialog() -eq 'OK') {
        $iconInput.Text = $dialog.FileName
    }
})
$form.Controls.Add($browseIconButton)

$outputLabel = New-Object System.Windows.Forms.Label
$outputLabel.Text = '输出文件夹'
$outputLabel.Location = New-Object System.Drawing.Point(24, 442)
$outputLabel.Size = New-Object System.Drawing.Size(100, 24)
$form.Controls.Add($outputLabel)

$outputInput = New-Object System.Windows.Forms.TextBox
$outputInput.Location = New-Object System.Drawing.Point(130, 439)
$outputInput.Size = New-Object System.Drawing.Size(515, 26)
$outputInput.Text = $DefaultOutputDir
$form.Controls.Add($outputInput)

$browseButton = New-Object System.Windows.Forms.Button
$browseButton.Text = '选择目录'
$browseButton.Location = New-Object System.Drawing.Point(658, 437)
$browseButton.Size = New-Object System.Drawing.Size(98, 30)
$browseButton.Add_Click({
    $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
    $dialog.SelectedPath = $outputInput.Text
    if ($dialog.ShowDialog() -eq 'OK') {
        $outputInput.Text = $dialog.SelectedPath
    }
})
$form.Controls.Add($browseButton)

$testButton = New-Object System.Windows.Forms.Button
$testButton.Text = '测试网页/API'
$testButton.Location = New-Object System.Drawing.Point(130, 482)
$testButton.Size = New-Object System.Drawing.Size(140, 36)
$testButton.Add_Click({ Invoke-BackendTest })
$form.Controls.Add($testButton)

$buildButton = New-Object System.Windows.Forms.Button
$buildButton.Text = '一键打包 APK'
$buildButton.Location = New-Object System.Drawing.Point(284, 482)
$buildButton.Size = New-Object System.Drawing.Size(140, 36)
$buildButton.BackColor = [System.Drawing.Color]::FromArgb(36, 105, 245)
$buildButton.ForeColor = [System.Drawing.Color]::White
$buildButton.FlatStyle = 'Flat'
$buildButton.FlatAppearance.BorderSize = 0
$buildButton.Add_Click({ Start-Build })
$form.Controls.Add($buildButton)

$openButton = New-Object System.Windows.Forms.Button
$openButton.Text = '打开目录'
$openButton.Location = New-Object System.Drawing.Point(438, 482)
$openButton.Size = New-Object System.Drawing.Size(120, 36)
$openButton.Add_Click({
    $dir = $outputInput.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($dir)) { $dir = $DefaultOutputDir }
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    Start-Process explorer.exe $dir
})
$form.Controls.Add($openButton)

$openApkButton = New-Object System.Windows.Forms.Button
$openApkButton.Text = '定位 APK'
$openApkButton.Location = New-Object System.Drawing.Point(572, 482)
$openApkButton.Size = New-Object System.Drawing.Size(120, 36)
$openApkButton.Enabled = $false
$openApkButton.Add_Click({
    if ($script:OutputApk -and (Test-Path -LiteralPath $script:OutputApk)) {
        Start-Process explorer.exe "/select,`"$script:OutputApk`""
    }
})
$form.Controls.Add($openApkButton)

foreach ($button in @($testButton, $openButton, $openApkButton, $browseIconButton, $browseButton)) {
    $button.FlatStyle = 'Flat'
    $button.BackColor = [System.Drawing.Color]::FromArgb(255, 255, 255)
    $button.ForeColor = [System.Drawing.Color]::FromArgb(30, 64, 175)
    $button.FlatAppearance.BorderColor = [System.Drawing.Color]::FromArgb(147, 197, 253)
    $button.FlatAppearance.BorderSize = 1
}

$statusLabel = New-Object System.Windows.Forms.Label
$statusLabel.Text = '就绪。'
$statusLabel.Location = New-Object System.Drawing.Point(24, 534)
$statusLabel.Size = New-Object System.Drawing.Size(735, 24)
$form.Controls.Add($statusLabel)

$progress = New-Object System.Windows.Forms.ProgressBar
$progress.Location = New-Object System.Drawing.Point(24, 562)
$progress.Size = New-Object System.Drawing.Size(732, 18)
$progress.Value = 0
$form.Controls.Add($progress)

$logBox = New-Object System.Windows.Forms.TextBox
$logBox.Location = New-Object System.Drawing.Point(24, 598)
$logBox.Size = New-Object System.Drawing.Size(732, 190)
$logBox.Multiline = $true
$logBox.ScrollBars = 'Vertical'
$logBox.ReadOnly = $true
$logBox.Font = New-Object System.Drawing.Font('Consolas', 9)
$logBox.BackColor = [System.Drawing.Color]::FromArgb(15, 23, 42)
$logBox.ForeColor = [System.Drawing.Color]::FromArgb(226, 232, 240)
$form.Controls.Add($logBox)

$headerPanel = New-Object System.Windows.Forms.Panel
$headerPanel.Location = New-Object System.Drawing.Point(0, 0)
$headerPanel.Size = New-Object System.Drawing.Size(860, 76)
$headerPanel.BackColor = [System.Drawing.Color]::FromArgb(15, 23, 42)
$form.Controls.Add($headerPanel)
$headerPanel.SendToBack()

function Add-SectionCard {
    param(
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [string]$Title
    )
    $card = New-Object System.Windows.Forms.Panel
    $card.Location = New-Object System.Drawing.Point($X, $Y)
    $card.Size = New-Object System.Drawing.Size($W, $H)
    $card.BackColor = [System.Drawing.Color]::White
    $card.BorderStyle = 'FixedSingle'
    $form.Controls.Add($card)
    $card.SendToBack()

}

Add-SectionCard 18 82 800 112 '服务器配置'
Add-SectionCard 18 186 800 286 '应用品牌'
Add-SectionCard 18 478 800 108 '输出与打包'
Add-SectionCard 18 594 800 226 '构建日志'

foreach ($control in $form.Controls) {
    if ($control -is [System.Windows.Forms.TextBox] -and $control -ne $logBox) {
        $control.BorderStyle = 'FixedSingle'
        $control.BackColor = [System.Drawing.Color]::FromArgb(248, 250, 252)
        $control.ForeColor = [System.Drawing.Color]::FromArgb(15, 23, 42)
    } elseif ($control -is [System.Windows.Forms.ComboBox]) {
        $control.BackColor = [System.Drawing.Color]::FromArgb(248, 250, 252)
        $control.ForeColor = [System.Drawing.Color]::FromArgb(15, 23, 42)
    } elseif ($control -is [System.Windows.Forms.Label] -and $control -ne $title -and $control -ne $subtitle -and $control -ne $previewLabel) {
        $control.ForeColor = [System.Drawing.Color]::FromArgb(51, 65, 85)
    }
}

$title.BringToFront()
$subtitle.BringToFront()
$previewLabel.BringToFront()
$buildButton.BackColor = [System.Drawing.Color]::FromArgb(37, 99, 235)
$buildButton.Font = New-Object System.Drawing.Font('Microsoft YaHei UI', 9, [System.Drawing.FontStyle]::Bold)
$statusLabel.ForeColor = [System.Drawing.Color]::FromArgb(15, 23, 42)
$progress.ForeColor = [System.Drawing.Color]::FromArgb(37, 99, 235)

$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 900
$timer.Add_Tick({
    Refresh-Log
    if ($script:BuildProcess -and $script:BuildProcess.HasExited) {
        Finish-Build
    }
})

$form.Add_Shown({
    Update-Preview
    Append-Log '就绪：输入 IP/域名后点击“一键打包 APK”。'
    Append-Log '提示：网页端口填后台/页面端口；WS端口用于房间和雷达数据，默认 8888。'
})

$form.Add_FormClosing({
    if ($script:BuildProcess -and -not $script:BuildProcess.HasExited) {
        $result = [System.Windows.Forms.MessageBox]::Show('APK 仍在打包中，确定要关闭吗？', 'ALinRadar APK 打包器', 'YesNo', 'Warning')
        if ($result -ne 'Yes') {
            $_.Cancel = $true
        }
    }
})

[System.Windows.Forms.Application]::EnableVisualStyles()
[System.Windows.Forms.Application]::Run($form)
