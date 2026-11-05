{{-- Search and link-preview tags. Pages set @section('description') and
     @section('share_image'); private ones set @section('noindex'), or pass
     $noindex through Livewire's layoutData, and get a robots tag instead. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
@php($description = html_entity_decode(View::yieldContent('description', 'Book '.$business->name.' directly: live availability, paid securely online.'), ENT_QUOTES))
@php($image = html_entity_decode(View::yieldContent('share_image'), ENT_QUOTES) ?: $site->sharePhoto())
@php($canonical = 'https://'.$business->primaryHost().'/'.ltrim(request()->path(), '/'))
@if (($noindex ?? false) || View::hasSection('noindex'))
    <meta name="robots" content="noindex, nofollow">
@else
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $business->name }}">
    <meta property="og:locale" content="en_NG">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if ($image)<meta property="og:image" content="{{ url($image) }}">@endif
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if ($image)<meta name="twitter:image" content="{{ url($image) }}">@endif
@endif
