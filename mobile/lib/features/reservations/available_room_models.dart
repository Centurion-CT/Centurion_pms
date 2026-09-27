// Dataclasses mirroring AvailableRoomResource. Used by the manual
// booking form to render the room-picker list.

class AvailableRoomCategory {
  const AvailableRoomCategory({
    required this.id,
    required this.name,
    required this.capacityAdults,
    required this.capacityChildren,
    required this.baseRate,
    required this.hasBreakfastOption,
    this.breakfastRate,
  });

  final int id;
  final String name;
  final int capacityAdults;
  final int capacityChildren;
  final int baseRate;
  final int? breakfastRate;
  final bool hasBreakfastOption;

  factory AvailableRoomCategory.fromJson(Map<String, dynamic> j) => AvailableRoomCategory(
        id: j['id'] as int,
        name: (j['name'] as String?) ?? '—',
        capacityAdults: (j['capacity_adults'] as int?) ?? 0,
        capacityChildren: (j['capacity_children'] as int?) ?? 0,
        baseRate: (j['base_rate'] as int?) ?? 0,
        breakfastRate: j['breakfast_rate'] as int?,
        hasBreakfastOption: (j['has_breakfast_option'] as bool?) ?? false,
      );
}

class AvailableRoom {
  const AvailableRoom({
    required this.id,
    required this.roomNumber,
    this.category,
  });

  final int id;
  final String? roomNumber;
  final AvailableRoomCategory? category;

  factory AvailableRoom.fromJson(Map<String, dynamic> j) => AvailableRoom(
        id: j['id'] as int,
        roomNumber: j['room_number'] as String?,
        category: j['category'] != null
            ? AvailableRoomCategory.fromJson((j['category'] as Map).cast<String, dynamic>())
            : null,
      );

  int rateFor({required bool breakfastIncluded}) {
    final base = category?.baseRate ?? 0;
    if (breakfastIncluded && (category?.hasBreakfastOption ?? false)) {
      return category?.breakfastRate ?? base;
    }
    return base;
  }
}
