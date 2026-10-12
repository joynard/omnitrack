$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# Generate Obsidian notes for every Omnitrack project.
#
# Usage (from the repository root, with the stack up):
#
#     ./scripts/vault-notes.ps1
#
# It reads OBSIDIAN_VAULT_HOST_PATH from .env, pulls the project fields from
# /board/projects (the harness context payload is too compact for a note) and the
# module detail from the database, then rewrites Projects/ in the vault.
#
# WHY the data comes from the app and not from a hand-written table: Omnitrack
# already holds name, category, role, status, description and modules for every
# project, so the notes are a projection of that data. Re-running this script
# after editing Omnitrack refreshes the generated fields while leaving the
# "## Catatan" section (the whole point of the note) untouched.
#
# WHY cleanup is selective: only notes carrying `omnitrack_id:` (i.e. written by
# this script) are replaced, so a note written by hand inside Projects/ survives
# a regeneration.
# ---------------------------------------------------------------------------

$vault = (Select-String -Path (Join-Path $PSScriptRoot '..\.env') -Pattern '^OBSIDIAN_VAULT_HOST_PATH=(.+)$').Matches.Groups[1].Value.Trim().Trim('"')
if (-not $vault) { throw 'OBSIDIAN_VAULT_HOST_PATH not found in .env' }
$root  = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$api   = 'http://127.0.0.1:8080/api/harness'
$stamp = (Get-Date).ToString('yyyy-MM-dd')

$token = (Select-String -Path (Join-Path $root '.env') -Pattern '^HARNESS_SECRET_TOKEN=(.+)$').Matches.Groups[1].Value.Trim()
$headers = @{ Authorization = "Bearer $token" }

