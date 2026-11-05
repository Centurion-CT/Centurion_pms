{{-- Check in and check out. The native date input does the picking; with
     JavaScript it sits invisibly over a readable "Thu 10 Dec". --}}
<label class="seg seg-date"><span class="seg-label">Check in</span><span class="seg-value">{{ $stay->checkin->format('D j M') }}</span><input type="date" name="in" value="{{ $stay->checkin->toDateString() }}" min="{{ now()->toDateString() }}" required></label>
<label class="seg seg-date"><span class="seg-label">Check out</span><span class="seg-value">{{ $stay->checkout->format('D j M') }}</span><input type="date" name="out" value="{{ $stay->checkout->toDateString() }}" min="{{ now()->addDay()->toDateString() }}" required></label>
@once
    @push('scripts')
        <script>
            (function () {
                var text = function (input) {
                    var d = new Date(input.value + 'T00:00');
                    if (!isNaN(d)) input.previousElementSibling.textContent = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }).replace(',', '');
                };
                document.addEventListener('click', function (e) {
                    if (e.target.matches('.seg-date input') && e.target.showPicker) { try { e.target.showPicker(); } catch (_) {} }
                });
                document.addEventListener('change', function (e) {
                    if (!e.target.matches('.seg-date input')) return;
                    text(e.target);
                    var form = e.target.form, checkin = form.elements['in'], checkout = form.elements['out'];
                    if (e.target === checkin && checkin.value) {
                        var next = new Date(checkin.value + 'T12:00');
                        next.setDate(next.getDate() + 1);
                        var min = next.toISOString().slice(0, 10);
                        checkout.min = min;
                        if (checkout.value <= checkin.value) { checkout.value = min; text(checkout); }
                    }
                });
            })();
        </script>
    @endpush
@endonce
