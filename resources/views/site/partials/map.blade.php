{{-- Leaflet map with one price marker per building. Clicking a marker
     calls window.siteMap.onPick(slug); pages set what that does.
     $pins from SiteController::mapPins. --}}
@once
    @push('head')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    @endpush
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script>
            window.siteMap = function (el, pins, opts) {
                opts = opts || {};
                var naira = function (n) { return '₦' + (n >= 1e6 ? (n / 1e6).toFixed(1) + 'm' : Math.round(n / 1000) + 'k'); };
                var map = L.map(el, { zoomControl: false, scrollWheelZoom: false });
                L.tileLayer(@json(config('keverna.site_map.tiles')), {
                    attribution: @json(config('keverna.site_map.attribution')),
                    maxZoom: 18, className: 'map-base',
                }).addTo(map);
                L.control.zoom({ position: opts.zoomPosition || 'bottomright' }).addTo(map);
                pins.forEach(function (p) {
                    var label = opts.withName ? p.name + ' · ' + naira(p.from) : naira(p.from);
                    var icon = L.divIcon({ className: 'marker-wrap', iconSize: [0, 0],
                        html: '<button type="button" class="marker" data-slug="' + p.slug + '" aria-label="' + p.name + ', from ' + naira(p.from) + '">' + label + '</button>' });
                    L.marker([p.lat, p.lng], { icon: icon, riseOnHover: true, keyboard: false }).addTo(map);
                });
                if (pins.length === 1) map.setView([pins[0].lat, pins[0].lng], 15);
                else map.fitBounds(pins.map(function (p) { return [p.lat, p.lng]; }), { paddingTopLeft: opts.padTL || [60, 60], paddingBottomRight: opts.padBR || [60, 60] });
                var each = function (fn) { el.querySelectorAll('.marker').forEach(fn); };
                var api = {
                    map: map,
                    activate: function (slug) { each(function (m) { m.classList.toggle('is-active', m.dataset.slug === slug); }); },
                    dim: function (slugs) { each(function (m) { m.classList.toggle('is-dim', !!slugs && slugs.indexOf(m.dataset.slug) === -1); }); },
                    flyTo: function (slug, offsetLng) { var p = pins.find(function (x) { return x.slug === slug; }); if (p) map.flyTo([p.lat, p.lng - (offsetLng || 0)], 15, { duration: .6 }); },
                    onPick: null,
                };
                el.addEventListener('click', function (e) { var m = e.target.closest('.marker'); if (m && api.onPick) api.onPick(m.dataset.slug); });
                return api;
            };
        </script>
    @endpush
@endonce
