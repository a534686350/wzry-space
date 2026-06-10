param(
    [string]$PublishDir = ""
)

$ErrorActionPreference = "Stop"

$ProjectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$WorkspaceDir = Split-Path -Parent (Split-Path -Parent $ProjectDir)
$Gradle = Join-Path $ProjectDir "tools\gradle-8.10.2\bin\gradle.bat"
$MetadataPath = Join-Path $ProjectDir "app\build\outputs\apk\release\output-metadata.json"

if (-not (Test-Path -LiteralPath $Gradle)) {
    throw "Gradle not found: $Gradle"
}

Push-Location $ProjectDir
try {
    & $Gradle assembleRelease
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
finally {
    Pop-Location
}

if (-not (Test-Path -LiteralPath $MetadataPath)) {
    throw "Release metadata not found: $MetadataPath"
}

$Metadata = Get-Content -LiteralPath $MetadataPath -Raw | ConvertFrom-Json
$Element = $Metadata.elements[0]
$VersionName = [string]$Element.versionName
$SafeVersionName = $VersionName -replace '[^\w\.\-]', '_'
$ApkSource = Join-Path $ProjectDir ("app\build\outputs\apk\release\" + $Element.outputFile)

if (-not (Test-Path -LiteralPath $ApkSource)) {
    throw "Release APK not found: $ApkSource"
}

if ([string]::IsNullOrWhiteSpace($PublishDir)) {
    $WebDirName = -join ([char[]](0x7f51, 0x9875, 0x524d, 0x540e, 0x53f0))
    $PublishPath = Join-Path (Join-Path $WorkspaceDir $WebDirName) "apk"
}
elseif ([System.IO.Path]::IsPathRooted($PublishDir)) {
    $PublishPath = $PublishDir
}
else {
    $PublishPath = Join-Path $ProjectDir $PublishDir
}

New-Item -ItemType Directory -Force -Path $PublishPath | Out-Null
$ApkDest = Join-Path $PublishPath ("ALinRadar-" + $SafeVersionName + ".apk")
Copy-Item -LiteralPath $ApkSource -Destination $ApkDest -Force
$Hash = Get-FileHash -Algorithm SHA256 -LiteralPath $ApkDest

Write-Host "Release APK published:"
Write-Host "  Version: $VersionName ($($Element.versionCode))"
Write-Host "  File:    $ApkDest"
Write-Host "  SHA256:  $($Hash.Hash)"
