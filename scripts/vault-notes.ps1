<#
.SYNOPSIS
    Generate the Obsidian Second Brain notes from Omnitrack.

.DESCRIPTION
    Writes, into the vault named by OBSIDIAN_VAULT_HOST_PATH in .env:

      Projects/<Kategori>/<Project>.md   one note per project (mirror of Omnitrack)
      Areas/<Kategori>.md                one note per area, i.e. per category
      Projects/Projects MOC.md           index of every project
      Rencana/Fokus Saat Ini.md          what is actually open right now
      Journal/Rekap/<tahun>-W<minggu>.md weekly digest of the current ISO week
      Ide/Ide.md                         scratchpad index
      Home.md                            vault entry point
      Welcome.md                         pointer to Home (the stock Obsidian note)
      Templates/Harian.md, Mingguan.md   daily and weekly templates

    Two things matter about the generated files:

    1. Anything between <!-- omnitrack:keep --> and <!-- /omnitrack:keep --> is
       read back from the existing note and re-emitted, keyed by the section
       heading above it. That is where YOUR writing goes, and it survives every
       regeneration. Everything outside those markers is overwritten.
    2. Every generated note carries `generated: vault-notes` in its frontmatter,
       which is how a stale note is recognised and replaced. A note you write
       yourself has no such marker and is never touched.

    The module checklist block (`<!-- omnitrack:modules -->`) is always written
    from Omnitrack: it is the pull side of the bridge. Ticking a box there and
    running scripts/vault-sync.ps1 is the push side.

.EXAMPLE
    ./scripts/vault-notes.ps1
#>

param(
    # Rewrite only the project notes whose area, "Area/Project" or name matches.
    # WHY: a full run rescans every project folder on disk, which is the slowest
    # thing this repository does; refreshing one note should not cost that.
    [string[]]$Only = @(),

    # Ignore the on-disk layout cache and rescan every folder.
    [switch]$Fresh
)

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envPath = Join-Path $root '.env'
$api = 'http://127.0.0.1:8080/api/harness'
$web = 'http://127.0.0.1:8080'
$stamp = (Get-Date).ToString('yyyy-MM-dd')
$utf8 = New-Object System.Text.UTF8Encoding($false)

# ------------------------------------------------------------ keep cache ------
# A durable copy of every keep block this run reads.
#
# WHY on disk: the run DELETES every generated note during cleanup and writes them
# back afterwards, so hand-written prose exists only in memory in between. A crash
# in that window destroyed it once for real -- a bug at the focus section killed the
# run after Home.md was deleted, and its keep block (three links) was gone with no
# copy anywhere. Saving the cache before anything is written back removes that
# window: the next run restores prose for a file that no longer exists.
$keepStorePath = Join-Path $env:USERPROFILE '.dsh\cache\omnitrack-keep.json'
$keepStore = @{}

if (Test-Path -LiteralPath $keepStorePath) {
    try {
        $storedKeeps = Get-Content -LiteralPath $keepStorePath -Raw | ConvertFrom-Json
        foreach ($property in $storedKeeps.PSObject.Properties) {
            $bag = @{}
            foreach ($heading in $property.Value.PSObject.Properties) { $bag[$heading.Name] = [string]$heading.Value }
            $keepStore[$property.Name] = $bag
        }
    } catch {
        Write-Output "cache keep tidak terbaca, mulai dari kosong: $($_.Exception.Message)"
    }
}

function Save-KeepStore {
    if ($keepStore.Count -eq 0) { return }

    $dir = Split-Path -Parent $keepStorePath
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }

    [System.IO.File]::WriteAllText($keepStorePath, ($keepStore | ConvertTo-Json -Depth 6), $utf8)
}

