# ============================================================================
# CBT Offline -- Automated Build Script
# Assembles all components into a build/ directory and compiles the installer.
#
# USAGE:
#   cd c:\xampp\htdocs\cbt\installer
#   .\build.ps1
#
# REQUIREMENTS:
#   - PowerShell 5.1+  (comes with Windows 10/11)
#   - Internet connection (first run only -- downloads PHP and MariaDB)
#   - Inno Setup 6 installed at default location
#   - AutoHotkey v1 installed (to compile CBTManager.ahk to CBTManager.exe)
#
# OUTPUT:
#   installer\dist\CBT-Setup.exe
# ============================================================================

$ErrorActionPreference = "Stop"

# --- Paths ---
$ScriptDir   = $PSScriptRoot
$ProjectRoot = Split-Path $ScriptDir -Parent
$BuildDir    = Join-Path $ScriptDir "build"
$DistDir     = Join-Path $ScriptDir "dist"
$AppWwwDir   = Join-Path $BuildDir  "www"
$TempDir     = Join-Path $ScriptDir "tmp"

# --- Download URLs (pinned stable versions) ---
$PhpUrl     = "https://windows.php.net/downloads/releases/php-8.2.33-nts-Win32-vs16-x64.zip"
$PhpZip     = Join-Path $TempDir "php.zip"

$MariaDbUrl = "https://downloads.mariadb.org/rest-api/mariadb/10.11.11/mariadb-10.11.11-winx64.zip"
$MariaDbZip = Join-Path $TempDir "mariadb.zip"

$AhkCompiler  = "C:\Program Files\AutoHotkey\Compiler\Ahk2Exe.exe"
$InnoCompiler = "C:\Program Files (x86)\Inno Setup 6\ISCC.exe"

# ============================================================================
Write-Host ""
Write-Host "====================================================" -ForegroundColor Magenta
Write-Host "  CBT Offline -- Installer Build Script" -ForegroundColor Magenta
Write-Host "====================================================" -ForegroundColor Magenta

# ============================================================================
Write-Host ""
Write-Host ">>> Step 1 -- Creating build directories" -ForegroundColor Cyan

$dirs = @($BuildDir, $DistDir, $TempDir,
          "$BuildDir\php", "$BuildDir\mariadb",
          "$BuildDir\www",  "$BuildDir\data",
          "$BuildDir\assets")

foreach ($d in $dirs) {
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
}
Write-Host "    [OK] Directories ready." -ForegroundColor Green

# ============================================================================
Write-Host ""
Write-Host ">>> Step 2 -- Downloading PHP 8.2 (portable)" -ForegroundColor Cyan

if (-not (Test-Path $PhpZip)) {
    Write-Host "    Downloading PHP 8.2..." -NoNewline
    try {
        $wc = New-Object System.Net.WebClient
        $wc.Headers.Add("User-Agent", "CBT-Installer-Build/1.0")
        $wc.DownloadFile($PhpUrl, $PhpZip)
    } catch {
        Invoke-WebRequest -Uri $PhpUrl -OutFile $PhpZip -UseBasicParsing
    }
    Write-Host " Done." -ForegroundColor Green
} else {
    Write-Host "    [OK] PHP already downloaded." -ForegroundColor Green
}

$PhpExtractDir = Join-Path $TempDir "php_extracted"
if (-not (Test-Path $PhpExtractDir)) {
    Write-Host "    Extracting PHP..."
    Expand-Archive -Path $PhpZip -DestinationPath $PhpExtractDir -Force
    Write-Host "    [OK] PHP extracted." -ForegroundColor Green
}

Write-Host "    Copying PHP to build dir..."
Copy-Item "$PhpExtractDir\*" "$BuildDir\php\" -Recurse -Force
Copy-Item "$ScriptDir\php.ini" "$BuildDir\php\php.ini" -Force
Write-Host "    [OK] PHP ready." -ForegroundColor Green

# ============================================================================
Write-Host ""
Write-Host ">>> Step 3 -- Downloading MariaDB 10.11 LTS (portable)" -ForegroundColor Cyan

if (-not (Test-Path $MariaDbZip)) {
    Write-Host "    Downloading MariaDB..." -NoNewline
    try {
        $wc = New-Object System.Net.WebClient
        $wc.Headers.Add("User-Agent", "CBT-Installer-Build/1.0")
        $wc.DownloadFile($MariaDbUrl, $MariaDbZip)
    } catch {
        Invoke-WebRequest -Uri $MariaDbUrl -OutFile $MariaDbZip -UseBasicParsing
    }
    Write-Host " Done." -ForegroundColor Green
} else {
    Write-Host "    [OK] MariaDB already downloaded." -ForegroundColor Green
}

$MariaDbExtractDir = Join-Path $TempDir "mariadb_extracted"
if (-not (Test-Path $MariaDbExtractDir)) {
    Write-Host "    Extracting MariaDB..."
    Expand-Archive -Path $MariaDbZip -DestinationPath $MariaDbExtractDir -Force
    Write-Host "    [OK] MariaDB extracted." -ForegroundColor Green
}

$MariaDbRoot = Get-ChildItem -Path $MariaDbExtractDir -Directory | Select-Object -First 1
if ($null -eq $MariaDbRoot) {
    throw "Could not find MariaDB root directory inside extracted zip."
}

