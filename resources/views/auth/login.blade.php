<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Masuk · Omnitrack</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#5C4E3D">

    <link rel="stylesheet" href="{{ asset('css/omnitrack.css') }}">

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
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <img class="brand-mark" src="{{ asset('favicon.svg') }}" alt="" width="28" height="28">
            <span class="brand-name">Omnitrack</span>
        </div>

        <form method="POST" action="{{ route('login.store') }}" autocomplete="on">
            @csrf

            <label class="field">
                <span class="field-label">Email</span>
                <input class="input" type="email" name="email" id="email"
                       value="{{ old('email') }}" required autofocus autocomplete="username">
            </label>

            <label class="field">
                <span class="field-label">Password</span>
                <input class="input" type="password" name="password" id="password"
                       required autocomplete="current-password">
            </label>

            <label class="auth-remember">
                <input type="checkbox" name="remember" value="1">
                <span>Tetap masuk di perangkat ini</span>
            </label>

            @error('email')
                <div class="flash is-error" style="margin-top:12px">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn btn-primary auth-submit">Masuk</button>
        </form>
    </div>
</main>
</body>
</html>
