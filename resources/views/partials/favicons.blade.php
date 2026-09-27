{{-- Keverna brand favicon. SVG is served to modern browsers (crisp
     at any DPR + supports dark mode later); .ico stays as the
     fallback for very old browsers that still ignore SVG icons. --}}
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