Write-Host "    Copying MariaDB to build dir..."
Copy-Item "$($MariaDbRoot.FullName)\*" "$BuildDir\mariadb\" -Recurse -Force
Copy-Item "$ScriptDir\my.ini" "$BuildDir\my.ini" -Force
Write-Host "    [OK] MariaDB ready." -ForegroundColor Green

# ============================================================================
Write-Host ""
Write-Host ">>> Step 4 -- Copying CBT app files to www\" -ForegroundColor Cyan

$ExcludeDirs = @("installer", ".git", "temp")
Get-ChildItem -Path $ProjectRoot | ForEach-Object {
    if ($ExcludeDirs -notcontains $_.Name) {
        if ($_.PSIsContainer) {
            Copy-Item $_.FullName "$AppWwwDir\$($_.Name)" -Recurse -Force
        } else {
            Copy-Item $_.FullName "$AppWwwDir\" -Force
        }
    }
}

# Remove dev-only files from www
@("$AppWwwDir\.git", "$AppWwwDir\installer", "$AppWwwDir\config\config.ini") | ForEach-Object {
    if (Test-Path $_) { Remove-Item $_ -Recurse -Force }
}
Write-Host "    [OK] App files copied and cleaned." -ForegroundColor Green

# ============================================================================
Write-Host ""
Write-Host ">>> Step 5 -- Copying launcher scripts" -ForegroundColor Cyan

Copy-Item "$ScriptDir\launcher.bat" "$BuildDir\launcher.bat" -Force
Copy-Item "$ScriptDir\stop.bat"     "$BuildDir\stop.bat"     -Force
Write-Host "    [OK] Launcher scripts copied." -ForegroundColor Green

# ============================================================================
Write-Host ""
Write-Host ">>> Step 6 -- Compiling CBTManager.exe (AutoHotkey)" -ForegroundColor Cyan

if (Test-Path $AhkCompiler) {
    $AhkScript  = Join-Path $ScriptDir "CBTManager.ahk"
    $AhkOutput  = Join-Path $BuildDir  "CBTManager.exe"
    $FaviconSrc = Join-Path $ProjectRoot "assets\favicon.ico"

    if (Test-Path $FaviconSrc) {
        Copy-Item $FaviconSrc "$BuildDir\assets\favicon.ico" -Force
        $ahkArgs = "/in `"$AhkScript`" /out `"$AhkOutput`" /icon `"$FaviconSrc`""
    } else {
        $ahkArgs = "/in `"$AhkScript`" /out `"$AhkOutput`""
    }

    Write-Host "    Compiling CBTManager.ahk..."
    Start-Process -FilePath $AhkCompiler -ArgumentList $ahkArgs -Wait -NoNewWindow
    if (Test-Path $AhkOutput) {
        Write-Host "    [OK] CBTManager.exe compiled." -ForegroundColor Green
    } else {
        throw "AHK compilation failed - CBTManager.exe not found."
    }
} else {
    Write-Host "    [WARN] AutoHotkey not found at: $AhkCompiler" -ForegroundColor Yellow
    Write-Host "    [WARN] Install from https://www.autohotkey.com/download/1.x/" -ForegroundColor Yellow
    Write-Host "    [WARN] Skipping CBTManager.exe -- using php.exe as placeholder." -ForegroundColor Yellow
    if (-not (Test-Path "$BuildDir\CBTManager.exe")) {
        Copy-Item "$BuildDir\php\php.exe" "$BuildDir\CBTManager.exe" -Force
    }
}

# ============================================================================
Write-Host ""
Write-Host ">>> Step 7 -- Compiling Installer with Inno Setup" -ForegroundColor Cyan

if (Test-Path $InnoCompiler) {
    $IssFile = Join-Path $ScriptDir "setup.iss"
    Write-Host "    Compiling setup.iss..."
    & $InnoCompiler $IssFile
    if ($LASTEXITCODE -eq 0) {
        $OutputExe = Join-Path $DistDir "CBT-Setup.exe"
        if (Test-Path $OutputExe) {
            $fileSizeMB = [string]([math]::Round((Get-Item $OutputExe).Length / 1MB, 1)) + " MB"
            Write-Host "    [OK] CBT-Setup.exe created! Size: $fileSizeMB" -ForegroundColor Green
            Write-Host "    [OK] File: $OutputExe" -ForegroundColor Green
        }
    } else {
        throw "Inno Setup compilation failed."
    }
} else {
    Write-Host "    [WARN] Inno Setup not found at: $InnoCompiler" -ForegroundColor Yellow
    Write-Host "    [WARN] Install from https://jrsoftware.org/isinfo.php then re-run." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "    The build\ directory is ready." -ForegroundColor Yellow
    Write-Host "    Open setup.iss in Inno Setup IDE to compile manually." -ForegroundColor Yellow
}

# ============================================================================
Write-Host ""
Write-Host "====================================================" -ForegroundColor Green
Write-Host "  BUILD COMPLETE!" -ForegroundColor Green
Write-Host "====================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Build dir:   $BuildDir"
Write-Host "  Installer:   $DistDir\CBT-Setup.exe"
Write-Host ""
Write-Host "  NEXT STEPS:" -ForegroundColor Cyan
Write-Host "  1. Test on a clean Windows VM (no PHP/XAMPP installed)"
Write-Host "  2. Run CBT-Setup.exe as Administrator"
Write-Host "  3. Complete first-time setup with your School ID"
Write-Host "  4. Test LAN access from a student computer"
Write-Host ""
