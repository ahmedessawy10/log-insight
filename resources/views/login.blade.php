@php
    $nonce = request()->attributes->get('log-insight-nonce');
    $rtl = \LogInsight\Support\SetLocale::isRtl();
    $t = fn ($key, $rep = []) => __('log-insight::messages.'.$key, $rep);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $t('login.title') }} - {{ $t('app_name') }}</title>
    <script nonce="{{ $nonce }}">
        try { var t = localStorage.getItem('li-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
    </script>
    @include('log-insight::partials.styles')
    <style>
        .login { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
        .login .card { width: min(380px, 100%); padding: 24px; }
        .login h1 { margin: 0 0 4px; }
        .login label { display: block; font-size: 12px; color: var(--muted); margin: 14px 0 4px; }
        .login input { width: 100%; }
        .login button.primary { width: 100%; margin-top: 18px; padding: 9px; }
        .err { color: var(--bad); font-size: 13px; margin-top: 12px; }
    </style>
</head>
<body>
<main class="login">
    <div class="card">
        <h1>{{ $t('app_name') }}</h1>
        <p class="muted" style="margin: 0">{{ $t('login.subtitle') }}</p>

        @if ($misconfigured)
            <div class="notice warn-box" style="margin-top: 14px">{{ $t('login.misconfigured', ['min' => \LogInsight\Security\EnvAuth::MIN_PLAIN_LENGTH]) }}</div>
        @endif

        <form method="POST" action="{{ route('log-insight.login.store') }}">
            @csrf
            <label for="username">{{ $t('login.username') }}</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" autofocus required maxlength="190">

            <label for="password">{{ $t('login.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="500">

            @error('username')<div class="err" role="alert">{{ $message }}</div>@enderror
            @error('password')<div class="err" role="alert">{{ $message }}</div>@enderror

            <button type="submit" class="primary">{{ $t('login.submit') }}</button>
        </form>

        <p style="margin: 16px 0 0; text-align: center">
            <a href="{{ route('log-insight.locale', app()->getLocale() === 'ar' ? 'en' : 'ar') }}">{{ $t('nav.language') }}</a>
        </p>
    </div>
</main>
</body>
</html>