$vault = (Select-String -Path $envPath -Pattern '^OBSIDIAN_VAULT_HOST_PATH=(.+)$').Matches.Groups[1].Value.Trim().Trim('"')
# .env writes the path with forward slashes; every path this script builds or
# compares must use the same separators as Get-ChildItem's FullName.
$vault = $vault.Replace('/', '\').TrimEnd('\')

if (-not $vault) { throw 'OBSIDIAN_VAULT_HOST_PATH not found in .env' }
if (-not (Test-Path -LiteralPath $vault)) { throw "Vault not found: $vault" }

$token = (Select-String -Path $envPath -Pattern '^HARNESS_SECRET_TOKEN=(.+)$').Matches.Groups[1].Value.Trim()
$headers = @{ Authorization = "Bearer $token" }

$statusLabel = @{ pending = 'Belum mulai'; in_progress = 'Jalan'; done = 'Selesai' }
$priorityLabel = @{ low = 'rendah'; medium = 'sedang'; high = 'tinggi' }

$areaIntro = @{
    'Collage'      = 'Perkuliahan di Ubaya: tugas per semester dan Tugas Akhir (deteksi melanoma).'
    'Panit'        = 'Kepanitiaan kampus, terutama MOB FT 2026: web, aset, dan game rally.'
    'Private'      = 'Project pribadi di luar kuliah dan kerja: automasi, eksperimen ML, dan Omnitrack sendiri.'
    'PT SPIL'      = 'Magang dan kerja di PT Salam Pacific Indonesia Lines: analisis data operasional, portal internal, dan tooling AI.'
    'Testing Zone' = 'Zona coba-coba: latihan, prototipe, dan eksperimen yang boleh gagal.'
    'Ubaya'        = 'Pekerjaan web kampus (WordPress): plugin dan tema.'
}

$techTags = [ordered]@{
    'laravel' = 'laravel'; 'php' = 'php'; 'python' = 'python'; 'svelte' = 'svelte';
    'vue' = 'vue'; 'next.js' = 'nextjs'; 'angular' = 'angular'; 'ionic' = 'ionic';
    'rust' = 'rust'; 'postgres' = 'postgres'; 'mysql' = 'mysql'; 'docker' = 'docker';
    'fastapi' = 'fastapi'; 'flask' = 'flask'; 'playwright' = 'playwright'; 'excel' = 'excel';
    'jupyter' = 'jupyter'; 'notebook' = 'jupyter'; 'tensorflow' = 'tensorflow';
    'keras' = 'keras'; 'deep learning' = 'deep-learning'; 'cnn' = 'cnn'; 'unity' = 'unity';
    'wordpress' = 'wordpress'; 'vite' = 'vite'; 'typescript' = 'typescript';
    'node' = 'nodejs'; 'esp32' = 'esp32'; 'arduino' = 'arduino'; 'github actions' = 'github-actions';
    'telegram' = 'telegram'; 'dmaic' = 'dmaic'; 'forecast' = 'forecasting';
    'data mining' = 'data-mining'; 'scraping' = 'scraping'; 'prisma' = 'prisma'
}

$junkDirs = @('node_modules', 'vendor', '.git', '.angular', '.vscode', '.next', '.svelte-kit',
              '__pycache__', '.phpunit.cache', '.turbo', '.vs', 'dist', 'build', 'venv', '.venv',
              '.claude', '.agents', '.kiro', '.omp', 'catboost_info', '.pytest_cache',
              'Library', 'obj', 'Temp', 'bin', 'target', '.gradle', '.idea')
$junkRx = 'node_modules|\\vendor\\|\\\.git\\|__pycache__|\\\.venv\\|\\venv\\|\\dist\\|\\build\\|\\\.next\\|\\\.svelte-kit\\|\\\.angular\\|\\\.vs\\|\\\.vscode\\|\\Library\\|\\obj\\|\\Temp\\|\\bin\\|\\target\\|\\\.gradle\\|\\\.idea\\'

function ConvertTo-SafeName([string]$name) {
    $safe = $name -replace '[\\/:*?"<>|#^\[\]]', '-'

    return ($safe -replace '\s+', ' ').Trim()
}

function ConvertTo-Slug([string]$name) {
    return (($name.ToLower() -replace '[^a-z0-9]+', '-').Trim('-'))
}

function Get-TechTags([string]$text) {
    $lower = $text.ToLower()
    $found = New-Object System.Collections.Generic.List[string]

    foreach ($key in $techTags.Keys) {
        if ($lower.Contains($key) -and -not $found.Contains($techTags[$key])) { $found.Add($techTags[$key]) }
    }

    return $found
}

function Get-IsoWeek([datetime]$date) {
    # Windows PowerShell 5.1 has no [System.Globalization.ISOWeek], so this is the
    # standard "week of the Thursday" computation.
    $isoDay = if ($date.DayOfWeek -eq 'Sunday') { 7 } else { [int]$date.DayOfWeek }
    $thursday = $date.AddDays(4 - $isoDay)
    $year = $thursday.Year
    $jan4 = Get-Date -Year $year -Month 1 -Day 4
    $jan4IsoDay = if ($jan4.DayOfWeek -eq 'Sunday') { 7 } else { [int]$jan4.DayOfWeek }
    $week1Thursday = $jan4.AddDays(4 - $jan4IsoDay)
    $number = [int][math]::Round((($thursday - $week1Thursday).Days / 7)) + 1

    return @{ Year = $year; Week = $number; Key = '{0}-W{1:d2}' -f $year, $number }
}

# Modules per project. WHY a function with the comma operator: a bare empty array
# collapses to $null when it travels through a property, and @($null) is a ONE-element
# array whose element fails every later $_.status check.,@() keeps the shape.
function Get-Modules([string]$projectId) {
    if ($modulesByProject.ContainsKey($projectId)) { return ,@($modulesByProject[$projectId]) }

    return ,@()
}

# WHY this exists next to Get-Modules: piping that wrapped shape into Where-Object
# collapsed a single module to a scalar, and a scalar PSCustomObject has no .Count,
# so "- N modul terbuka" printed an empty number for every project with exactly one
# open module. Unwrapping one level first gives callers a plain array they can pipe
# and count normally, for zero, one or many modules.
function Get-ModuleList([string]$projectId) {
    $list = New-Object System.Collections.Generic.List[object]

    foreach ($item in (Get-Modules $projectId)) {
        foreach ($module in @($item)) { $list.Add($module) }
    }

    return $list.ToArray()
}
# ------------------------------------------------------------ layout cache ---
# WHY a cache: the folder inventory needs a recursive walk of every project, and
# those walks are the entire cost of a run (tens of thousands of files across
# TA/Model, MOB FT and Testing Zone). The signature is deliberately cheap -- only
# top-level entries, their mtimes and their sizes -- so reusing an entry costs one
# shallow listing instead of a tree walk. A file created deep inside an unchanged
# top-level folder does not bump that folder's mtime, so a nested COUNT can lag
# until the next -Fresh; names, sizes and totals stay correct.
$cachePath = Join-Path $env:USERPROFILE '.dsh\cache\omnitrack-vault-layout.json'
$layoutStore = @{}
$cacheHits = 0
$cacheMisses = 0
$utf8 = New-Object System.Text.UTF8Encoding($false)

# ------------------------------------------------------------ keep cache ------
# A durable copy of every keep block this run reads.
#
# WHY on disk: the run DELETES every generated note during cleanup and writes them
# back afterwards, so hand-written prose exists only in memory in between. A crash
# in that window destroyed it once for real -- a bug at the focus section killed the
# run after Home.md was deleted, and its keep block (three links) was gone with no
# copy anywhere. Saving the cache before anything is written back removes that
# window: the next run restores prose for a file that no longer exists.
$keepStorePath = Join-Path $env:USERPROFILE '.dsh\cache\omnitrack-keep.json'
$keepStore = @{}

if (Test-Path -LiteralPath $keepStorePath) {
    try {
        $storedKeeps = Get-Content -LiteralPath $keepStorePath -Raw | ConvertFrom-Json
        foreach ($property in $storedKeeps.PSObject.Properties) {
            $bag = @{}
            foreach ($heading in $property.Value.PSObject.Properties) { $bag[$heading.Name] = [string]$heading.Value }
            $keepStore[$property.Name] = $bag
        }
    } catch {
        Write-Output "cache keep tidak terbaca, mulai dari kosong: $($_.Exception.Message)"
    }
}

function Save-KeepStore {
    if ($keepStore.Count -eq 0) { return }

    $dir = Split-Path -Parent $keepStorePath
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }

    [System.IO.File]::WriteAllText($keepStorePath, ($keepStore | ConvertTo-Json -Depth 6), $utf8)
}

if (-not $Fresh -and (Test-Path -LiteralPath $cachePath)) {
    try {
        $stored = Get-Content -LiteralPath $cachePath -Raw | ConvertFrom-Json
        foreach ($property in $stored.PSObject.Properties) { $layoutStore[$property.Name] = $property.Value }
    } catch {
        Write-Output "cache layout tidak terbaca, memindai ulang: $($_.Exception.Message)"
    }
}

function Get-DirSignature([string]$path) {
    $parts = New-Object System.Collections.Generic.List[string]

    foreach ($entry in (Get-ChildItem -LiteralPath $path -Force -ErrorAction SilentlyContinue)) {
        $parts.Add(('{0}|{1}|{2}' -f $entry.Name, $entry.LastWriteTimeUtc.Ticks, $(if ($entry.PSIsContainer) { 'd' } else { $entry.Length })))
    }

    return ($parts -join ';')
}

