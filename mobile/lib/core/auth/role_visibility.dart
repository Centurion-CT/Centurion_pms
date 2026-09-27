// Role-based UI gates. Mirrors the server-side ALLOWED_ROLES lists
// on each controller so the operator doesn't see tabs / buttons
// that will just 403 when tapped.
//
// The bottom-nav model:
//   - Home is universal (Manager glance for eligible roles;
//     welcome card for others — see HomeScreen).
//   - Outbox is universal (every operator has writes that queue).
//   - Housekeeping is for the housekeeping chain + Manager (who
//     can act as any signer).
//   - Reservations is for Manager / Frontdesk / Reservation Officer.
//     Porters + Housekeepers CAN'T see it — they don't take payments
//     or check guests in.
//   - POS is for PosStaff / InventoryClerk / StockKeeper / Manager.

class RoleVisibility {
  RoleVisibility._(this.role);

  /// Empty role → treat as unauthenticated / new (only Home + Outbox
  /// which don't need role info to render).
  final String? role;

  factory RoleVisibility.forRole(String? role) => RoleVisibility._(role);

  bool get canSeeHome => true;
  bool get canSeeOutbox => true;

  bool get canSeeHousekeeping => switch (role) {
        'manager' || 'house_cleaner' || 'porter' || 'supervisor' => true,
        _ => false,
      };

  bool get canSeeReservations => switch (role) {
        'manager' || 'frontdesk' || 'reservation_officer' => true,
        _ => false,
      };

  bool get canSeePos => switch (role) {
        'manager' || 'pos_staff' || 'inventory_clerk' || 'stock_keeper' => true,
        _ => false,
      };

  /// Can this operator create a booking? Mirrors
  /// BookingsController::ALLOWED_ROLES on the server so the FAB is
  /// hidden for anyone who'd just get 403 from the create endpoint.
  bool get canCreateBooking => switch (role) {
        'manager' || 'frontdesk' || 'reservation_officer' => true,
        _ => false,
      };

  /// Can this operator approve/reject stock requests? Same idea —
  /// mirrors InventoryController::guardManager.
  bool get canApproveStockRequests => role == 'manager';
}
