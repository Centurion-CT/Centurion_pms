{{-- Install brand favicon (config/brand.php). PNG for modern browsers,
     .ico as the legacy fallback, touch icon on white for iOS home screens. --}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="512x512" href="{{ asset('brand/favicon-512.png') }}">
<link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
<link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">