function Get-LayoutCached([string]$path) {
    if (-not (Test-Path -LiteralPath $path)) { return $null }

    $signature = Get-DirSignature $path

    if (-not $Fresh -and $layoutStore.ContainsKey($path) -and $layoutStore[$path].Signature -eq $signature) {
        $script:cacheHits++
        return $layoutStore[$path].Layout
    }

    $script:cacheMisses++
    $layout = Get-DiskLayout $path

    if ($layout) {
        $layoutStore[$path] = [pscustomobject]@{ Signature = $signature; Scanned = (Get-Date).ToString('s'); Layout = $layout }
    }

    return $layout
}

function Save-LayoutCache {
    if ($layoutStore.Count -eq 0) { return }

    $dir = Split-Path -Parent $cachePath
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }

    [System.IO.File]::WriteAllText($cachePath, ($layoutStore | ConvertTo-Json -Depth 8), $utf8)
}

function Test-OnlyMatch([string]$category, [string]$name, [string[]]$filters) {
    if ($filters.Count -eq 0) { return $true }

    foreach ($filter in $filters) {
        $needle = $filter.Trim()
        if ($needle -eq '') { continue }
        if ($category -ieq $needle) { return $true }
        if (('{0}/{1}' -f $category, $name) -ieq $needle) { return $true }
        if ($name -like ('*' + $needle + '*')) { return $true }
    }

    return $false
}
function Get-DiskLayout([string]$path) {
    if (-not (Test-Path -LiteralPath $path)) { return $null }

    $all = Get-ChildItem -LiteralPath $path -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch $junkRx }

    $entries = @()
    foreach ($dir in (Get-ChildItem -LiteralPath $path -Directory -Force -ErrorAction SilentlyContinue |
                      Where-Object { $_.Name -notin $junkDirs })) {
        $needle = $dir.Name + '\'
        $count = 0
        foreach ($file in $all) {
            if ($file.FullName.Substring($path.Length + 1).StartsWith($needle)) { $count++ }
        }
        $entries += [pscustomobject]@{ Name = $dir.Name; Files = $count }
    }

    return [pscustomobject]@{
        Dirs  = @($entries | Sort-Object -Property Files -Descending)
        Files = @(Get-ChildItem -LiteralPath $path -File -Force -ErrorAction SilentlyContinue |
            Select-Object -First 10 -ExpandProperty Name)
        Total = $all.Count
    }
}

# Read the keep-blocks of an existing generated note, keyed by the ## heading
# above each one, so hand-written prose survives a regeneration.
function Get-KeptSections([string]$notePath) {
    $kept = @{}

    if (-not (Test-Path -LiteralPath $notePath)) { return $kept }

    $raw = Get-Content -LiteralPath $notePath -Raw
        # WHY the tempered part `(?:(?!^##[ \t])[\s\S])*?`: a plain `.*?` walks past
    # every intermediate heading, so the block would be attributed to the FIRST
    # ## in the file instead of the one directly above it -- which silently moved
    # every hand-written paragraph into the wrong section.
    # WHY the markers must stand alone on their line: Home's own prose QUOTES the
    # markers as inline code. A pattern that accepted them anywhere treated that
    # quoted pair as a real block, swallowed the genuine block under `## Catatan`
    # into a 421-character body attributed to another heading, and silently dropped
    # it -- Home's keep content was destroyed on EVERY run, not just after a crash.
    $found = [regex]::Matches($raw, '(?ms)^##[ \t]+([^\r\n]+)[ \t]*\r?\n(?:(?!^##[ \t])[\s\S])*?^[ \t]*<!--\s*omnitrack:keep\s*-->[ \t]*\r?\n(.*?)^[ \t]*<!--\s*/omnitrack:keep\s*-->[ \t]*(?:\r?\n|$)')

    foreach ($hit in $found) {
        $kept[$hit.Groups[1].Value.Trim()] = $hit.Groups[2].Value.Trim()
    }

    return $kept
}

function Get-NoteKey([string]$path) {
    return [System.IO.Path]::GetFullPath($path).ToLowerInvariant()
}

function Get-KeptSectionsFrom([string]$notePath, $cache) {
    $key = Get-NoteKey $notePath
    if ($cache.ContainsKey($key)) { return $cache[$key] }

    if (Test-Path -LiteralPath $notePath) {
        $kept = Get-KeptSections $notePath
        if ($kept.Count -gt 0) { $keepStore[$key] = $kept }
        return $kept
    }

    # The file is gone: a stale note, or a previous run died after deleting it.
    # The durable cache is then the only copy of the prose left.
    if ($keepStore.ContainsKey($key)) { return $keepStore[$key] }

    return @{}
}

function Add-KeepSection($lines, [string]$title, $kept, [string]$placeholder) {
    $lines.Add("## $title")
    $lines.Add('')
    $lines.Add('<!-- omnitrack:keep -->')

    $body = if ($kept.ContainsKey($title) -and $kept[$title]) { $kept[$title] } else { $placeholder }

    foreach ($line in ($body -split "`n")) { $lines.Add($line.TrimEnd("`r")) }

    $lines.Add('<!-- /omnitrack:keep -->')
    $lines.Add('')
}

function Write-Note([string]$path, $lines) {
    $dir = Split-Path -Parent $path

    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }

    [System.IO.File]::WriteAllText($path, ($lines -join "`r`n"), $utf8)
}

# --- ------------------------------------------------------------------ data
# The projects come from /board/projects and not from /api/harness/context: the
# harness payload is deliberately compact (an LLM window) and carries no
# description, project_owner, target_user or doc_link, which a note needs.
$email = (Select-String -Path $envPath -Pattern '^OMNITRACK_USER_EMAIL=(.+)$').Matches.Groups[1].Value.Trim()
$password = (Select-String -Path $envPath -Pattern '^OMNITRACK_USER_PASSWORD=(.+)$').Matches.Groups[1].Value.Trim()
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest "$web/login" -WebSession $session -UseBasicParsing
$csrf = [regex]::Match($loginPage.Content, 'name="_csrf_token"\s+value="([^"]+)"').Groups[1].Value

