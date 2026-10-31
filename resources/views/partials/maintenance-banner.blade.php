@php($window = \App\Support\MaintenanceWindow::current())
@if ($window['state'] !== 'inactive')
    <div role="status" data-testid="maintenance-banner"
         data-state="{{ $window['state'] }}"
         style="background:#fef3c7;color:#78350f;padding:8px;text-align:center;">
        @if ($window['state'] === 'active')
            {{ config('brand.name') }} is in scheduled maintenance until
            {{ $window['end']->format('H:i') }}. Bookings keep working but
            dashboard saves may be intermittent.
        @else
            Scheduled maintenance starts in {{ $window['hours_until'] }} hour(s)
            ({{ $window['start']->format('D H:i') }} →
            {{ $window['end']->format('H:i') }} WAT). Bookings keep working.
        @endif
    </div>
@endif
