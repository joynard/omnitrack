<#
.SYNOPSIS
    Development task runner for the Omnitrack Engine Docker stack.

.DESCRIPTION
    Wraps the long `docker compose -f docker-compose.yml -f docker-compose.dev.yml`
    invocation so normal work is one short command.

    The point of the dev overlay is that the source tree is bind-mounted into the
    container, so editing a file is enough -- no image rebuild. This script exists
    so that you never have to remember the overlay flags, and so that the slow
    paths (a rebuild, a full dependency install) are explicit subcommands rather
    than accidents.

.EXAMPLE
    ./scripts/dev.ps1 init          # one time: env file + install vendor/ into its volume
    ./scripts/dev.ps1 up            # start app + db with the source bind-mounted
    ./scripts/dev.ps1 test          # run the PHPUnit suite (no rebuild)
    ./scripts/dev.ps1 test --filter=HarnessTest
    ./scripts/dev.ps1 tinker
    ./scripts/dev.ps1 logs app
    ./scripts/dev.ps1 sh
    ./scripts/dev.ps1 down
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('init', 'up', 'down', 'restart', 'build', 'test', 'artisan', 'tinker',
                 'composer', 'npm', 'logs', 'ps', 'sh', 'fresh', 'config', 'help')]
    [string]$Command = 'help',

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]]$Args
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
Push-Location $RepoRoot

# NOTE: the Compose project name is intentionally NOT set here. It comes from
# COMPOSE_PROJECT_NAME in .env, which keeps this script and a raw
# `docker compose -f ...` invocation in the *same* project -- and therefore
# sharing the same named volumes. Exporting it here would silently override
# .env and produce a second, empty set of volumes.

$Base   = 'docker-compose.yml'
$Dev    = 'docker-compose.dev.yml'
$Test   = 'docker-compose.test.yml'

function Invoke-Compose {
    param(
        [string[]]$Files,
        [Parameter(ValueFromRemainingArguments = $true)][string[]]$ComposeArgs
    )
    $fileArgs = @()
    foreach ($f in $Files) { $fileArgs += @('-f', $f) }

    # PowerShell 5.1 turns anything a native command writes to stderr into an
    # ErrorRecord. Docker Compose writes ordinary progress ("Network x Creating",
    # "Container y Created") to stderr, so with $ErrorActionPreference = 'Stop'
    # in force the script aborts on the very first container it creates and the
    # real command output is lost. Relax the preference across the call and
    # decide success from the exit code instead.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker compose @fileArgs @ComposeArgs
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }

    if ($code -ne 0) {
        throw "docker compose exited with code $code"
    }
}

function Get-DevFiles {
    @($Base, $Dev)
}

function Get-DevArgs {
    # IMPORTANT: enabling a profile also *starts* that profile's services.
    # Passing --profile test to `up` booted omnitrack-test-1, which ran
    # `php artisan test` under the dev entrypoint and exited with code 2 on
    # every `up`. Use no profiles for lifecycle commands (up/build/restart) and
    # this helper only where the test service must be visible to the project
    # model (down/ps/exec), so its container is still cleaned up.
    @('--profile', 'test')
}

switch ($Command) {
    'help' {
        Write-Host @'
Omnitrack Engine -- development task runner

  init                 Create .env if missing, then install vendor/ into the
                       dev-vendor volume. Slow once, fast afterwards.
  up                   Start app + db with source bind-mounted.
  build                Force a rebuild of the app image (only needed when the
                       Dockerfile or composer.lock changes).
  down                 Stop the stack. Add -v to also delete named volumes.
  restart              down + up.
  test [args]          Run the PHPUnit suite. No rebuild.
  artisan <args>       php artisan inside the app container.
  tinker               php artisan tinker.
  composer <args>      Run composer through the tools profile.
  npm <args>           Run npm through the tools profile (once package.json exists).
  logs [service]       Follow container logs.
  ps                   Show containers and health.
  sh                   Interactive shell in the app container.
  fresh                migrate:fresh --seed on the compose database.
  config               Validate and print the merged compose config.
'@
    }

    'init' {
        if (-not (Test-Path '.env')) {
            Write-Host '[init] .env missing -- copying .env.example' -ForegroundColor Yellow
            Copy-Item '.env.example' '.env'
            Write-Host '[init] Set APP_KEY (php artisan key:generate) before serving.' -ForegroundColor Yellow
        } else {
            Write-Host '[init] .env present.'
        }

        Write-Host '[init] Installing composer dependencies into the dev-vendor volume...' -ForegroundColor Cyan
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('--profile', 'tools', 'run', '--rm', 'composer', 'install', '--no-interaction', '--prefer-dist') + $Args)

        Write-Host '[init] Done. Next: ./scripts/dev.ps1 up' -ForegroundColor Green
    }

    'up' {
        # No profiles here: enabling `test` would start omnitrack-test-1 as well.
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('up', '-d') + $Args)
    }

    'build' {
        # `docker compose build` builds every unprofiled service. The test image
        # is built separately by `build` with the test profile, so this one stays
        # profile-free to avoid surprise rebuilds of the testing stage.
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('build') + $Args)
    }

    'down' {
        # Profile IS wanted here: the test container must be part of the project
        # model or `down` would leave omnitrack-test-1 behind.
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('down') + $Args)
    }

    'restart' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('down'))
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('up', '-d'))
    }

    'test' {
        # The `testing` target carries the dev dependencies; the app image does
        # not. The command is overridden here so `test` is one reproducible entry
        # point with no rebuild. Extra args pass straight through to artisan:
        #   ./scripts/dev.ps1 test                     -> full suite
        #   ./scripts/dev.ps1 test --filter=Harness    -> one test
        #
        # `--pull never` matters: the overlay declares only `image:` for this
        # service (no `build:`), so without it Docker would try the registry
        # first and fail with a confusing pull error rather than using the
        # locally built omnitrack-engine:test.
        if (-not (docker image inspect omnitrack-engine:test 2>$null)) {
            throw "omnitrack-engine:test not found. Run './scripts/dev.ps1 build' once."
        }
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('run', '--rm', '--pull', 'never', 'test') + $Args)
    }

    'artisan' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('exec', 'app', 'php', 'artisan') + $Args)
    }

    'tinker' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('exec', 'app', 'php', 'artisan', 'tinker'))
    }

    'composer' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('--profile', 'tools', 'run', '--rm', 'composer') + $Args)
    }

    'npm' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('--profile', 'tools', 'run', '--rm', 'npm') + $Args)
    }

    'logs' {
        $service = if ($Args.Count -gt 0) { $Args[0] } else { 'app' }
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs (@('logs', '-f', '--tail=100', $service))
    }

    'ps' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('ps'))
    }

    'sh' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('exec', 'app', 'sh'))
    }

    'fresh' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('exec', 'app', 'php', 'artisan', 'migrate:fresh', '--seed'))
    }

    'config' {
        Invoke-Compose -Files (Get-DevFiles) -ComposeArgs ((Get-DevArgs) + @('config'))
    }
}

Pop-Location