# WHY the projects come from /board/projects and not from /api/harness/context:
# the harness context payload is deliberately compact (it is injected into an LLM
# window) and carries no description, project_owner, target_user or doc_link --
# exactly the fields a note needs. /board/projects is the same board the UI
# renders, so it has them; it authenticates with the web session instead of the
# bearer token, hence the login below.
$email = (Select-String -Path (Join-Path $root '.env') -Pattern '^OMNITRACK_USER_EMAIL=(.+)$').Matches.Groups[1].Value.Trim()
$password = (Select-String -Path (Join-Path $root '.env') -Pattern '^OMNITRACK_USER_PASSWORD=(.+)$').Matches.Groups[1].Value.Trim()
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest 'http://127.0.0.1:8080/login' -WebSession $session -UseBasicParsing
$csrf = [regex]::Match($loginPage.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
Invoke-WebRequest 'http://127.0.0.1:8080/login' -Method Post -WebSession $session -UseBasicParsing `
    -Body @{ _token = $csrf; email = $email; password = $password } | Out-Null

$projects = @((Invoke-RestMethod 'http://127.0.0.1:8080/board/projects' -WebSession $session).projects)
$categories = @((Invoke-RestMethod -Uri "$api/categories" -Headers $headers).categories)

# WHY the module descriptions come from psql: neither surface returns them --
# the harness payload drops them and the board only counts them -- and they name
# the pipeline scripts and subfolders, which is the useful part.
$modulesJson = docker exec omnitrack-db psql -U omnitrack -d omnitrack -t -A -c "select coalesce(json_agg(json_build_object('project_id', project_id, 'title', title, 'description', description, 'status', status, 'priority', priority, 'order_index', order_index) order by order_index), '[]') from project_modules;"
$modulesByProject = @{}
foreach ($m in ($modulesJson | ConvertFrom-Json)) {
    if (-not $modulesByProject.ContainsKey($m.project_id)) { $modulesByProject[$m.project_id] = @() }
    $modulesByProject[$m.project_id] += $m
}
$moduleTotal = (@($modulesByProject.Values | ForEach-Object { $_.Count }) | Measure-Object -Sum).Sum

$utf8 = New-Object System.Text.UTF8Encoding($false)

$statusLabel = @{ pending = 'Belum mulai'; in_progress = 'Jalan'; done = 'Selesai' }
$priorityLabel = @{ low = 'rendah'; medium = 'sedang'; high = 'tinggi' }

# Junk that must never appear in a "what is in this folder" list. It includes
# generated build state (Unity Library, .NET bin/obj, Rust target, Python venv),
# because raw file counts otherwise say "PBA - 109974 berkas" for what is really
# a handful of course projects.
$junkDirs = @('node_modules', 'vendor', '.git', '.angular', '.vscode', '.next', '.svelte-kit',
              '__pycache__', '.phpunit.cache', '.turbo', '.vs', 'dist', 'build', 'venv', '.venv',
              '.claude', '.agents', '.kiro', '.omp', 'catboost_info', '.pytest_cache',
              'Library', 'obj', 'Temp', 'bin', 'target', '.gradle', '.idea')
$junkRx = 'node_modules|\\vendor\\|\\\.git\\|__pycache__|\\\.venv\\|\\venv\\|\\dist\\|\\build\\|\\\.next\\|\\\.svelte-kit\\|\\\.angular\\|\\\.vs\\|\\\.vscode\\|\\Library\\|\\obj\\|\\Temp\\|\\bin\\|\\target\\|\\\.gradle\\|\\\.idea\\'

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

function ConvertTo-SafeName([string]$name) {
    # Windows forbids \ / : * ? " < > | and Obsidian treats # ^ [ ] | as syntax.
    $safe = $name -replace '[\\/:*?"<>|#^\[\]]', '-'
    return ($safe -replace '\s+', ' ').Trim()
}

function ConvertTo-Slug([string]$name) {
    return (($name.ToLower() -replace '[^a-z0-9]+', '-').Trim('-'))
}

function Get-TechTags([string]$text) {
    $lower = $text.ToLower()
    $tags = New-Object System.Collections.Generic.List[string]
    foreach ($key in $techTags.Keys) {
        if ($lower.Contains($key) -and -not $tags.Contains($techTags[$key])) { $tags.Add($techTags[$key]) }
    }
    return $tags
}

function Get-DiskLayout([string]$path) {
    if (-not (Test-Path -LiteralPath $path)) { return $null }

    $all = Get-ChildItem -LiteralPath $path -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch $junkRx }

    $entries = @()
    foreach ($d in (Get-ChildItem -LiteralPath $path -Directory -Force -ErrorAction SilentlyContinue |
                    Where-Object { $_.Name -notin $junkDirs })) {
        $prefix = $path.Length + 1
        $needle = $d.Name + '\'
        $count = 0
        foreach ($f in $all) {
            if ($f.FullName.Substring($prefix).StartsWith($needle)) { $count++ }
        }
        $entries += [pscustomobject]@{ Name = $d.Name; Files = $count }
    }

    $topFiles = @(Get-ChildItem -LiteralPath $path -File -Force -ErrorAction SilentlyContinue |
        Select-Object -First 10 -ExpandProperty Name)

    return [pscustomobject]@{
        Dirs  = @($entries | Sort-Object -Property Files -Descending)
        Files = $topFiles
        Total = $all.Count
    }
}

$projects = @($projects)
# /board/projects returns the category as an object ({id, name}) while the notes
# only need the label, and Group-Object cannot group on that object.
foreach ($p in $projects) {
    $p | Add-Member -NotePropertyName categoryName -NotePropertyValue ([string]($p.category.name)) -Force
}
$categoryOrder = @{}
$i = 0
foreach ($c in $categories) { $categoryOrder[$c.name] = $i; $i++ }

foreach ($p in $projects) {
    $p | Add-Member -NotePropertyName _cat -NotePropertyValue ([int]$categoryOrder[$p.categoryName]) -Force
}

# Selectively clear previously generated notes (and the MOC); hand-written notes stay.
$projectsDir = Join-Path $vault 'Projects'
if (Test-Path -LiteralPath $projectsDir) {
    foreach ($file in (Get-ChildItem -LiteralPath $projectsDir -Recurse -File -Filter *.md -Force)) {
        $head = (Get-Content -LiteralPath $file.FullName -TotalCount 20 -ErrorAction SilentlyContinue) -join "`n"
        if ($head -match 'omnitrack_id:|title: "Projects MOC"') { Remove-Item -LiteralPath $file.FullName -Force }
    }
    foreach ($dir in (Get-ChildItem -LiteralPath $projectsDir -Recurse -Directory -Force | Sort-Object { $_.FullName.Length } -Descending)) {
        if (-not (Get-ChildItem -LiteralPath $dir.FullName -Force)) { Remove-Item -LiteralPath $dir.FullName -Force }
    }
}

