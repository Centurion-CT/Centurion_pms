// Shared behaviour for the platform mockups: address slugs and the
// "is this address free?" check (sample data stands in for the API).
window.CX = (() => {
  const SUFFIX = '.centurionpms.com';
  // Addresses already taken, plus reserved words nobody can claim.
  const TAKEN = ['centurion', 'grandpalace', 'lekkipearl', 'mabushicourt', 'harmony', 'cedarplace', 'bodijahomes', 'gra-suites', 'ogui-road'];
  const RESERVED = ['www', 'app', 'admin', 'api', 'mail', 'login', 'signup', 'help', 'status', 'console', 'billing', 'docs'];

  const slugify = s => s.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/&/g, ' and ').replace(/\b(the|ltd|limited|nig|nigeria)\b/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-{2,}/g, '-').slice(0, 30);

  function check(slug) {
    if (!slug) return { state: '', text: 'Letters, numbers and dashes. You can add your own domain later.' };
    if (slug.length < 3) return { state: 'is-taken', text: 'Use at least 3 characters.' };
    if (RESERVED.includes(slug)) return { state: 'is-taken', text: `“${slug}” is reserved. Try another.` };
    if (TAKEN.includes(slug)) {
      const alt = [`${slug}-abuja`, `${slug}-suites`, `${slug}-apartments`].filter(a => !TAKEN.includes(a));
      return { state: 'is-taken', text: `${slug}${SUFFIX} is taken. Try ${alt[0]} or ${alt[1]}.`, alt };
    }
    return { state: 'is-free', text: `${slug}${SUFFIX} is free.` };
  }

  // Wire an .address input to its .address-status line.
  function bindAddress(input, status, onChange) {
    const run = () => {
      const clean = slugify(input.value) + (/-$/.test(input.value) ? '-' : '');
      if (input.value !== clean.replace(/-$/, '') && document.activeElement !== input) input.value = clean;
      const r = check(slugify(input.value));
      status.className = 'address-status ' + r.state;
      status.textContent = r.text;
      onChange && onChange(slugify(input.value), r);
    };
    input.addEventListener('input', () => {
      input.value = input.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
      run();
    });
    input.addEventListener('blur', run);
    run();
    return run;
  }

  return { SUFFIX, slugify, check, bindAddress };
})();
