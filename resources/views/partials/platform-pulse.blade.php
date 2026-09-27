{{--
    Opts a public page into the platform-pulse websocket so the marketing
    kobo counter live-ticks + a small "just received" toast pops when
    payments land anywhere on the platform. Include in <head> AFTER the
    charset/viewport meta tags on any surface that should react.

    Pairs with resources/js/platform-pulse.js (loaded via app.js) which
    reads these meta values, boots laravel-echo over Reverb, and only
    activates when kv-pulse == "on".
--}}
@php
    $pulseHost = env('REVERB_HOST', 'localhost');
    $pulsePort = (int) env('REVERB_PORT', 8080);
    $pulseScheme = env('REVERB_SCHEME', 'http') === 'https' ? 'https' : 'http';
    $pulseKey = env('REVERB_APP_KEY', '');
@endphp
<meta name="kv-pulse" content="on">
<meta name="kv-reverb-key" content="{{ $pulseKey }}">
<meta name="kv-reverb-host" content="{{ $pulseHost }}">
<meta name="kv-reverb-port" content="{{ $pulsePort }}">
<meta name="kv-reverb-scheme" content="{{ $pulseScheme }}">
