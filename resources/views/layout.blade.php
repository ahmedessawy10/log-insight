@php
    $nonce = request()->attributes->get('log-insight-nonce');
    $rtl = \LogInsight\Support\SetLocale::isRtl();
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('log-insight::messages.app_name') }}</title>
    <script nonce="{{ $nonce }}">
        try { var t = localStorage.getItem('li-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
    </script>
    @include('log-insight::partials.styles')
    @livewireStyles
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <span class="brand">{{ __('log-insight::messages.app_name') }}</span>
        <nav class="nav" aria-label="Main">
            <a href="{{ route('log-insight.dashboard') }}" @class(['active' => request()->routeIs('log-insight.dashboard')])>{{ __('log-insight::messages.nav.dashboard') }}</a>
            <a href="{{ route('log-insight.logs') }}" @class(['active' => request()->routeIs('log-insight.logs')])>{{ __('log-insight::messages.nav.logs') }}</a>
            <a href="{{ route('log-insight.issues') }}" @class(['active' => request()->routeIs('log-insight.issues')])>{{ __('log-insight::messages.nav.issues') }}</a>
            @if (\LogInsight\LogInsight::can($user, 'manage'))
                <a href="{{ route('log-insight.tags') }}" @class(['active' => request()->routeIs('log-insight.tags')])>{{ __('log-insight::messages.nav.tags') }}</a>
            @endif
            @if (\LogInsight\LogInsight::can($user, 'audit'))
                <a href="{{ route('log-insight.audit') }}" @class(['active' => request()->routeIs('log-insight.audit')])>{{ __('log-insight::messages.nav.audit') }}</a>
            @endif
        </nav>
        <div class="tools">
            <a class="btn" href="{{ route('log-insight.locale', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ __('log-insight::messages.nav.language') }}</a>
            @if (\LogInsight\Security\EnvAuth::enabled())
                <form method="POST" action="{{ route('log-insight.logout') }}" style="margin: 0">
                    @csrf
                    <button type="submit">{{ __('log-insight::messages.login.logout') }}</button>
                </form>
            @endif
            <button type="button" id="li-theme" title="{{ __('log-insight::messages.nav.theme') }}" aria-label="{{ __('log-insight::messages.nav.theme') }}">◐</button>
        </div>
    </div>
</header>
<main class="wrap">
    {{ $slot }}
</main>
@livewireScripts(['nonce' => $nonce])
<script nonce="{{ $nonce }}">
    document.getElementById('li-theme').addEventListener('click', function () {
        var root = document.documentElement;
        var dark = root.getAttribute('data-theme') === 'dark' ||
            (!root.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var next = dark ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('li-theme', next); } catch (e) {}
    });
</script>
</body>
</html>
