<section class="contact-block">
    <div class="container">
        @if (! empty($attrs['title']))
            <h2>{{ $attrs['title'] }}</h2>
        @else
            <h2>Get in touch</h2>
        @endif
        <div class="contact-grid">
            @if ($tenant->phone)
                <a href="tel:{{ $tenant->phone }}">📞 {{ $tenant->phone }}</a>
            @endif
            @if ($tenant->email)
                <a href="mailto:{{ $tenant->email }}">✉️ {{ $tenant->email }}</a>
            @endif
        </div>
    </div>
</section>