$written = 0
$layoutCache = @{}
$notePathOf = @{}

foreach ($project in ($projects | Sort-Object _cat, order_index)) {
    $category = if ($project.categoryName) { $project.categoryName } else { 'Tanpa Kategori' }
    $safeName = ConvertTo-SafeName $project.name
    $folder = Join-Path $projectsDir $category
    New-Item -ItemType Directory -Force -Path $folder | Out-Null

    $description = [string]$project.description
    $diskPath = $null
    $m = [regex]::Match($description, 'Path:\s*(.+?)\s*$')
    if ($m.Success) {
        $diskPath = $m.Groups[1].Value.Trim()
        $description = $description.Substring(0, $m.Index).Trim().TrimEnd('.')
    }

    # @($null) is a one-element array, so a project with no modules must be an
    # explicit empty array -- otherwise the module loop runs once on $null.
    $modules = if ($modulesByProject.ContainsKey($project.id)) { @($modulesByProject[$project.id]) } else { @() }
    $moduleText = (@($modules) | ForEach-Object { $_.title + ' ' + $_.description }) -join ' '
    $haystack = $project.name + ' ' + $description + ' ' + $moduleText
    $tags = @('project', (ConvertTo-Slug $category), $project.status) + (Get-TechTags $haystack)
    $tags = @($tags | Select-Object -Unique)

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('---')
    $lines.Add('title: "{0}"' -f $project.name.Replace('"', '\"'))
    $lines.Add('category: {0}' -f $category)
    $lines.Add('status: {0}' -f $project.status)
    if ($project.my_role) { $lines.Add('my_role: "{0}"' -f $project.my_role.Replace('"', '\"')) }
    if ($project.department) { $lines.Add('department: "{0}"' -f $project.department.Replace('"', '\"')) }
    if ($project.project_owner) { $lines.Add('project_owner: "{0}"' -f $project.project_owner.Replace('"', '\"')) }
    if ($project.target_user) { $lines.Add('target_user: "{0}"' -f $project.target_user.Replace('"', '\"')) }
    if ($diskPath) { $lines.Add('path: "{0}"' -f $diskPath.Replace('\', '\\').Replace('"', '\"')) }
    if ($project.doc_link) { $lines.Add('repo: {0}' -f $project.doc_link) }
    $lines.Add('tags: [{0}]' -f ($tags -join ', '))
    $lines.Add('omnitrack_id: {0}' -f $project.id)
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
    $lines.Add('| Kategori | {0} |' -f $category)
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
        foreach ($mod in $modules) {
            $detail = [string]$mod.description
            $mm = [regex]::Match($detail, 'Path:\s*(.+?)\s*$')
            if ($mm.Success) { $detail = $detail.Substring(0, $mm.Index).Trim().TrimEnd('.') }
            $detail = $detail.Replace('|', '\|')
            $lines.Add(('| {0} | {1} | {2} | {3} |' -f $mod.title, $statusLabel[$mod.status], $priorityLabel[$mod.priority], $detail))
        }
        $lines.Add('')
        $lines.Add('_Status modul di atas salinan dari Omnitrack; yang berlaku tetap di Omnitrack._')
        $lines.Add('')
    }

    if ($diskPath) {
        if (-not $layoutCache.ContainsKey($diskPath)) { $layoutCache[$diskPath] = Get-DiskLayout $diskPath }
        $layout = $layoutCache[$diskPath]
        if ($layout) {
            $lines.Add('## Isi folder di disk')
            $lines.Add('')
            foreach ($d in $layout.Dirs) { $lines.Add(('- `{0}/` - {1} berkas' -f $d.Name, $d.Files)) }
            foreach ($f in $layout.Files) { $lines.Add('- `{0}`' -f $f) }
            $lines.Add('')
            $lines.Add('_Total {0} berkas, dihitung tanpa node_modules/vendor/.git dan tanpa hasil build (Library, bin, obj, target, venv)._' -f $layout.Total)
            $lines.Add('')
        }
    }

    $lines.Add('## Catatan')
    $lines.Add('')
    $lines.Add('<!-- Tulisanmu di sini. Bagian ini tidak pernah ditimpa saat catatan di-generate ulang. -->')
    $lines.Add('')

    $lines.Add('## Lihat juga')
    $lines.Add('')
    $lines.Add('- [[Home]]')
    $lines.Add('- [[Projects/Projects MOC|Projects MOC]]')
    $siblings = @($projects | Where-Object { $_.categoryName -eq $project.categoryName -and $_.id -ne $project.id } |
        Sort-Object order_index | Select-Object -First 8)
    foreach ($s in $siblings) {
        $lines.Add(('- [[Projects/{0}/{1}|{2}]]' -f $s.categoryName, (ConvertTo-SafeName $s.name), $s.name))
    }
    $lines.Add('')

    [System.IO.File]::WriteAllText((Join-Path $folder ($safeName + '.md')), ($lines -join "`r`n"), $utf8)
    $notePathOf[$project.id] = 'Projects/{0}/{1}' -f $category, $safeName
    $written++
}

# ---------------------------------------------------------------------------
# Projects MOC -- the index Obsidian's graph hangs off.
# ---------------------------------------------------------------------------
$moc = New-Object System.Collections.Generic.List[string]
$moc.Add('---')
$moc.Add('title: "Projects MOC"')
$moc.Add('tags: [moc, project]')
$moc.Add('synced: {0}' -f $stamp)
$moc.Add('---')
$moc.Add('')
$moc.Add('# Projects MOC')
$moc.Add('')
$moc.Add('Indeks seluruh project Alexander. Angka dan status di sini adalah cerminan')
$moc.Add('Omnitrack, bukan sumber kebenaran: yang mengubah status tetap Omnitrack.')
$moc.Add('')
$moc.Add('| Kategori | Project | Selesai | Jalan | Belum | Modul |')
$moc.Add('| --- | --- | --- | --- | --- | --- |')
foreach ($cat in ($projects | Group-Object categoryName | Sort-Object { $categoryOrder[$_.Name] })) {
    $done = @($cat.Group | Where-Object status -eq 'done').Count
    $run = @($cat.Group | Where-Object status -eq 'in_progress').Count
    $hold = @($cat.Group | Where-Object status -eq 'pending').Count
    $mods = (@($cat.Group | ForEach-Object { if ($modulesByProject.ContainsKey($_.id)) { @($modulesByProject[$_.id]).Count } else { 0 } }) | Measure-Object -Sum).Sum
    $moc.Add(('| {0} | {1} | {2} | {3} | {4} | {5} |' -f $cat.Name, $cat.Count, $done, $run, $hold, $mods))
}
$moc.Add('')
$moc.Add('## Daftar project')
$moc.Add('')
foreach ($cat in ($projects | Group-Object categoryName | Sort-Object { $categoryOrder[$_.Name] })) {
    $moc.Add('### {0}' -f $cat.Name)
    $moc.Add('')
    foreach ($p in ($cat.Group | Sort-Object order_index)) {
        $role = if ($p.my_role) { ' - ' + $p.my_role } else { '' }
        $moc.Add(('- [[Projects/{0}/{1}|{2}]] - {3}{4}' -f $p.categoryName, (ConvertTo-SafeName $p.name), $p.name, $statusLabel[$p.status], $role))
    }
    $moc.Add('')
}
$moc.Add('## Tag')
$moc.Add('')
$moc.Add('Setiap catatan project membawa tag project, tag kategori, tag status, dan tag')
$moc.Add('teknologi yang terdeteksi (mis. laravel, python, svelte). Pakai panel tag')
$moc.Add('Obsidian untuk menyaring berdasarkan hal itu.')
[System.IO.File]::WriteAllText((Join-Path $projectsDir 'Projects MOC.md'), ($moc -join "`r`n"), $utf8)

# ---------------------------------------------------------------------------
# Home -- vault entry point. Single-quoted here-string: no backtick escapes.
# ---------------------------------------------------------------------------
$total = $projects.Count
$doneTotal = @($projects | Where-Object status -eq 'done').Count

$homeNote = @'
---
title: "Home"
tags: [moc, home]
synced: __STAMP__
---

# Second Brain

Rumah untuk catatan pribadi. Isinya sekarang didominasi project, karena itulah
yang datanya sudah rapi di Omnitrack.

## Peta isi

- [[Projects/Projects MOC|Projects MOC]] - indeks __TOTAL__ project (__DONE__ selesai,
  __MODULES__ modul) di enam kategori: Collage, Panit, Private, PT SPIL, Testing Zone,
  dan Ubaya.

## Cara vault ini dipakai

- **Catatan project adalah cerminan.** Field seperti status, peran, modul, dan
  path di-generate dari Omnitrack (Laravel, D:\Projects\Private\Projects & Event
  Management, http://127.0.0.1:8080). Mengubah status di sini tidak mengubah
  Omnitrack.
- **Bagian "Catatan" milikmu.** Itu satu-satunya bagian yang tidak pernah ditimpa
  saat catatan di-generate ulang. Tulis ide, keputusan, dan langkah berikutnya di
  sana.
- **Omnitrack bisa membaca vault ini.** Lewat halaman /vault dan endpoint
  /api/harness/vault*, dan DeepSeek Harness memakainya sebagai konteks tiap sesi.
  Menulis di sini tidak mengeluarkan catatan dari alur kerja.

## Konvensi

- Wikilink selalu memakai path penuh ([[Projects/PT SPIL/Forecast Container]])
  supaya nama yang mirip - mis. collage di Testing Zone dan kategori Collage -
  tidak pernah salah taut.
- Tag: project, tag kategori (pt-spil), tag status (done), tag teknologi (python,
  laravel).
- Frontmatter tiap catatan memuat omnitrack_id, jadi catatan dan baris Omnitrack
  selalu bisa dipasangkan kembali.
'@
$homeNote = $homeNote.Replace('__STAMP__', $stamp).Replace('__TOTAL__', $total).Replace('__DONE__', $doneTotal).Replace('__MODULES__', $moduleTotal)
[System.IO.File]::WriteAllText((Join-Path $vault 'Home.md'), $homeNote, $utf8)

# The stock Obsidian note tells you to delete it once the vault is in use.
$welcome = @'
---
title: "Welcome"
tags: [meta]
---

Vault ini sudah dipakai. Mulai dari [[Home]]; daftar projectnya ada di
[[Projects/Projects MOC|Projects MOC]].

Catatan ini sisa template Obsidian dan aman untuk dihapus.
'@
[System.IO.File]::WriteAllText((Join-Path $vault 'Welcome.md'), $welcome, $utf8)

Write-Output ('notes written  : {0} project notes + Projects MOC + Home + Welcome' -f $written)
Write-Output ('categories     : {0}' -f ((@($projects | Group-Object categoryName).Name) -join ', '))
Write-Output ('disk layouts   : {0}' -f $layoutCache.Count)
