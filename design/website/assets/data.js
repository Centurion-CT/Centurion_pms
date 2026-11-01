/*
 * Shared sample data for the website mockups. Locations, Wuse 2 room
 * types and the "from" prices come from the current Tripmata listing;
 * free-tonight counts, the other locations' room types and room counts
 * are made up for the mockup. In the built site all of it comes from
 * the HMS (properties, room categories, availability, rates).
 */
window.CENTURION = {
  phone: '0816 241 7819',
  phoneCare: '0908 835 8213',
  locations: [
    {
      slug: 'wuse-2', name: 'Wuse 2', area: 'Wuse II', address: '39 Agadez Street, Wuse II',
      lat: 9.079, lng: 7.468, free: 3, rooms: 14, from: 59400, rating: 10, reviews: 7,
      photo: 'living-green.jpg',
      note: 'Banex, Ademola Adetokunbo and the restaurants of Wuse II on foot. 10 minutes to the Central Business District.',
      types: [
        { name: 'Standard', sleeps: 2, rate: 59400, was: 60000, left: 0 },
        { name: 'Deluxe apartment', sleeps: 2, rate: 63200, was: 80000, left: 1 },
        { name: 'Executive', sleeps: 2, rate: 84000, was: 100000, left: 0 },
        { name: 'One bedroom', sleeps: 2, rate: 94800, was: 120000, left: 1 },
        { name: 'Two bedroom family', sleeps: 4, rate: 150100, was: 190000, left: 1 },
      ],
    },
    {
      slug: 'zone-4', name: 'Zone 4', area: 'Wuse Zone 4', address: 'Wuse Zone 4',
      lat: 9.066, lng: 7.479, free: 5, rooms: 10, from: 71100, rating: 8.3, reviews: 3,
      photo: 'living-cream.jpg',
      note: 'Walking distance to Berger, Wuse Market and the banks on Herbert Macaulay Way.',
      types: [
        { name: 'Deluxe apartment', sleeps: 2, rate: 71100, was: 90000, left: 2 },
        { name: 'Executive', sleeps: 2, rate: 95000, left: 1 },
        { name: 'One bedroom', sleeps: 2, rate: 110000, left: 1 },
        { name: 'Two bedroom', sleeps: 4, rate: 165000, left: 1 },
      ],
    },
    {
      slug: 'jabi', name: 'Jabi', area: 'Jabi', address: 'Jabi, near Jabi Lake Mall',
      lat: 9.066, lng: 7.425, free: 2, rooms: 8, from: 58500, rating: 8.0, reviews: 3,
      photo: 'living-window.jpg',
      note: 'Five minutes from Jabi Lake Mall and the lakeside walk. Quiet streets, easy run to Utako.',
      types: [
        { name: 'Standard', sleeps: 2, rate: 58500, was: 65000, left: 1 },
        { name: 'Deluxe apartment', sleeps: 2, rate: 70000, left: 1 },
        { name: 'One bedroom', sleeps: 2, rate: 98000, left: 0 },
      ],
    },
    {
      slug: 'jabi-annex', name: 'Jabi Annex', area: 'Jabi', address: 'Jabi',
      lat: 9.074, lng: 7.437, free: 6, rooms: 9, from: 60000, rating: null, reviews: 0,
      photo: 'living-open.jpg',
      note: 'Our newest building: modern fittings, full kitchens and a short drive to Jabi Lake.',
      types: [
        { name: 'Studio', sleeps: 2, rate: 60000, was: 75000, left: 3 },
        { name: 'One bedroom', sleeps: 2, rate: 85000, left: 2 },
        { name: 'Two bedroom', sleeps: 4, rate: 140000, left: 1 },
      ],
    },
    {
      slug: 'lifecamp', name: 'Lifecamp', area: 'Life Camp', address: 'Life Camp',
      lat: 9.083, lng: 7.405, free: 1, rooms: 2, from: 50000, rating: null, reviews: 0,
      photo: 'living-warm.jpg',
      note: 'A two-bedroom duplex with a shared pool and gym. Room for a family or a team on a long job.',
      types: [
        { name: 'Two bedroom duplex', sleeps: 4, rate: 50000, left: 1 },
      ],
    },
  ],
  review: { quote: "It's my home away from home. It's simply amazing!", by: 'Mustapha A.', where: 'Wuse 2', score: 10 },
};

window.naira = (n) => '₦' + Math.round(n).toLocaleString('en-NG');

/*
 * A drawn map of west and central Abuja with a pin per location.
 * Positions come from each location's lat/lng; roads and landmarks are
 * approximate. The built site would use a real map tile layer.
 */
window.abujaMap = function (opts = {}) {
  const W = 600, H = 380;
  const x = (lng) => ((lng - 7.38) / 0.14) * W;
  const y = (lat) => ((9.11 - lat) / 0.08) * H;
  const pins = window.CENTURION.locations.map((l) => {
    const px = x(l.lng).toFixed(0), py = y(l.lat).toFixed(0);
    const label = opts.label ? opts.label(l) : l.name;
    return `<g class="map-pin" data-slug="${l.slug}" transform="translate(${px} ${py})" tabindex="0" role="button" aria-label="${l.name}">
      <circle class="map-pin-halo" r="18"/>
      <circle class="map-pin-dot" r="7"/>
      <text class="map-pin-label" x="12" y="-12">${label}</text>
    </g>`;
  }).join('');
  return `<svg class="abuja-map" viewBox="0 0 ${W} ${H}" preserveAspectRatio="${opts.fill ? 'xMidYMid slice' : 'xMidYMid meet'}" role="img" aria-label="Map of the five Centurion locations in Abuja">
    <rect class="map-land" width="${W}" height="${H}"/>
    <path class="map-green" d="M520 0 C 560 60 600 90 600 140 L600 0 Z"/>
    <path class="map-green" d="M0 300 C 60 280 110 320 150 380 L0 380 Z"/>
    <ellipse class="map-water" cx="150" cy="186" rx="34" ry="20" transform="rotate(-18 150 186)"/>
    <path class="map-road major" d="M20 250 C 160 230 300 190 420 150 S 560 110 600 95"/>
    <path class="map-road major" d="M60 40 C 160 120 260 200 330 250 S 460 340 520 380"/>
    <path class="map-road" d="M300 0 C 320 90 330 160 360 240 S 420 330 430 380"/>
    <path class="map-road" d="M0 140 C 120 150 240 150 600 220"/>
    <path class="map-road" d="M470 0 C 470 120 480 220 500 380"/>
    <text class="map-place" x="150" y="226" text-anchor="middle">Jabi Lake</text>
    <text class="map-place" x="490" y="292">Central Business District</text>
    <text class="map-place" x="560" y="60" text-anchor="end">Maitama</text>
    <text class="map-place" x="300" y="300">Utako</text>
    <text class="map-place" x="590" y="250" text-anchor="end">Aso Rock →</text>
    <text class="map-place" x="16" y="368">↙ Airport, 35 min</text>
    ${pins}
  </svg>`;
};
