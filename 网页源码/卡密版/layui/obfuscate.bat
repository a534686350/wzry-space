@echo off
echo 开始混淆 layui.js 文件...

REM 检查文件是否存在
if not exist "layui.js" (
    echo 错误: layui.js 文件不存在
    pause
    exit /b 1
)

echo 文件大小: %~z0 字节

echo 正在进行简单的混淆处理...

REM 使用 PowerShell 进行简单的混淆
powershell -Command "
$source = Get-Content 'layui.js' -Raw

# 移除注释
$source = $source -replace '//.*?\r?\n', ''
$source = $source -replace '/\\*.*?\\*/', '' -replace '\s+', ' '

# 简单的变量名替换
$counter = 0
$varMap = @{}

# 匹配变量名
$source = [regex]::Replace($source, '\\b(var|let|const)\\s+([a-zA-Z_\\$][a-zA-Z0-9_\\$]*)', {
    param($match)
    $keyword = $match.Groups[1].Value
    $varName = $match.Groups[2].Value
    
    if (-not $varMap.ContainsKey($varName)) {
        $varMap[$varName] = '_' + [Convert]::ToString($counter, 36)
        $counter++
    }
    
    return $keyword + ' ' + $varMap[$varName]
})

# 匹配函数名
$source = [regex]::Replace($source, '\\bfunction\\s+([a-zA-Z_\\$][a-zA-Z0-9_\\$]*)', {
    param($match)
    $funcName = $match.Groups[1].Value
    
    if (-not $varMap.ContainsKey($funcName)) {
        $varMap[$funcName] = '_' + [Convert]::ToString($counter, 36)
        $counter++
    }
    
    return 'function ' + $varMap[$funcName]
})

Write-Output "替换了 $($varMap.Count) 个变量名"

# 写入混淆后的文件
Set-Content -Path 'script-obfuscated.js' -Value $source -Encoding UTF8
"

echo.
echo 混淆完成！
echo 输出文件: script-obfuscated.js

REM 检查输出文件
if exist "script-obfuscated.js" (
    for %%F in ("script-obfuscated.js") do set size=%%~zF
    echo 输出文件大小: %size% 字节
    echo 文件已成功创建
) else (
    echo 错误: 输出文件创建失败
)

pause