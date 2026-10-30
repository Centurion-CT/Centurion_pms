{{-- A host that belongs to no business (HostTenantFinder found nothing).
     Rendered with a 404 by the NoCurrentTenant handler in bootstrap/app.php. --}}
@extends('platform.layouts.plain')

@section('title', "This address isn't in use")

@section('content')
    <div class="bar"><i></i><i></i><i></i><span>https://<b>{{ $host }}</b></span></div>
    <h1>This address isn't in use</h1>
    <p class="lead">No business on {{ config('brand.name') }} uses <b>{{ $host }}</b>. Check the spelling, or find your business by email.</p>
    <div class="actions">
        <a class="btn btn-primary" href="https://{{ $central }}/login">Find my business</a>
        @if ($slug !== null)
            <a class="btn btn-secondary" href="https://{{ $central }}/signup?slug={{ urlencode($slug) }}">Use this address for my business</a>
        @endif
    </div>
@endsection
