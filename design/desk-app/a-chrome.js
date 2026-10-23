// Direction A window chrome: title bar, sidebar, status bar. The page
// supplies <main class="main"> and sets <body data-nav="today">.
(function () {
  const nav = [
    ['Front desk', [
      ['today', 'Today', 'layout-dashboard', 't-blue'],
      ['reservations', 'Reservations', 'bookmark', 't-blue', '6'],
      ['calendar', 'Room calendar', 'calendar-days', 't-rose'],
      ['inhouse', 'In-house', 'building-2', 't-cyan', '14'],
      ['requests', 'Guest requests', 'message-square', 't-amber', null, '2'],
      ['approvals', 'Approvals', 'circle-check', 't-violet', null, '1'],
    ]],
    ['Rooms', [
      ['rooms', 'Rooms', 'bed-double', 't-orange'],
      ['housekeeping', 'Housekeeping', 'sparkles', 't-green', '4'],
    ]],
    ['Money', [
      ['shift', 'My shift', 'wallet', 't-emerald'],
      ['invoices', 'Invoices', 'receipt-text', 't-emerald'],
    ]],
    ['Guests', [
      ['guests', 'Guests', 'users', 't-violet'],
      ['corporates', 'Corporate accounts', 'building', 't-teal'],
    ]],
  ];
  const active = document.body.dataset.nav;
  const links = nav.map(([h, items]) => `<h6>${h}</h6>` + items.map(([k, label, icon, tint, n, badge]) =>
    `<a class="${k === active ? 'on' : ''}"><span class="ic ${tint}"><i data-lucide="${icon}"></i></span>${label}` +
    (badge ? `<span class="badge">${badge}</span>` : n ? `<span class="n">${n}</span>` : '') + '</a>').join('')).join('');

  const win = `<div class="win"><img src="assets/logo.png" alt=""><span class="wtitle">Centurion Desk</span>
    <span class="wctl"><span><i data-lucide="minus"></i></span><span><i data-lucide="square"></i></span><span><i data-lucide="x"></i></span></span></div>`;
  const side = `<aside class="side"><div class="brand"><img src="assets/logo.png" alt="Centurion">
    <div><b>Centurion Apartments</b><small><span class="dot" style="background:var(--green);width:6px;height:6px"></span>Reception desk 1</small></div></div>
    <nav>${links}</nav>
    <div class="web"><i data-lucide="globe" class="s16"></i>Rates, reports and settings open the web dashboard</div></aside>`;
  const status = `<div class="status">
    <span><span class="dot" style="background:var(--green)"></span>Online, all saved</span>
    <span><i data-lucide="printer" class="s14"></i>Receipts: Epson TM-T20 ready</span>
    <span><i data-lucide="file-text" class="s14"></i>A4: HP LaserJet front office</span>
    <span class="who"><span class="avatar" style="background:var(--blue-soft);color:var(--blue-deep)">TA</span>Tobi Adewale, front desk, on since 7:02 am</span>
    <span class="lock"><i data-lucide="lock" class="s14"></i>Lock or switch <kbd>Ctrl L</kbd></span></div>`;

  const main = document.querySelector('.main');
  const app = document.createElement('div');
  app.className = 'app';
  app.innerHTML = win + '<div class="body">' + side + '</div>' + status;
  document.body.prepend(app);
  app.querySelector('.body').appendChild(main);
  lucide.createIcons();
})();
