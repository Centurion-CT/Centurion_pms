// Direction B window chrome: navy title bar and sidebar, tab strip of
// open work. The page sets <body data-nav="..." data-tabs='[...]'> where
// each tab is [label, dotColour|null, active].
(function () {
  const nav = [
    ['Front desk', [
      ['today', 'Today', 'layout-dashboard', 'g-blue'],
      ['reservations', 'Reservations', 'bookmark', 'g-blue', '6'],
      ['calendar', 'Room calendar', 'calendar-days', 'g-rose'],
      ['inhouse', 'In-house', 'building-2', 'g-cyan', '14'],
      ['requests', 'Guest requests', 'message-square', 'g-amber', null, '3'],
      ['approvals', 'Approvals', 'circle-check', 'g-violet', null, '3'],
    ]],
    ['Rooms', [
      ['rooms', 'Rooms', 'bed-double', 'g-orange'],
      ['housekeeping', 'Housekeeping', 'sparkles', 'g-green', '4'],
    ]],
    ['Money and guests', [
      ['shift', 'My shift', 'wallet', 'g-emerald'],
      ['invoices', 'Invoices', 'receipt-text', 'g-emerald'],
      ['guests', 'Guests', 'users', 'g-violet'],
      ['corporates', 'Corporate accounts', 'building', 'g-teal'],
    ]],
  ];
  const active = document.body.dataset.nav;
  const links = nav.map(([h, items]) => `<h6>${h}</h6>` + items.map(([k, label, icon, tint, n, badge]) =>
    `<a class="${k === active ? 'on' : ''}"><i data-lucide="${icon}" class="${tint}"></i>${label}` +
    (badge ? `<span class="badge">${badge}</span>` : n ? `<span class="n">${n}</span>` : '') + '</a>').join('')).join('') +
    `<h6>Web dashboard</h6><a><i data-lucide="globe" style="color:#9FB6CC"></i>Rates and reports<i data-lucide="external-link" style="margin-left:auto;width:13px;color:#7F9AB4"></i></a>`;

  const tabs = JSON.parse(document.body.dataset.tabs || '[["Today",null,true]]');
  const tabHtml = tabs.map(([label, dot, on], i) =>
    `<div class="tab ${on ? 'on' : ''}">${i === 0 ? '<i data-lucide="layout-dashboard" class="s14"></i>' : dot ? `<span class="tdot" style="background:${dot}"></span>` : ''}${label}${i ? '<i data-lucide="x" class="s14 x"></i>' : ''}</div>`).join('');

  const win = `<div class="win"><img src="assets/logo.png" alt=""><span class="wtitle">Centurion Desk</span>
    <span class="wctl"><span><i data-lucide="minus"></i></span><span><i data-lucide="square"></i></span><span><i data-lucide="x"></i></span></span></div>`;
  const side = `<aside class="side"><div class="brand"><span class="lg"><img src="assets/logo.png" alt="Centurion"></span><b>Centurion<br>Apartments</b></div>
    <nav>${links}</nav>
    <div class="me"><span class="avatar" style="background:var(--blue);color:#fff">TA</span><div><b>Tobi Adewale</b><small>Front desk</small></div><span class="lockb" title="Lock or switch (Ctrl L)"><i data-lucide="lock" class="s16"></i></span></div></aside>`;
  const strip = `<div class="tabs">${tabHtml}<span class="plus"><i data-lucide="plus" class="s16"></i></span>
    <div class="right"><div class="search"><i data-lucide="search" class="s16"></i><span>Find guest, booking, room</span><kbd>Ctrl K</kbd></div>
    <span class="ic"><span class="dot" style="background:#5ED1A8"></span>Saved</span><span class="ic"><i data-lucide="printer" class="s14"></i>2</span></div></div>`;

  const main = document.querySelector('.main');
  main.insertAdjacentHTML('afterbegin', strip);
  const app = document.createElement('div');
  app.className = 'app';
  app.innerHTML = win + '<div class="body">' + side + '</div>';
  document.body.prepend(app);
  app.querySelector('.body').appendChild(main);
  lucide.createIcons();
})();
