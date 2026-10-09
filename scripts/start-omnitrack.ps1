<#
.SYNOPSIS
    Start the Omnitrack local stack and open it in the browser.

.DESCRIPTION
    One-click launcher for local development. In order it:

      1. makes sure the Docker daemon is up, starting Docker Desktop if needed;
      2. checks the image exists (it will NOT build -- a build is minutes long
         and should never happen behind a double-click);
      3. brings the dev stack up with the source bind-mounted;
      4. ensures a login account exists;
      5. waits for the app to be genuinely READY, not merely alive;
      6. opens the browser.

    WHY IT WAITS ON /login AND NOT /up
    ----------------------------------
    /up is Laravel's built-in liveness probe and answers 200 as soon as nginx
    and PHP-FPM are serving -- it says nothing about the database. Opening the
    browser on that signal can land on a 500 while migrations are still running.
    /login forces a full boot, reads the database config and touches the
    sessions table, so a 200 there proves the schema is ready.

.EXAMPLE
    .\scripts\start-omnitrack.ps1
    .\scripts\start-omnitrack.ps1 -NoBrowser    # verify without opening a tab
#>

[CmdletBinding()]
param(
    # Skip launching the browser. Used by the automated check.
    [switch]$NoBrowser,

    # Keep the console open if the launcher fails.
    #
    # Needed when this runs from a Start Menu / taskbar shortcut that targets
    # powershell.exe directly: that window belongs to this process, so it
    # vanishes the moment the script exits and a failure message would never be
    # readable. The .cmd wrapper does NOT pass this -- it already pauses itself,
    # and pausing twice would mean pressing Enter twice.
    [switch]$PauseOnError
)

Set-StrictMode -Version Latest

# NOTE: deliberately NOT 'Stop'. Windows PowerShell 5.1 turns anything a native
# command writes to stderr into an ErrorRecord, and Docker Compose writes normal
# progress ("Container x Created") to stderr. With 'Stop' in force the script
# would abort on its own success path. Every native call is exit-code checked
# explicitly below instead.
$ErrorActionPreference = 'Continue'

$Port         = 8080
$Url          = "http://localhost:$Port"
$ImageName    = 'omnitrack-engine:local'
$DaemonWait   = 180   # Docker Desktop cold start is the slow part
$AppWait      = 180   # migrations on a fresh volume take a while
$ProjectRoot  = Split-Path -Parent $PSScriptRoot
$ComposeFiles = @(
    '-f', 'docker-compose.yml',
    '-f', 'docker-compose.dev.yml'
)

function Write-Step { param([string]$Message) Write-Host "  $Message" -ForegroundColor Gray }
function Write-Ok   { param([string]$Message) Write-Host "  $Message" -ForegroundColor Green }
function Write-Warn { param([string]$Message) Write-Host "  $Message" -ForegroundColor Yellow }

function Stop-WithError {
    param([string]$Message)
    Write-Host ''
    Write-Host "  GAGAL: $Message" -ForegroundColor Red
    Write-Host ''
    if ($PauseOnError) {
        Write-Host '  Tekan Enter untuk menutup.' -ForegroundColor DarkGray
        [void](Read-Host)
    }
    exit 1
}

function Test-DockerDaemon {
    & docker info --format '{{.ServerVersion}}' *> $null
    return ($LASTEXITCODE -eq 0)
}

# Keep PowerShell 5.1 from mangling UTF-8 output, so Docker's own progress lines
# arrive readable rather than as mojibake.
try { [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false) } catch { }
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)

Set-Location $ProjectRoot

Write-Host ''
Write-Host '  Omnitrack' -ForegroundColor Cyan
Write-Host '  ---------' -ForegroundColor DarkCyan

