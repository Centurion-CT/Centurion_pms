// Mock house for both directions: 24 rooms over three categories.
// state: in | arr | leave | clean | free | ooo; nights = booked in the next 7.
window.HOUSE = [
  ['Deluxe studio', [
    ['101', 'in', 7], ['102', 'in', 5], ['103', 'arr', 2], ['104', 'in', 6],
    ['105', 'free', 0], ['106', 'leave', 3], ['107', 'in', 4], ['108', 'in', 3]]],
  ['One-bedroom suite', [
    ['201', 'in', 4], ['202', 'in', 3], ['203', 'in', 7], ['204', 'clean', 3], ['205', 'in', 6, 'over'],
    ['206', 'in', 2], ['207', 'free', 1], ['208', 'arr', 1], ['209', 'in', 5], ['210', 'leave', 2]]],
  ['Two-bedroom apartment', [
    ['301', 'arr', 7], ['302', 'in', 6], ['303', 'ooo', 0], ['304', 'in', 4], ['305', 'free', 2], ['306', 'in', 7]]],
];
window.STATE_LABEL = { in: 'In-house', arr: 'Arriving', leave: 'Leaving today', clean: 'Being cleaned', free: 'Free', ooo: 'Out of order' };
