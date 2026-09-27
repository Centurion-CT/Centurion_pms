{{--
    Mobile hamburger menu for the auth surfaces (central + tenant
    login / register / forgot / reset). Replaces the "Est. 2026 ·
    Lagos, NG" / "Powered by Keverna" / "Remembered it?" tag line on
    ≤ 1023px viewports so operators on phones have a proper way to
    move between pages instead of only seeing an editorial mono line
    they can't do anything with.

    Uses <details>/<summary> so it works without JS. All styling
    lives in resources/css/app.css under the ".kv-auth-mob-nav*"
    selectors — kept out of an inline <style> block here so the CSS
    doesn't land inside a flex container each time the partial is
    included (some browsers give <style> nodes flex-item slots).
--}}
<details class="kv-auth-mob-nav" data-testid="auth-mobile-menu">
    <summary aria-label="Open menu">
        <svg width="18" height="18" viewBox="0 0 16 16" fill="none"
             stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
            <path d="M2 4h12M2 8h12M2 12h12"/>
        </svg>
    </summary>
    <div class="kv-auth-mob-nav-panel">
        {{-- Show the sign-in / register jumps only when we're NOT
             already on that page; keeps the menu concise. --}}
        @if (! request()->routeIs('login', 'tenant.login'))
            <a href="{{ Route::has('login') ? route('login') : '/login' }}">
                Sign in
            </a>
        @endif
        @if (! request()->routeIs('register') && Route::has('register'))
            <a href="{{ route('register') }}">Register your property</a>
        @endif
        @if (! request()->routeIs('password.request', 'tenant.password.request'))
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}">Forgot password</a>
            @elseif (Route::has('tenant.password.request'))
                <a href="{{ route('tenant.password.request') }}">Forgot password</a>
            @endif
        @endif
        <div class="divider" aria-hidden="true"></div>
        <a href="{{ url('/pricing') }}">Pricing</a>
        <a href="{{ url('/for-hosts') }}">For hosts</a>
        <a href="mailto:hello@keverna.com">Contact</a>
        <div class="divider" aria-hidden="true"></div>
        <div class="foot" aria-hidden="true">Elevated hospitality · One property at a time</div>
    </div>
</details>