if (-not $csrf) {
    $csrf = [regex]::Match($loginPage.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
}

Invoke-WebRequest "$web/login" -Method Post -WebSession $session -UseBasicParsing `
    -Body @{ _token = $csrf; email = $email; password = $password } | Out-Null

$projects = @((Invoke-RestMethod "$web/board/projects" -WebSession $session).projects)
$categories = @((Invoke-RestMethod -Uri "$api/categories" -Headers $headers).categories)

# Module detail and module timestamps exist only in the database: the harness
# payload drops the description and the board only counts modules.
$modulesJson = docker exec omnitrack-db psql -U omnitrack -d omnitrack -t -A -c "select coalesce(json_agg(json_build_object('project_id', project_id, 'id', id, 'title', title, 'description', description, 'status', status, 'priority', priority, 'order_index', order_index, 'updated_at', to_char(updated_at, 'YYYY-MM-DD')) order by order_index), '[]') from project_modules;"
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

$categoryOrder = @{}
$index = 0
foreach ($category in $categories) { $categoryOrder[$category.name] = $index; $index++ }

foreach ($project in $projects) {
    $categoryName = [string]($project.category.name)
    $safeName = ConvertTo-SafeName $project.name
    $project | Add-Member -NotePropertyName categoryName -NotePropertyValue $categoryName -Force
    $project | Add-Member -NotePropertyName _cat -NotePropertyValue ([int]$categoryOrder[$categoryName]) -Force
    # (module lists come from Get-Modules; nothing is cached on the project object)
    $project | Add-Member -NotePropertyName notePath -NotePropertyValue ('Projects/{0}/{1}' -f $categoryName, $safeName) -Force
}

$week = Get-IsoWeek (Get-Date)

# --- --------------------------------------------------------------- cleanup
# Replace only what this script wrote: a note carrying `generated: vault-notes`,
# and nothing else. The first version also matched omnitrack_id and the MOC
# title, so a note that merely documents this vault could be deleted. Templates/
# is skipped as well: those are files to copy, not notes to refresh.
$stale = @()

foreach ($file in $(if ($Only.Count -eq 0) { Get-ChildItem -LiteralPath $vault -Recurse -File -Filter *.md -Force } else { @() })) {
    if ($file.FullName -match '\\Templates\\') { continue }

    $head = (Get-Content -LiteralPath $file.FullName -TotalCount 30 -ErrorAction SilentlyContinue) -join "`n"

    # WHY the marker only: the old heuristic also deleted any note whose first 30
    # lines merely QUOTED omnitrack_id or the MOC title, so a hand-written note
    # documenting this vault was a landmine.
    if ($head -match 'generated: vault-notes') {
        $stale += $file.FullName
    }
}

# Read the hand-written sections BEFORE deleting the file: once cleanup has run,
# the writer has no note left to read them from, and every keep-block would come
# back empty.
$keptCache = @{}
foreach ($path in $stale) {
    $key = Get-NoteKey $path
    $keptCache[$key] = Get-KeptSections $path
    $keepStore[$key] = $keptCache[$key]
    Remove-Item -LiteralPath $path -Force
}

# Written BEFORE the first note is generated, on purpose: from here on the prose is
# recoverable even if this process dies mid-run (see the keep cache block above).
Save-KeepStore

foreach ($dir in $(if ($Only.Count -eq 0) { Get-ChildItem -LiteralPath $vault -Recurse -Directory -Force |
                  Where-Object { $_.Name -notin @('.obsidian', 'Templates') } |
                  Sort-Object { $_.FullName.Length } -Descending } else { @() })) {
    if (-not (Get-ChildItem -LiteralPath $dir.FullName -Force)) { Remove-Item -LiteralPath $dir.FullName -Force }
}

# --- -------------------------------------------------------- project notes
$written = 0

$selected = @($projects | Where-Object { Test-OnlyMatch $_.categoryName $_.name $Only })

if ($Only.Count -gt 0 -and $selected.Count -eq 0) {
    throw ('Tidak ada project yang cocok dengan -Only: ' + ($Only -join ', '))
}

