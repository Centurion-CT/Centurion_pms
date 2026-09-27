@if ($property->whatsapp_button_enabled && $tenant->phone)
    @php($prefill = $property->whatsapp_button_prefill ?? "Hi! I'd like to ask about ".$property->name)
    <a class="wa-button" target="_blank" rel="noopener"
       href="https://wa.me/{{ ltrim($tenant->phone, '+') }}?text={{ urlencode($prefill) }}"
       aria-label="Chat on WhatsApp">💬</a>
@endif