# ---------------------------------------------------------------------------
# 1. Docker daemon
# ---------------------------------------------------------------------------
if (-not (Test-DockerDaemon)) {
    Write-Warn 'Docker Desktop belum jalan. Menyalakan...'

    $desktop = @(
        (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'),
        (Join-Path ${env:ProgramFiles(x86)} 'Docker\Docker\Docker Desktop.exe'),
        (Join-Path $env:LOCALAPPDATA 'Docker\Docker Desktop.exe')
    ) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

    if (-not $desktop) {
        Stop-WithError @'
Docker Desktop tidak ditemukan di lokasi standar, dan daemon Docker tidak jalan.
Install Docker Desktop, atau nyalakan sendiri lalu jalankan shortcut ini lagi.
'@
    }

    Start-Process -FilePath $desktop | Out-Null

    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.Elapsed.TotalSeconds -lt $DaemonWait) {
        Start-Sleep -Seconds 3
        if (Test-DockerDaemon) { break }
        Write-Host '.' -NoNewline -ForegroundColor DarkGray
    }

    if (-not (Test-DockerDaemon)) {
        Stop-WithError "Docker daemon tidak siap setelah $DaemonWait detik. Buka Docker Desktop dan periksa statusnya."
    }
    Write-Host ''
    Write-Ok "Docker siap dalam $([math]::Round($sw.Elapsed.TotalSeconds)) detik."
} else {
    Write-Step 'Docker sudah jalan.'
}

# ---------------------------------------------------------------------------
# 2. Image
#
# A build takes about four minutes because the Dockerfile compiles the PHP
# extensions. That is unacceptable latency behind a double-click, so the
# launcher refuses rather than silently making the user wait.
# ---------------------------------------------------------------------------
& docker image inspect $ImageName *> $null
if ($LASTEXITCODE -ne 0) {
    Stop-WithError @"
Image '$ImageName' belum ada.

Jalankan sekali dari terminal:

    cd "$ProjectRoot"
    .\scripts\dev.ps1 build

Sekitar 4 menit, dan hanya perlu sekali (atau setelah Dockerfile berubah).
"@
}
Write-Step "Image $ImageName ada."

# ---------------------------------------------------------------------------
# 3. Bring the stack up
# ---------------------------------------------------------------------------
Write-Step 'Menjalankan container...'
& docker compose @ComposeFiles up -d 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Stop-WithError "docker compose up gagal (exit $LASTEXITCODE). Jalankan '.\scripts\dev.ps1 up' untuk melihat pesan lengkapnya."
}

# ---------------------------------------------------------------------------
# 4. Login account
#
# Idempotent and takes ~0.3s. It matters because a `dev.ps1 down -v` removes the
# database volume, and without this the browser would open on a login page that
# cannot possibly succeed.
# ---------------------------------------------------------------------------
Write-Step 'Memastikan akun login ada...'
& docker compose @ComposeFiles exec -T app php artisan db:seed --class=UserSeeder --force *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Warn 'Seeder gagal; lanjut saja (mungkin akunnya sudah ada).'
}

# ---------------------------------------------------------------------------
# 5. Wait until genuinely ready, then open the browser
# ---------------------------------------------------------------------------
Write-Step 'Menunggu aplikasi siap...'

$sw = [System.Diagnostics.Stopwatch]::StartNew()
$ready = $false
$alive = $false

while ($sw.Elapsed.TotalSeconds -lt $AppWait) {
    if (-not $alive) {
        try {
            $probe = Invoke-WebRequest "$Url/up" -UseBasicParsing -TimeoutSec 5
            $alive = ($probe.StatusCode -eq 200)
        } catch { }
    }

    try {
        $login = Invoke-WebRequest "$Url/login" -UseBasicParsing -TimeoutSec 5
        if ($login.StatusCode -eq 200) { $ready = $true; break }
    } catch { }

    Start-Sleep -Milliseconds 700
}
$sw.Stop()

if (-not $ready) {
    Stop-WithError @"
Aplikasi tidak menjawab 200 di $Url/login setelah $AppWait detik.

Periksa log container:

    cd "$ProjectRoot"
    .\scripts\dev.ps1 logs app

Penyebab yang paling sering: port $Port sudah dipakai program lain.
"@
}

Write-Ok "Aplikasi siap dalam $([math]::Round($sw.Elapsed.TotalSeconds,1)) detik."

if ($NoBrowser) {
    Write-Step "-NoBrowser: browser tidak dibuka."
} else {
    Write-Step "Membuka $Url"
    Start-Process $Url | Out-Null
}

Write-Host ''
Write-Host "  Omnitrack jalan di $Url" -ForegroundColor Green
Write-Host '  Login dengan OMNITRACK_USER_EMAIL / OMNITRACK_USER_PASSWORD dari .env' -ForegroundColor DarkGray
Write-Host '  Untuk mematikan: .\scripts\dev.ps1 stop' -ForegroundColor DarkGray
Write-Host ''
