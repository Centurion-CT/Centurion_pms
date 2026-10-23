// Room card copy and colours (shared by the list and the cleaning screen).

import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../staff/models.dart';

class RoomLook {
  const RoomLook(this.chip, this.bg, this.fg);

  final String chip;
  final Color bg;
  final Color fg;
}

RoomLook roomLook(RoomState s) {
  switch (s) {
    case RoomState.toClean:
      return const RoomLook(S.chipToClean, C.toCleanBg, C.toCleanFg);
    case RoomState.cleaning:
      return const RoomLook(S.chipCleaning, C.warnBg, C.warnFg);
    case RoomState.sentBack:
      return const RoomLook(S.chipSentBack, C.toCleanBg, C.toCleanFg);
    case RoomState.withPorter:
      return const RoomLook(S.chipWithPorter, C.accentSoftSolid, C.accentDeep);
    case RoomState.ready:
      return const RoomLook(S.chipReady, C.readyBg, C.readyFg);
  }
}

/// Badge colours: open rooms keep the warm "to clean" tone.
RoomLook badgeLook(RoomState s) {
  if (s == RoomState.withPorter || s == RoomState.ready) return roomLook(s);
  return const RoomLook('', C.toCleanBg, C.toCleanFg);
}

String arrivalLine(DateTime? at, bool today) {
  if (at != null) return S.guestArriving(hm(at));
  if (today) return S.guestArrivingToday;
  return '';
}

/// One-line reason under the category (PLAN.md card rules). Empty means
/// show the category only.
String roomSubtitle(Room r) {
  if (r.state == RoomState.sentBack && r.sentBack != null) return S.porterSentBack(r.sentBack!.reasonsLabel);
  final arriving = arrivalLine(r.arrivingAt, r.arrivingToday);
  if (arriving.isNotEmpty) return arriving;
  if (r.state == RoomState.withPorter) return S.waitingForPorter;
  if (r.state == RoomState.ready) return S.readyForGuest;
  if (r.guestLeftAt != null) return S.checkedOut(hm(r.guestLeftAt));
  return '';
}

/// "Deluxe King · Guest arriving today" for the cleaning screen eyebrow.
String roomEyebrow(Room r) {
  final arriving = arrivalLine(r.arrivingAt, r.arrivingToday);
  final extra = arriving.isNotEmpty ? arriving : (r.guestLeftAt != null ? S.checkedOut(hm(r.guestLeftAt)) : '');
  return [r.category, extra].where((s) => s.isNotEmpty).join(' · ');
}
