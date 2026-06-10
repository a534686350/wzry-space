param(
    [switch]$BuildApk,
    [string]$CommitMessage = "",
    [string]$Branch = "main",
    [string]$GitHubRemote = "github",
    [string]$GiteeRemote = "gitee",
    [switch]$SkipGitHub,
    [switch]$SkipGitee
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$GitHubSyncScript = Join-Path $RepoRoot "scripts\sync-github-tree.py"

function Invoke-GitChecked {
    param([string[]]$ArgsForGit)

    & git @ArgsForGit
    if ($LASTEXITCODE -ne 0) {
        throw "git $($ArgsForGit -join ' ') failed with exit code $LASTEXITCODE"
    }
}

function Get-WorkingTreeStatus {
    git -c core.quotepath=false status --porcelain
}

function Invoke-PythonChecked {
    param([string[]]$ArgsForPython)

    $Python = Get-Command python -ErrorAction SilentlyContinue
    if ($null -ne $Python) {
        & $Python.Source @ArgsForPython
    }
    else {
        $PyLauncher = Get-Command py -ErrorAction SilentlyContinue
        if ($null -eq $PyLauncher) {
            throw "Python was not found. Install Python or sync GitHub with normal git push."
        }
        & $PyLauncher.Source -3 @ArgsForPython
    }

    if ($LASTEXITCODE -ne 0) {
        throw "Python sync failed with exit code $LASTEXITCODE"
    }
}

Push-Location $RepoRoot
try {
    if ($BuildApk) {
        $BuildScript = Join-Path $RepoRoot "APP\wzry_overlay_apk\build-and-publish-release.ps1"
        & powershell -ExecutionPolicy Bypass -File $BuildScript
        if ($LASTEXITCODE -ne 0) {
            throw "APK build failed with exit code $LASTEXITCODE"
        }
    }

    if (-not [string]::IsNullOrWhiteSpace($CommitMessage)) {
        Invoke-GitChecked @("add", "-A")
        if (Get-WorkingTreeStatus) {
            Invoke-GitChecked @("commit", "-m", $CommitMessage)
        }
        else {
            Write-Host "No changes to commit."
        }
    }

    $Status = Get-WorkingTreeStatus
    if ($Status) {
        Write-Host $Status
        throw "Working tree has uncommitted changes. Commit first or pass -CommitMessage."
    }

    if (-not $SkipGitee) {
        Write-Host "Pushing to Gitee..."
        Invoke-GitChecked @("push", $GiteeRemote, "HEAD:$Branch")
    }

    if (-not $SkipGitHub) {
        Write-Host "Pushing to GitHub..."
        & git push $GitHubRemote "HEAD:$Branch"
        if ($LASTEXITCODE -ne 0) {
            Write-Host "GitHub git push failed. Falling back to GitHub Git Data API..."
            Invoke-PythonChecked @($GitHubSyncScript, "--remote", $GitHubRemote, "--branch", $Branch)
        }
    }

    Write-Host "Remote sync finished."
}
finally {
    Pop-Location
}