foreach ($project in ($selected | Sort-Object _cat, order_index)) {
    $notePath = Join-Path $vault ($project.notePath + '.md')
    $kept = Get-KeptSectionsFrom $notePath $keptCache

    $description = [string]$project.description
    $diskPath = $null
    $pathHit = [regex]::Match($description, 'Path:\s*(.+?)\s*$')

    if ($pathHit.Success) {
        $diskPath = $pathHit.Groups[1].Value.Trim()
        $description = $description.Substring(0, $pathHit.Index).Trim().TrimEnd('.')
    }

    $modules = Get-Modules $project.id
    $moduleText = (@($modules) | ForEach-Object { $_.title + ' ' + $_.description }) -join ' '
    $tags = @('type/project', ('area/' + (ConvertTo-Slug $project.categoryName)), ('status/' + $project.status)) +
        (Get-TechTags ($project.name + ' ' + $description + ' ' + $moduleText))
    $tags = @($tags | Select-Object -Unique)

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('---')
    $lines.Add('title: "{0}"' -f $project.name.Replace('"', '\"'))
    $lines.Add('category: {0}' -f $project.categoryName)
    $lines.Add('area: {0}' -f (ConvertTo-Slug $project.categoryName))
    $lines.Add('status: {0}' -f $project.status)
    if ($project.my_role) { $lines.Add('my_role: "{0}"' -f $project.my_role.Replace('"', '\"')) }
    if ($project.department) { $lines.Add('department: "{0}"' -f $project.department.Replace('"', '\"')) }
    if ($project.project_owner) { $lines.Add('project_owner: "{0}"' -f $project.project_owner.Replace('"', '\"')) }
    if ($project.target_user) { $lines.Add('target_user: "{0}"' -f $project.target_user.Replace('"', '\"')) }
    if ($diskPath) { $lines.Add('path: "{0}"' -f $diskPath.Replace('\', '\\').Replace('"', '\"')) }
    if ($project.doc_link) { $lines.Add('repo: {0}' -f $project.doc_link) }
    $lines.Add('tags: [{0}]' -f ($tags -join ', '))
    $lines.Add('omnitrack_id: {0}' -f $project.id)
    $lines.Add('generated: vault-notes')
    $lines.Add('synced: {0}' -f $stamp)
    $lines.Add('---')
    $lines.Add('')
    $lines.Add('# {0}' -f $project.name)
    $lines.Add('')
    if ($description) { $lines.Add('> {0}' -f $description); $lines.Add('') }

    $lines.Add('## Peran & konteks')
    $lines.Add('')
    $lines.Add('| Field | Nilai |')
    $lines.Add('| --- | --- |')
    $lines.Add(('| Area | [[Areas/{0}\|{0}]] |' -f $project.categoryName))
    $lines.Add(('| Status | {0} (`{1}`) |' -f $statusLabel[$project.status], $project.status))
    if ($project.my_role) { $lines.Add('| Peran saya | {0} |' -f $project.my_role) }
    if ($project.department) { $lines.Add('| Departemen | {0} |' -f $project.department) }
    if ($project.project_owner) { $lines.Add('| Pemilik | {0} |' -f $project.project_owner) }
    if ($project.target_user) { $lines.Add('| Pengguna | {0} |' -f $project.target_user) }
    if ($diskPath) { $lines.Add('| Lokasi | `{0}` |' -f $diskPath) }
    if ($project.doc_link) { $lines.Add('| Repo | {0} |' -f $project.doc_link) }
    $lines.Add('| Progress | {0}% |' -f $project.progress)
    $lines.Add('')

    if ($modules.Count -gt 0) {
        $lines.Add('## Modul (mirror Omnitrack)')
        $lines.Add('')
        $lines.Add('| Modul | Status | Prioritas | Detail |')
        $lines.Add('| --- | --- | --- | --- |')
        foreach ($module in $modules) {
            $detail = [string]$module.description
            $detailHit = [regex]::Match($detail, 'Path:\s*(.+?)\s*$')
            if ($detailHit.Success) { $detail = $detail.Substring(0, $detailHit.Index).Trim().TrimEnd('.') }
            $lines.Add(('| {0} | {1} | {2} | {3} |' -f $module.title, $statusLabel[$module.status], $priorityLabel[$module.priority], $detail.Replace('|', '\|')))
        }
        $lines.Add('')
        $lines.Add('### Checklist (centang di sini, lalu jalankan scripts/vault-sync.ps1)')
        $lines.Add('')
        $lines.Add('<!-- omnitrack:modules -->')
        foreach ($module in $modules) {
            $box = if ($module.status -eq 'done') { 'x' } else { ' ' }
            $lines.Add(('- [{0}] {1}' -f $box, $module.title))
        }
        $lines.Add('<!-- /omnitrack:modules -->')
        $lines.Add('')
        $lines.Add('_Tabel dan checklist di atas salinan dari Omnitrack. Centang di sini lalu jalankan_')
        $lines.Add('_`./scripts/vault-sync.ps1 -Apply` untuk mendorongnya ke Omnitrack; menjalankan ulang_')
        $lines.Add('_generator akan menarik state Omnitrack dan menimpa centangnya._')
        $lines.Add('')
    }

    if ($diskPath) {
        $layout = Get-LayoutCached $diskPath
        if ($layout) {
            $lines.Add('## Isi folder di disk')
            $lines.Add('')
            foreach ($dir in $layout.Dirs) { $lines.Add(('- `{0}/` - {1} berkas' -f $dir.Name, $dir.Files)) }
            foreach ($file in $layout.Files) { $lines.Add('- `{0}`' -f $file) }
            $lines.Add('')
            $lines.Add('_Total {0} berkas, dihitung tanpa node_modules/vendor/.git dan tanpa hasil build (Library, bin, obj, target, venv)._' -f $layout.Total)
            $lines.Add('')
        }
    }

    Add-KeepSection $lines 'Catatan' $kept '<!-- Tulisanmu di sini: ide, keputusan, langkah berikutnya. -->'

    $lines.Add('## Lihat juga')
    $lines.Add('')
    $lines.Add('- [[Home]]')
    $lines.Add(('- [[Areas/{0}|{0}]]' -f $project.categoryName))
    $lines.Add('- [[Projects/Projects MOC|Projects MOC]]')
    $siblings = @($projects | Where-Object { $_.categoryName -eq $project.categoryName -and $_.id -ne $project.id } |
        Sort-Object order_index | Select-Object -First 8)
    foreach ($sibling in $siblings) {
        $lines.Add(('- [[{0}|{1}]]' -f $sibling.notePath, $sibling.name))
    }
    $lines.Add('')

    Write-Note $notePath $lines
    $written++
}

# A filtered run stops here: Areas/, MOC, Home, Rencana, Ide, the journal and the
# templates all describe the whole workspace, so rewriting them from a partial
# project set would be worse than leaving them one run behind.
if ($Only.Count -gt 0) {
    Save-LayoutCache
    Write-Output ('project notes : {0} (hanya yang cocok dengan -Only; note lain tidak disentuh)' -f $written)
    Write-Output ('layout cache  : {0} dipakai ulang, {1} dipindai' -f $cacheHits, $cacheMisses)
    return
}
# --- ----------------------------------------------------------- area notes
$areaWritten = 0

foreach ($group in ($projects | Group-Object categoryName | Sort-Object { $categoryOrder[$_.Name] })) {
    $category = $group.Name
    $areaPath = Join-Path (Join-Path $vault 'Areas') ($category + '.md')
    $kept = Get-KeptSectionsFrom $areaPath $keptCache
    $slug = ConvertTo-Slug $category
    $members = @($group.Group | Sort-Object order_index)

    $done = @($members | Where-Object status -eq 'done').Count
    $running = @($members | Where-Object status -eq 'in_progress').Count
    $pending = @($members | Where-Object status -eq 'pending').Count
    $openModules = @($members | ForEach-Object { Get-ModuleList $_.id | Where-Object { $_.status -ne 'done' } })

    $techCounts = @{}
    foreach ($member in $members) {
        $techText = $member.name + ' ' + $member.description + ' ' + ((Get-Modules $member.id | ForEach-Object { $_.title }) -join ' ')
        foreach ($tag in (Get-TechTags $techText)) {
            if ($techCounts.ContainsKey($tag)) { $techCounts[$tag]++ } else { $techCounts[$tag] = 1 }
        }
    }

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('---')
    $lines.Add('title: "Area - {0}"' -f $category)
    $lines.Add('type: area')
    $lines.Add('area: {0}' -f $slug)
    $lines.Add('tags: [type/area, area/{0}]' -f $slug)
    $lines.Add('generated: vault-notes')
    $lines.Add('synced: {0}' -f $stamp)
    $lines.Add('---')
    $lines.Add('')
    $lines.Add('# {0}' -f $category)
    $lines.Add('')
    $lines.Add('> {0}' -f $(if ($areaIntro.ContainsKey($category)) { $areaIntro[$category] } else { 'Area project.' }))
    $lines.Add('')
    $lines.Add('## Ringkasan (dari Omnitrack)')
    $lines.Add('')
    $lines.Add('| Project | Selesai | Jalan | Belum | Modul terbuka |')
    $lines.Add('| --- | --- | --- | --- | --- |')
    $lines.Add(('| {0} | {1} | {2} | {3} | {4} |' -f $category, $done, $running, $pending, $openModules.Count))
    $lines.Add('')

    $lines.Add('## Project')
    $lines.Add('')
    $lines.Add('| Project | Status | Peran | Modul | Repo |')
    $lines.Add('| --- | --- | --- | --- | --- |')
    foreach ($member in $members) {
        $memberModules = Get-Modules $member.id
        $memberDone = @($memberModules | Where-Object { $_.status -eq 'done' }).Count
        $repo = if ($member.doc_link) { '[repo]({0})' -f $member.doc_link } else { '-' }
        $role = if ($member.my_role) { $member.my_role } else { '-' }
        $lines.Add(('| [[{0}\|{1}]] | {2} | {3} | {4}/{5} | {6} |' -f $member.notePath, $member.name, $statusLabel[$member.status], $role, $memberDone, $memberModules.Count, $repo))
    }
    $lines.Add('')

    if ($techCounts.Count -gt 0) {
        $lines.Add('## Teknologi yang muncul')
        $lines.Add('')
        foreach ($tech in ($techCounts.GetEnumerator() | Sort-Object -Property Value -Descending)) {
            $lines.Add(('- {0} ({1} project)' -f $tech.Key, $tech.Value))
        }
        $lines.Add('')
    }

    if ($openModules.Count -gt 0) {
        $lines.Add('## Yang masih terbuka')
        $lines.Add('')
        foreach ($member in $members) {
            $open = @(Get-ModuleList $member.id | Where-Object { $_.status -ne 'done' })
            if ($open.Count -eq 0) { continue }
            $lines.Add(('- [[{0}|{1}]]' -f $member.notePath, $member.name))
            foreach ($module in $open) {
                $lines.Add(('    - {0} ({1})' -f $module.title, $statusLabel[$module.status]))
            }
        }
        $lines.Add('')
    }

    Add-KeepSection $lines 'Peran saya' $kept '<!-- Apa peranmu di area ini, dan sejak kapan. -->'
    Add-KeepSection $lines 'Pencapaian' $kept '<!-- Yang sudah selesai dan layak dicatat: hasil, angka, umpan balik. -->'
    Add-KeepSection $lines 'Pelajaran' $kept '<!-- Yang kamu pelajari dan mau kamu ulang atau hindari. -->'
    Add-KeepSection $lines 'Kontak & referensi' $kept '<!-- Nama, peran, dan hal yang perlu diingat tentang orang atau dokumen di area ini. -->'

    $lines.Add('## Lihat juga')
    $lines.Add('')
    $lines.Add('- [[Home]]')
    $lines.Add('- [[Projects/Projects MOC|Projects MOC]]')
    $lines.Add('')

    Write-Note $areaPath $lines
    $areaWritten++
}

# --- ------------------------------------------------------------ index notes
$moc = New-Object System.Collections.Generic.List[string]
$moc.Add('---')
$moc.Add('title: "Projects MOC"')
$moc.Add('type: moc')
$moc.Add('tags: [type/moc]')
$moc.Add('generated: vault-notes')
$moc.Add('synced: {0}' -f $stamp)
$moc.Add('---')
$moc.Add('')
$moc.Add('# Projects MOC')
$moc.Add('')
$moc.Add('Indeks seluruh project. Angka dan status di sini adalah cerminan Omnitrack,')
$moc.Add('bukan sumber kebenaran: yang mengubah status tetap Omnitrack.')
$moc.Add('')
$moc.Add('| Area | Project | Selesai | Jalan | Belum | Modul |')
$moc.Add('| --- | --- | --- | --- | --- | --- |')
foreach ($group in ($projects | Group-Object categoryName | Sort-Object { $categoryOrder[$_.Name] })) {
    $groupDone = @($group.Group | Where-Object status -eq 'done').Count
    $groupRunning = @($group.Group | Where-Object status -eq 'in_progress').Count
    $groupPending = @($group.Group | Where-Object status -eq 'pending').Count
    $groupModules = (@($group.Group | ForEach-Object { (Get-ModuleList $_.id).Count }) | Measure-Object -Sum).Sum
    $moc.Add(('| [[Areas/{0}\|{0}]] | {1} | {2} | {3} | {4} | {5} |' -f $group.Name, $group.Count, $groupDone, $groupRunning, $groupPending, $groupModules))
}
$moc.Add('')
$moc.Add('## Daftar project')
$moc.Add('')
foreach ($group in ($projects | Group-Object categoryName | Sort-Object { $categoryOrder[$_.Name] })) {
    $moc.Add(('### [[Areas/{0}\|{0}]]' -f $group.Name))
    $moc.Add('')
    foreach ($project in ($group.Group | Sort-Object order_index)) {
        $role = if ($project.my_role) { ' - ' + $project.my_role } else { '' }
        $moc.Add(('- [[{0}|{1}]] - {2}{3}' -f $project.notePath, $project.name, $statusLabel[$project.status], $role))
    }
    $moc.Add('')
}
Write-Note (Join-Path (Join-Path $vault 'Projects') 'Projects MOC.md') $moc

$totalProjects = $projects.Count
$totalDone = @($projects | Where-Object status -eq 'done').Count
$totalModules = $allModules.Count
$openModulesAll = @($allModules | Where-Object { $_.status -ne 'done' })
$areaList = (($projects | Group-Object categoryName | Sort-Object { $categoryOrder[$_.Name] }).Name)

$homePath = Join-Path $vault 'Home.md'
$homeKept = Get-KeptSectionsFrom $homePath $keptCache
$homeLines = New-Object System.Collections.Generic.List[string]
$homeLines.Add('---')
$homeLines.Add('title: "Home"')
$homeLines.Add('type: moc')
$homeLines.Add('tags: [type/moc]')
$homeLines.Add('generated: vault-notes')
$homeLines.Add('synced: {0}' -f $stamp)
$homeLines.Add('---')
$homeLines.Add('')
$homeLines.Add('# Second Brain')
$homeLines.Add('')
$homeLines.Add('Catatan pribadi, dengan project sebagai tulang punggungnya karena di situlah')
$homeLines.Add('datanya sudah rapi di Omnitrack.')
$homeLines.Add('')
$homeLines.Add('## Peta isi')
$homeLines.Add('')
$homeLines.Add(('- [[Projects/Projects MOC|Projects MOC]] - {0} project, {1} selesai, {2} modul, {3} modul masih terbuka.' -f $totalProjects, $totalDone, $totalModules, $openModulesAll.Count))
foreach ($area in $areaList) { $homeLines.Add(('- [[Areas/{0}|{0}]]' -f $area)) }
$homeLines.Add('- [[Rencana/Fokus Saat Ini|Rencana - Fokus Saat Ini]] - apa yang benar-benar terbuka sekarang')
$homeLines.Add(('- [[Journal/Rekap/{0}|Rekap {0}]] - digest terbaru dari Omnitrack' -f $week.Key))
$homeLines.Add('- [[Ide/Ide|Ide]] - tempat menaruh ide sebelum jadi project')
$homeLines.Add('')
$homeLines.Add('## Cara vault ini dipakai')
$homeLines.Add('')
$homeLines.Add('- **Catatan project adalah cerminan Omnitrack** (Laravel,')
$homeLines.Add('  `D:\Projects\Private\Projects & Event Management`, http://127.0.0.1:8080).')
$homeLines.Add('  Status, peran, modul, dan path di-generate; mengubahnya di sini tidak')
$homeLines.Add('  mengubah Omnitrack.')
$homeLines.Add('- **Tulisanmu aman di dalam penanda.** Semua yang berada di antara')
$homeLines.Add('  `<!-- omnitrack:keep -->` dan `<!-- /omnitrack:keep -->` dibaca kembali lalu')
$homeLines.Add('  ditulis apa adanya saat catatan di-generate ulang. Semua di luar penanda itu')
$homeLines.Add('  akan ditimpa.')
$homeLines.Add('- **Checklist modul bisa didorong ke Omnitrack.** Centang kotak di bagian')
$homeLines.Add('  checklist sebuah project, lalu jalankan `./scripts/vault-sync.ps1 -Apply`.')
$homeLines.Add('  Menjalankan ulang generator berarti menarik state Omnitrack dan menimpa')
$homeLines.Add('  centang lokal.')
$homeLines.Add('')
$homeLines.Add('## Konvensi')
$homeLines.Add('')
$homeLines.Add('- Wikilink memakai path penuh (`[[Projects/PT SPIL/Forecast Container]]`) supaya')
$homeLines.Add('  nama yang mirip - mis. `collage` di Testing Zone dan area `Collage` - tidak')
$homeLines.Add('  pernah salah taut.')
$homeLines.Add('- Tag bertingkat: `type/project`, `type/area`, `type/moc`, `type/journal`;')
$homeLines.Add('  `area/pt-spil`; `status/done`. Tag teknologi tetap datar (`python`, `laravel`).')
$homeLines.Add('- Frontmatter tiap catatan memuat `omnitrack_id`, jadi catatan dan baris')
$homeLines.Add('  Omnitrack selalu bisa dipasangkan kembali.')
$homeLines.Add('')
Add-KeepSection $homeLines 'Catatan' $homeKept '<!-- Ruang bebas: apa pun yang tidak cocok di catatan lain. -->'
Write-Note $homePath $homeLines

$welcome = @'
---
title: "Welcome"
type: meta
tags: [type/meta]
---

Vault ini sudah dipakai. Mulai dari [[Home]].

Catatan ini sisa template Obsidian dan aman untuk dihapus.
'@
[System.IO.File]::WriteAllText((Join-Path $vault 'Welcome.md'), $welcome, $utf8)

# --- --------------------------------------------------- rencana dan ide notes
$focusPath = Join-Path (Join-Path $vault 'Rencana') 'Fokus Saat Ini.md'
$focusKept = Get-KeptSectionsFrom $focusPath $keptCache
$focus = New-Object System.Collections.Generic.List[string]
$focus.Add('---')
$focus.Add('title: "Fokus Saat Ini"')
$focus.Add('type: rencana')
$focus.Add('tags: [type/rencana]')
$focus.Add('generated: vault-notes')
$focus.Add('synced: {0}' -f $stamp)
$focus.Add('---')
$focus.Add('')
$focus.Add('# Fokus Saat Ini')
$focus.Add('')
$focus.Add('Di-generate dari Omnitrack: project yang statusnya jalan, plus semua modul yang')
$focus.Add('belum selesai. Urutannya mengikuti urutan papan, bukan prioritas.')
$focus.Add('')

$running = @($projects | Where-Object status -eq 'in_progress' | Sort-Object _cat, order_index)
$focus.Add(('## Project jalan ({0})' -f $running.Count))
$focus.Add('')
foreach ($project in $running) {
    $open = @(Get-ModuleList $project.id | Where-Object { $_.status -ne 'done' })
    $focus.Add(('- [[{0}|{1}]] - {2} modul terbuka' -f $project.notePath, $project.name, $open.Count))
}
$focus.Add('')

$focus.Add('## Modul terbuka')
$focus.Add('')
foreach ($project in $running) {
    $open = @(Get-ModuleList $project.id | Where-Object { $_.status -ne 'done' })
    if ($open.Count -eq 0) { continue }
    $focus.Add(('- [[{0}|{1}]]' -f $project.notePath, $project.name))
    foreach ($module in $open) {
        $focus.Add(('    - {0} `{1}` ({2})' -f $module.title, $module.status, $priorityLabel[$module.priority]))
    }
}
$focus.Add('')

$unbroken = @($projects | Where-Object { $_.status -ne 'done' -and (Get-ModuleList $_.id).Count -eq 0 } | Sort-Object _cat, order_index)
if ($unbroken.Count -gt 0) {
    $focus.Add('## Belum punya checklist')
    $focus.Add('')
    $focus.Add('Project ini belum dipecah jadi modul, jadi tidak ada yang bisa dicentang:')
    $focus.Add('')
    foreach ($project in $unbroken) { $focus.Add(('- [[{0}|{1}]] ({2})' -f $project.notePath, $project.name, $statusLabel[$project.status])) }
    $focus.Add('')
}

Add-KeepSection $focus 'Target bulan ini' $focusKept '<!-- Tulis 1-3 target yang bisa diukur. -->'
Add-KeepSection $focus 'Yang menghambat' $focusKept '<!-- Blocker, dan siapa atau apa yang bisa melepasnya. -->'
Add-KeepSection $focus 'Keputusan tertunda' $focusKept '<!-- Keputusan yang kamu tunda, dan kapan harus diambil. -->'
Add-KeepSection $focus 'Catatan' $focusKept '<!-- Lain-lain. -->'
Write-Note $focusPath $focus

$idePath = Join-Path (Join-Path $vault 'Ide') 'Ide.md'
$ideKept = Get-KeptSectionsFrom $idePath $keptCache
$ide = New-Object System.Collections.Generic.List[string]
$ide.Add('---')
$ide.Add('title: "Ide"')
$ide.Add('type: moc')
$ide.Add('tags: [type/moc]')
$ide.Add('generated: vault-notes')
$ide.Add('synced: {0}' -f $stamp)
$ide.Add('---')
$ide.Add('')
$ide.Add('# Ide')
$ide.Add('')
$ide.Add('Tempat menaruh ide sebelum menjadi project. Konvensinya sederhana:')
$ide.Add('')
$ide.Add('- Satu ide satu catatan di folder `Ide/`, dengan tag `#type/ide`.')
$ide.Add('- Sebutkan pemicunya dan langkah pertama yang paling kecil.')
$ide.Add('- Kalau idenya jadi dikerjakan, buat project di Omnitrack lalu tautkan')
$ide.Add('  catatannya dari sini; catatan ide tetap ada sebagai asal-usulnya.')
$ide.Add('')
$ide.Add('Catatan `Ide/` yang kamu tulis sendiri tidak pernah disentuh generator ini.')
$ide.Add('')
Add-KeepSection $ide 'Daftar ide' $ideKept '<!-- - [[Ide/Nama ide]] - satu baris tentang idenya. -->'
Add-KeepSection $ide 'Ide yang sudah jadi project' $ideKept '<!-- - [[Ide/Nama ide]] -> [[Projects/Kategori/Project]] -->'
Write-Note $idePath $ide

# --- ---------------------------------------------------------- weekly digest
$journalDir = Join-Path (Join-Path $vault 'Journal') 'Rekap'
$today = (Get-Date).Date
$weekStart = $today.AddDays(-(([int]$today.DayOfWeek + 6) % 7))
$weekEnd = $weekStart.AddDays(6)
$weekModules = @($allModules | Where-Object {
    $parsed = [datetime]::MinValue

    return [datetime]::TryParse($_.updated_at, [ref]$parsed) -and $parsed.Date -ge $weekStart -and $parsed.Date -le $weekEnd
})

$digestPath = Join-Path $journalDir ('{0}.md' -f $week.Key)
$digestKept = Get-KeptSectionsFrom $digestPath $keptCache
$digest = New-Object System.Collections.Generic.List[string]
$digest.Add('---')
$digest.Add('title: "Rekap {0}"' -f $week.Key)
$digest.Add('type: journal')
$digest.Add('week: {0}' -f $week.Key)
$digest.Add('tags: [type/journal]')
$digest.Add('generated: vault-notes')
$digest.Add('synced: {0}' -f $stamp)
$digest.Add('---')
$digest.Add('')
$digest.Add('# Rekap {0}' -f $week.Key)
$digest.Add('')
$digest.Add(('Periode {0} sampai {1}. Angka diambil dari Omnitrack saat catatan ini dibuat.' -f $weekStart.ToString('dd MMM yyyy'), $weekEnd.ToString('dd MMM yyyy')))
$digest.Add('')
$digest.Add('## Modul yang bergerak minggu ini')
$digest.Add('')

if ($weekModules.Count -eq 0) {
    $digest.Add('_Tidak ada modul yang berubah minggu ini menurut timestamp Omnitrack._')
    $digest.Add('')
} else {
    foreach ($project in $projects) {
        $moved = @(Get-ModuleList $project.id | Where-Object { $weekModules.id -contains $_.id })
        if ($moved.Count -eq 0) { continue }
        $digest.Add(('- [[{0}|{1}]]' -f $project.notePath, $project.name))
        foreach ($module in $moved) { $digest.Add(('    - {0} ({1})' -f $module.title, $statusLabel[$module.status])) }
    }
    $digest.Add('')
}

$digest.Add('## Papan saat ini')
$digest.Add('')
$digest.Add(('- Project: {0} total, {1} selesai, {2} jalan, {3} belum mulai.' -f $totalProjects, $totalDone, @($projects | Where-Object status -eq 'in_progress').Count, @($projects | Where-Object status -eq 'pending').Count))
$digest.Add(('- Modul: {0} total, {1} belum selesai.' -f $totalModules, $openModulesAll.Count))
$digest.Add('')
Add-KeepSection $digest 'Yang saya kerjakan' $digestKept '<!-- Ringkas sendiri: yang benar-benar kamu kerjakan minggu ini. -->'
Add-KeepSection $digest 'Kemenangan' $digestKept '<!-- Sekecil apa pun. -->'
Add-KeepSection $digest 'Hambatan' $digestKept '<!-- Yang bikin lambat, dan rencananya. -->'
Add-KeepSection $digest 'Rencana minggu depan' $digestKept '<!-- Tiga hal utama. -->'
Write-Note $digestPath $digest

# --- ------------------------------------------------- templates dan obsidian
$templatesDir = Join-Path $vault 'Templates'
if (-not (Test-Path -LiteralPath $templatesDir)) { New-Item -ItemType Directory -Force -Path $templatesDir | Out-Null }

$dailyTemplate = @'
---
type: journal
date: {{date:YYYY-MM-DD}}
tags: [type/journal]
---

# {{date:dddd, D MMMM YYYY}}

## Fokus hari ini

-

## Catatan

-

## Selesai

-

## Besok

-
'@
[System.IO.File]::WriteAllText((Join-Path $templatesDir 'Harian.md'), $dailyTemplate, $utf8)

$weeklyTemplate = @'
---
type: journal
week: {{date:gggg-[W]ww}}
tags: [type/journal]
---

# Minggu {{date:gggg-[W]ww}}

Rekap otomatis dari Omnitrack: ![[Journal/Rekap/{{date:gggg-[W]ww}}]]

## Yang saya kerjakan

-

## Kemenangan

-

## Hambatan

-

## Rencana minggu depan

-
'@
[System.IO.File]::WriteAllText((Join-Path $templatesDir 'Mingguan.md'), $weeklyTemplate, $utf8)

# Obsidian settings. WHY they are only created when absent: Obsidian rewrites
# these files itself, so an existing value belongs to the user (or to a newer
# Obsidian) and must not be stomped by a generator run.
$obsidianDir = Join-Path $vault '.obsidian'

if (Test-Path -LiteralPath $obsidianDir) {
    $dailyNotesConfig = Join-Path $obsidianDir 'daily-notes.json'

    if (-not (Test-Path -LiteralPath $dailyNotesConfig)) {
        [System.IO.File]::WriteAllText($dailyNotesConfig, (@{
            folder   = 'Journal'
            template = 'Templates/Harian'
            format   = 'YYYY-MM-DD'
        } | ConvertTo-Json), $utf8)
    }

    $templatesConfig = Join-Path $obsidianDir 'templates.json'

    if (-not (Test-Path -LiteralPath $templatesConfig)) {
        [System.IO.File]::WriteAllText($templatesConfig, (@{ folder = 'Templates' } | ConvertTo-Json), $utf8)
    }

    # core-plugins.json is a bare array in older Obsidian and an id -> bool map in
    # newer builds, so both shapes are handled.
    $corePlugins = Join-Path $obsidianDir 'core-plugins.json'

    if (Test-Path -LiteralPath $corePlugins) {
        $pluginsRaw = Get-Content -LiteralPath $corePlugins -Raw

        if ($pluginsRaw.TrimStart().StartsWith('[')) {
            $enabled = @($pluginsRaw | ConvertFrom-Json)

            foreach ($id in @('templates', 'daily-notes')) {
                if ($enabled -notcontains $id) { $enabled += $id }
            }

            [System.IO.File]::WriteAllText($corePlugins, ($enabled | ConvertTo-Json), $utf8)
        } else {
            $pluginMap = $pluginsRaw | ConvertFrom-Json

            foreach ($id in @('templates', 'daily-notes')) {
                $pluginMap | Add-Member -NotePropertyName $id -NotePropertyValue $true -Force
            }

            [System.IO.File]::WriteAllText($corePlugins, ($pluginMap | ConvertTo-Json), $utf8)
        }
    }
}

Write-Output ('project notes : {0}' -f $written)
Write-Output ('area notes    : {0}' -f $areaWritten)
Write-Output ('other notes   : Projects MOC, Home, Welcome, Rencana/Fokus Saat Ini, Ide/Ide, Journal/Rekap/{0}' -f $week.Key)
Write-Output ('templates     : Templates/Harian.md, Templates/Mingguan.md')
Save-LayoutCache
Save-KeepStore
Write-Output ('layout cache  : {0} dipakai ulang, {1} dipindai' -f $cacheHits, $cacheMisses)
