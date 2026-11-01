/*
 * Map Desk: shared behaviour for the Option C pages. Reads the sample
 * data in ../assets/data.js and adds what this direction needs: real
 * map coordinates, 14-night availability per apartment type, photos
 * per type, and the Leaflet map with price markers.
 */
(function () {
  const C = window.CENTURION;

  // Approximate real positions (the built site reads lat/lng from each branch).
  const POS = {
    'wuse-2': [9.0795, 7.4706],
    'zone-4': [9.0636, 7.4796],
    'jabi': [9.0689, 7.4218],
    'jabi-annex': [9.0752, 7.4312],
    'lifecamp': [9.0828, 7.3958],
  };
  const NEAR = {
    'wuse-2': ['Banex Plaza, 4 min walk', 'Central Business District, 10 min', 'Airport, 40 min'],
    'zone-4': ['Berger roundabout, 5 min walk', 'Wuse Market, 8 min walk', 'Airport, 40 min'],
    'jabi': ['Jabi Lake Mall, 5 min', 'Utako, 8 min', 'Airport, 35 min'],
    'jabi-annex': ['Jabi Lake, 6 min', 'Jabi Park, 5 min', 'Airport, 35 min'],
    'lifecamp': ['Shared pool and gym on site', 'Gwarinpa, 10 min', 'Airport, 35 min'],
  };
  const PHOTOS = ['living-green.jpg', 'bedroom-bright.jpg', 'living-cream.jpg', 'photo-a.jpg', 'living-open.jpg', 'bedroom-dark.jpg', 'living-window.jpg', 'photo-c.jpg', 'living-cream-2.jpg', 'living-warm.jpg', 'photo-b.jpg'];
  let k = 0;

  C.locations.forEach((l, li) => {
    l.pos = POS[l.slug];
    l.near = NEAR[l.slug];
    l.types.forEach((t, ti) => {
      t.id = `${l.slug}:${ti}`;
      t.photo = PHOTOS[k++ % PHOTOS.length];
      // 14 nights from Fri 6 Nov: 'free' | 'last' | 'gone', stable per type
      let seed = (li + 1) * 97 + (ti + 1) * 31;
      const rnd = () => ((seed = (seed * 9301 + 49297) % 233280) / 233280);
      t.nights = Array.from({ length: 14 }, (_, d) => {
        if (d < 3) return t.left === 0 ? 'gone' : t.left === 1 ? 'last' : 'free';
        const r = rnd();
        return r < 0.2 ? 'gone' : r < 0.42 ? 'last' : 'free';
      });
    });
  });

  C.NIGHTS = 3;
  C.dates = { label: 'Fri 6 – Mon 9 Nov', in: 'Fri 6 Nov', out: 'Mon 9 Nov' };
  C.all = () => C.locations.flatMap((l) => l.types.map((t) => ({ l, t })));
  C.short = (n) => '₦' + (n >= 1e6 ? (n / 1e6).toFixed(1) + 'm' : Math.round(n / 1000) + 'k');

  C.stripHTML = (t, sel = 3) => `
    <div class="strip" role="img" aria-label="Free nights over the next two weeks">
      ${t.nights.map((s, i) => `<i class="${s === 'free' ? '' : s} ${i < sel ? 'sel' : ''}"></i>`).join('')}
    </div>
    <div class="strip-legend"><span>Fri 6</span><span>next 14 nights</span><span>Thu 19</span></div>`;

  C.tagHTML = (t) => t.left === 0 ? '<span class="tag">Taken for your dates</span>'
    : t.left === 1 ? '<span class="tag tag-last">Last one</span>'
    : `<span class="tag tag-free">${t.left} free</span>`;

  /* Map: Leaflet + CARTO Voyager tiles, one price marker per location. */
  C.map = function (el, opts = {}) {
    const map = L.map(el, { zoomControl: false, scrollWheelZoom: false, attributionControl: true })
      .setView(opts.center || [9.0725, 7.442], opts.zoom || 13);
    // Esri street map, turned monochrome and tinted to the ink scale in CSS
    // (.map-base) so markers carry the only colour on the map. The built
    // site would use a styled vector map (MapLibre) with the same look.
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
      attribution: 'Tiles © Esri, HERE, Garmin, © OpenStreetMap contributors', maxZoom: 18, className: 'map-base',
    }).addTo(map);
    L.control.zoom({ position: opts.zoomPosition || 'bottomright' }).addTo(map);
    const markers = {};
    C.locations.forEach((l) => {
      const icon = L.divIcon({
        className: 'marker-wrap', iconSize: [0, 0],
        html: `<button class="marker ${l.free <= 2 ? 'is-last' : ''}" data-slug="${l.slug}" aria-label="${l.name}, from ${naira(l.from)}">${opts.label ? opts.label(l) : C.short(l.from)}</button>`,
      });
      markers[l.slug] = L.marker(l.pos, { icon, riseOnHover: true }).addTo(map);
    });
    if (opts.fit !== false) map.fitBounds(C.locations.map((l) => l.pos), { paddingTopLeft: opts.padTL || [60, 60], paddingBottomRight: opts.padBR || [60, 60] });
    const each = (fn) => el.querySelectorAll('.marker').forEach(fn);
    return {
      map,
      activate(slug) { each((m) => m.classList.toggle('is-active', m.dataset.slug === slug)); },
      dim(slugs) { each((m) => m.classList.toggle('is-dim', slugs && !slugs.includes(m.dataset.slug))); },
      on(fn) { el.addEventListener('click', (e) => { const m = e.target.closest('.marker'); if (m) fn(m.dataset.slug); }); },
      hover(fn) { el.addEventListener('mouseover', (e) => { const m = e.target.closest('.marker'); if (m) fn(m.dataset.slug); }); },
      flyTo(slug) { const l = C.locations.find((x) => x.slug === slug); map.flyTo(l.pos, 15, { duration: .6 }); },
    };
  };

  /* Search-bar popover for "Where" (multi-select locations). */
  C.wherePicker = function (seg, selected, onChange) {
    const pop = document.createElement('div');
    pop.className = 'popover'; pop.hidden = true; pop.setAttribute('role', 'listbox'); pop.setAttribute('aria-multiselectable', 'true');
    seg.parentElement.style.position = 'relative';
    seg.parentElement.appendChild(pop);
    const draw = () => {
      pop.innerHTML = C.locations.map((l) => `
        <button type="button" class="option" role="option" aria-checked="${selected.has(l.slug)}" data-slug="${l.slug}">
          <span class="check"></span><span><b class="h3" style="font-size:var(--t-md)">${l.name}</b><br><span class="meta">${l.area}</span></span>
          <span class="end">${l.free > 0 ? `<span class="tag ${l.free <= 2 ? 'tag-last' : 'tag-free'}">${l.free} free</span>` : ''}</span>
        </button>`).join('');
      const n = selected.size;
      seg.querySelector('.seg-value').textContent = n === C.locations.length ? 'All 5 locations' : n === 1 ? C.locations.find((l) => selected.has(l.slug)).name : `${n} locations`;
    };
    draw();
    seg.addEventListener('click', () => {
      pop.hidden = !pop.hidden; seg.setAttribute('aria-expanded', !pop.hidden);
      pop.style.left = seg.offsetLeft + 'px'; pop.style.top = seg.offsetTop + seg.offsetHeight + 12 + 'px';
    });
    pop.addEventListener('click', (e) => {
      const o = e.target.closest('.option'); if (!o) return;
      selected.has(o.dataset.slug) ? (selected.size > 1 && selected.delete(o.dataset.slug)) : selected.add(o.dataset.slug);
      draw(); onChange && onChange();
    });
    document.addEventListener('click', (e) => { if (!pop.contains(e.target) && !seg.contains(e.target)) { pop.hidden = true; seg.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { pop.hidden = true; seg.setAttribute('aria-expanded', 'false'); } });
  };
})();
