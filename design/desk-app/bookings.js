// Mock stays for the calendars. Day 0 is Friday 23 October.
// [room, startDay, nights, guest, kind]; kind: in | booked | owed | co | hold | ooo | over
window.DAYS = Array.from({ length: 14 }, (_, i) => {
  const d = new Date(2026, 9, 23 + i);
  return { dow: d.toLocaleDateString('en-GB', { weekday: 'short' }), day: d.getDate(), mon: d.toLocaleDateString('en-GB', { month: 'short' }), wknd: [0, 6].includes(d.getDay()) };
});
window.STAYS = [
  ['101', -3, 9, 'Oluwaseun Adebayo', 'in'],
  ['102', -1, 6, 'Kemi Ogunleye', 'in'], ['102', 6, 4, 'Daniel Etim', 'booked'],
  ['103', 0, 2, 'Chiamaka Eze', 'owed'], ['103', 4, 3, 'Sade Lawal', 'booked'],
  ['104', -2, 7, 'Segun Alabi', 'in'], ['104', 8, 5, 'Hauwa Bello', 'booked'],
  ['105', 3, 2, 'Peter Udo', 'booked'], ['105', 9, 3, 'Walk-in hold', 'hold'],
  ['106', -4, 4, 'Yusuf Bello', 'in'], ['106', 1, 5, 'Bisi Ojo', 'booked'],
  ['107', -1, 5, 'Grace Ekpo', 'in'], ['107', 7, 4, 'Tolu Martins', 'booked'],
  ['108', -2, 3, 'Musa Danjuma', 'in'], ['108', 5, 2, 'Ifeoma Uche', 'booked'],
  ['201', -5, 8, 'Femi Akande', 'in'], ['201', 9, 4, 'Zenith Bank, guest', 'co'],
  ['202', -1, 4, 'Ada Nnamdi', 'in'], ['202', 6, 6, 'Zenith Bank, guest', 'co'],
  ['203', -6, 14, 'Dangote Group, Ahmed Sule', 'co'],
  ['204', 0, 3, 'Adaeze Okafor', 'booked'], ['204', 5, 2, 'Kola Adeniyi', 'booked'],
  ['205', -4, 5, 'Emeka Nwosu', 'over'], ['205', 3, 3, 'Halima Garba', 'booked'],
  ['206', -1, 3, 'Joy Okon', 'in'], ['206', 4, 6, 'Uche Nwankwo', 'booked'],
  ['207', 1, 1, 'Bayo Ola', 'booked'],
  ['208', 0, 1, 'Ibrahim Musa', 'booked'], ['208', 2, 5, 'Rita Asuquo', 'owed'],
  ['209', -2, 6, 'Kunle Bello', 'in'], ['209', 10, 3, 'Ngozi Eke', 'booked'],
  ['210', -3, 3, 'Ngozi Chukwu', 'in'], ['210', 3, 4, 'Ayo Fashola', 'booked'],
  ['301', 0, 7, 'Tunde Bakare, Dangote', 'co'],
  ['302', -2, 7, 'Grace Ekpo family', 'in'], ['302', 8, 4, 'Obinna Eze', 'booked'],
  ['303', 0, 4, 'Plumbing repair', 'ooo'],
  ['304', -1, 5, 'Segun Alabi party', 'in'], ['304', 6, 7, 'Amaka Igwe', 'booked'],
  ['305', 2, 2, 'Lanre Shittu', 'booked'], ['305', 6, 3, 'Phone hold, Mrs Ojo', 'hold'],
  ['306', -7, 10, 'Chidi Okeke', 'in'],
];
window.nightsBooked = (day) => new Set(STAYS.filter(([, s, n, , k]) => k !== 'ooo' && s <= day && day < s + n).map(([r]) => r)).size;
