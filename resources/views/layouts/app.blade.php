<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'Omnitrack') · {{ config('app.name', 'Omnitrack') }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#5C4E3D">

    <link rel="stylesheet" href="{{ asset('css/omnitrack.css') }}">

    {{-- Applied before first paint so the saved theme never flashes. --}}
    <script>
        (function () {
            try {
                var theme = window.localStorage.getItem('omnitrack.theme');
                if (theme) {
                    document.documentElement.setAttribute('data-theme', theme);
                }
            } catch (e) {
                // Storage unavailable: fall back to the default theme.
            }
        })();
    </script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('projects.index') }}">
        <img class="brand-mark" src="{{ asset('favicon.svg') }}" alt="" width="24" height="24">
        <span class="brand-name">Omnitrack</span>
    </a>

    <nav class="nav">
        <a class="nav-link {{ request()->routeIs('projects.*') ? 'is-active' : '' }}"
           href="{{ route('projects.index') }}">Projects</a>
        <a class="nav-link {{ request()->routeIs('tasks.*') ? 'is-active' : '' }}"
           href="{{ route('tasks.index') }}">Tasks</a>
    </nav>

    <div class="topbar-end">
        <label class="theme-picker">
            <span class="sr-only">Tema</span>
            <select class="select select-sm" id="theme-select">
                <option value="warm">Warm</option>
                <option value="paper">Paper</option>
                <option value="slate">Slate</option>
                <option value="forest">Forest</option>
                <option value="dark">Dark</option>
            </select>
        </label>

        {{-- Mobile only (desktop has Ctrl + P). Kept in its own container so the
             show/hide rules for the page action cannot clash with it. --}}
        <div class="head-actions topbar-actions" id="topbar-actions">
            <button type="button" class="btn btn-secondary btn-sm" data-palette-open>AI</button>
        </div>

        {{-- Page actions are relocated here on mobile by core.js. --}}
        <div class="head-actions" id="topbar-page-actions"></div>

        <form method="POST" action="{{ route('logout') }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm">Keluar</button>
        </form>
    </div>
</header>

<main class="main">
    <div id="flash-host"></div>
    @yield('content')
</main>

{{-- Ctrl + P command palette overlay ------------------------------------- --}}
<div class="overlay" id="palette-overlay" hidden>
    <div class="palette" role="dialog" aria-modal="true" aria-labelledby="palette-heading">
        <div class="palette-head">
            <h2 id="palette-heading">AI Palette</h2>
            <span class="meta">Esc untuk menutup</span>
        </div>

        <div class="palette-body">
            <form id="palette-form" autocomplete="off">
                <input class="input palette-input" id="palette-prompt" name="prompt" type="text"
                       placeholder="Tulis instruksi apa saja, mis. &quot;pindahkan project ini ke kategori Kantor&quot;">

                <div class="palette-row">
                    <label class="palette-field">
                        <span class="field-label">Aksi</span>
                        <select class="select select-sm" id="palette-action" name="action_type">
                            <option value="agent">Full control</option>
                            <option value="parse_task">Buat task dari teks</option>
                            <option value="breakdown_project">Pecah jadi sub-modul</option>
                            <option value="summarize_board">Ringkas board</option>
                            <option value="trigger_sync">Sinkron Sheets</option>
                        </select>
                    </label>

                    <label class="palette-field" id="palette-project-field" hidden>
                        <span class="field-label">Project</span>
                        <select class="select select-sm" id="palette-project" name="project_id">
                            <option value="">Memuat…</option>
                        </select>
                    </label>
                </div>
            </form>

            <div class="template-list" id="palette-templates"></div>

            <p class="meta palette-hint">
                Cepat: <span class="kbd-inline">&gt; task</span> buat task langsung ·
                <span class="kbd-inline">/kata</span> cari project, modul, task
            </p>

            <div class="feedback" id="palette-feedback" hidden></div>
        </div>

        <div class="palette-foot">
            <span class="meta">
                <span class="kbd-inline">Ctrl</span> + <span class="kbd-inline">P</span> buka
                · <span class="kbd-inline">Enter</span> jalankan
            </span>
            <span class="palette-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="palette-close">Tutup</button>
                <button type="submit" class="btn btn-primary btn-sm" id="palette-submit" form="palette-form">
                    Jalankan
                </button>
            </span>
        </div>
    </div>
</div>

<script src="{{ asset('js/core.js') }}"></script>
<script src="{{ asset('js/palette.js') }}"></script>
@stack('scripts')
</body>
</html>
