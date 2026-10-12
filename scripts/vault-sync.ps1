<#
.SYNOPSIS
    Push the Obsidian checklist ticks back to Omnitrack, and report graph health.

.DESCRIPTION
    The bridge is deliberately half-automatic:

      pull  running ./scripts/vault-notes.ps1 overwrites the checklist block in
            every project note from Omnitrack.
      push  ticking a box in that block and running this script with -Apply sends
            the change to Omnitrack through the harness API.

    Without -Apply this is a read-only report: it lists what the vault and
    Omnitrack disagree about, which notes nothing links to (Obsidian's "orphan"
    view), and which notes need regenerating. Nothing is written either way.

    WHY it is not a watcher: two processes writing the same field without a user
    decision is how a checklist silently loses a tick. One explicit command keeps
    the direction of every write obvious.

.EXAMPLE
    ./scripts/vault-sync.ps1            # report only
    ./scripts/vault-sync.ps1 -Apply     # push the ticks to Omnitrack
#>

[CmdletBinding()]
param(
    [switch]$Apply
)

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envPath = Join-Path $root '.env'
$api = 'http://127.0.0.1:8080/api/harness'
$utf8 = New-Object System.Text.UTF8Encoding($false)

$vault = (Select-String -Path $envPath -Pattern '^OBSIDIAN_VAULT_HOST_PATH=(.+)$').Matches.Groups[1].Value.Trim().Trim('"')
# .env writes the path with forward slashes; every path this script builds or
# compares must use the same separators as Get-ChildItem's FullName.
$vault = $vault.Replace('/', '\').TrimEnd('\')
$token = (Select-String -Path $envPath -Pattern '^HARNESS_SECRET_TOKEN=(.+)$').Matches.Groups[1].Value.Trim()

if (-not $vault -or -not (Test-Path -LiteralPath $vault)) { throw "Vault not found: $vault" }

$headers = @{ Authorization = "Bearer $token" }

# Module truth straight from the database: this is the only surface that has both
# the module id and its title per project.
$modulesJson = docker exec omnitrack-db psql -U omnitrack -d omnitrack -t -A -c "select coalesce(json_agg(json_build_object('id', id, 'project_id', project_id, 'title', title, 'status', status)), '[]') from project_modules;"
# WHY not @($json | ConvertFrom-Json): PowerShell 5.1's ConvertFrom-Json emits a
# top-level array as ONE object, so wrapping it in @() produces a one-element
# array holding the real array -- and every later property access then silently
# works on an array of values instead of one module.
$parsedModules = $modulesJson | ConvertFrom-Json
$allModules = @()
foreach ($parsedModule in @($parsedModules)) { $allModules += $parsedModule }
$modulesByProject = @{}

foreach ($module in $allModules) {
    if (-not $modulesByProject.ContainsKey($module.project_id)) { $modulesByProject[$module.project_id] = @() }
    $modulesByProject[$module.project_id] += $module
}

$notes = @(Get-ChildItem -LiteralPath $vault -Recurse -File -Filter *.md -Force |
    Where-Object { $_.FullName -notmatch '\\Templates\\' -and $_.Name -ne 'Welcome.md' })

$pushed = 0
$differences = @()
$unknown = @()
$incomplete = @()
$linkedTargets = @{}
$notePaths = @{}

foreach ($note in $notes) {
    $relative = $note.FullName.Substring($vault.Length + 1).Replace('\', '/')
    $notePaths[$relative] = $true
}

foreach ($note in $notes) {
    $relative = $note.FullName.Substring($vault.Length + 1).Replace('\', '/')
    $raw = Get-Content -LiteralPath $note.FullName -Raw

    # Collect every wikilink in the vault, so the orphan check can be answered
    # without loading the whole graph into Obsidian.
    foreach ($link in [regex]::Matches($raw, '\[\[([^\]\|#]+)')) {
        $target = $link.Groups[1].Value.Trim().TrimEnd('/')

        if ($target -eq '') { continue }

        $linkedTargets[$target] = $true
        $linkedTargets[($target -replace '\.md$', '')] = $true
        $linkedTargets[[System.IO.Path]::GetFileName($target)] = $true
    }
}

foreach ($note in $notes) {
    $relative = $note.FullName.Substring($vault.Length + 1).Replace('\', '/')
    $raw = Get-Content -LiteralPath $note.FullName -Raw
    $idHit = [regex]::Match($raw, '(?m)^omnitrack_id:\s*(\S+)')

    if (-not $idHit.Success) { continue }

    $projectId = $idHit.Groups[1].Value.Trim()
    $titleHit = [regex]::Match($raw, '(?m)^title:\s*"?([^"\r\n]+)"?')
    $title = if ($titleHit.Success) { $titleHit.Groups[1].Value.Trim() } else { $relative }

    $block = [regex]::Match($raw, '(?s)<!--\s*omnitrack:modules\s*-->(.*?)<!--\s*/omnitrack:modules\s*-->')

    if (-not $block.Success) { continue }

    $inNote = @()
    foreach ($line in ($block.Groups[1].Value -split "`n")) {
        $tick = [regex]::Match($line, '^\s*-\s*\[(x| )\]\s*(.+?)\s*$')

        if ($tick.Success) {
            $inNote += [pscustomobject]@{
                Title = $tick.Groups[2].Value.Trim()
                Done  = $tick.Groups[1].Value -eq 'x'
            }
        }
    }

    $projectModules = @()
    if ($modulesByProject.ContainsKey($projectId)) { $projectModules = @($modulesByProject[$projectId]) }

    if ($inNote.Count -ne $projectModules.Count) {
        $incomplete += ('{0} (catatan {1} modul, Omnitrack {2})' -f $title, $inNote.Count, $projectModules.Count)
    }

    foreach ($entry in $inNote) {
        $owner = @($projectModules | Where-Object { $_.title -eq $entry.Title })

        if ($owner.Count -eq 0) {
            $unknown += ('{0}: "{1}" tidak ada di Omnitrack' -f $title, $entry.Title)
            continue
        }

        $module = $owner[0]
        $moduleDone = $module.status -eq 'done'

        if ($moduleDone -eq $entry.Done) { continue }

        $wanted = if ($entry.Done) { 'done' } else { 'pending' }
        $differences += [pscustomobject]@{
            Note    = $relative
            Project = $title
            Module  = $module.title
            Id      = $module.id
            From    = $module.status
            To      = $wanted
        }
    }
}

if ($differences.Count -gt 0) {
    Write-Output ('perbedaan checklist : {0}' -f $differences.Count)

    foreach ($difference in $differences) {
        Write-Output ('  {0} :: {1} -> {2}' -f $difference.Project, $difference.Module, $difference.To)
    }

    if ($Apply) {
        foreach ($difference in $differences) {
            $body = @{ status = $difference.To } | ConvertTo-Json -Compress
            Invoke-RestMethod -Method Post -Uri "$api/modules/$($difference.Id)" -Headers $headers `
                -ContentType 'application/json; charset=utf-8' `
                -Body ([System.Text.Encoding]::UTF8.GetBytes($body)) | Out-Null
            $pushed++
        }

        Write-Output ('didorong ke Omnitrack : {0} modul' -f $pushed)
        Write-Output 'Jalankan ./scripts/vault-notes.ps1 untuk menarik status barunya kembali ke catatan.'
    } else {
        Write-Output 'Mode laporan saja. Tambahkan -Apply untuk mendorong perubahan ini ke Omnitrack.'
    }
} else {
    Write-Output 'perbedaan checklist : 0 (vault dan Omnitrack sepakat)'
}

if ($incomplete.Count -gt 0) {
    Write-Output ('checklist perlu regenerate ({0} catatan): {1}' -f $incomplete.Count, ($incomplete -join '; '))
}

if ($unknown.Count -gt 0) {
    Write-Output ('baris tidak dikenali ({0}): {1}' -f $unknown.Count, ($unknown -join '; '))
}

# --- ------------------------------------------------------------- graph health
$orphans = @()

foreach ($path in $notePaths.Keys) {
    $withoutExtension = $path -replace '\.md$', ''
    $base = [System.IO.Path]::GetFileName($withoutExtension)

    if ($linkedTargets.ContainsKey($path) -or $linkedTargets.ContainsKey($withoutExtension) -or $linkedTargets.ContainsKey($base)) {
        continue
    }

    $orphans += $path
}

Write-Output ('catatan              : {0}' -f $notePaths.Count)

if ($orphans.Count -gt 0) {
    Write-Output ('orphan (tanpa tautan masuk) : {0}' -f $orphans.Count)

    foreach ($orphan in ($orphans | Sort-Object)) { Write-Output ('  {0}' -f $orphan) }
} else {
    Write-Output 'orphan (tanpa tautan masuk) : 0'
}
