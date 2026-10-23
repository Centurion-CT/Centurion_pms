// Contract icon keys -> Lucide icons.

import 'package:flutter/widgets.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

IconData itemIcon(String key) {
  switch (key) {
    case 'toilet':
      return LucideIcons.toilet;
    case 'shower':
      return LucideIcons.bath;
    case 'towel':
      return LucideIcons.shirt;
    case 'soap':
      return LucideIcons.droplets;
    case 'sheets':
      return LucideIcons.bedDouble;
    case 'dust':
      return LucideIcons.sparkles;
    case 'floor':
      return LucideIcons.brushCleaning;
    case 'light':
      return LucideIcons.lightbulb;
    case 'water':
      return LucideIcons.glassWater;
    case 'bin':
      return LucideIcons.trash2;
    case 'minibar':
      return LucideIcons.refrigerator;
    default:
      return LucideIcons.circleCheck;
  }
}

IconData groupIcon(String key) {
  switch (key) {
    case 'bathroom':
      return LucideIcons.bath;
    case 'bedroom':
      return LucideIcons.bedDouble;
    case 'extras':
      return LucideIcons.glassWater;
    default:
      return LucideIcons.house;
  }
}

/// Product icons: the server's key first, then a guess from the name.
IconData productIcon(String key, String name) {
  final k = '$key $name'.toLowerCase();
  if (k.contains('toilet') || k.contains('roll') || k.contains('tissue')) return LucideIcons.scroll;
  if (k.contains('soap') || k.contains('shampoo') || k.contains('gel')) return LucideIcons.droplets;
  if (k.contains('towel')) return LucideIcons.shirt;
  if (k.contains('water') || k.contains('bottle')) return LucideIcons.glassWater;
  if (k.contains('sheet') || k.contains('pillow') || k.contains('linen') || k.contains('duvet')) {
    return LucideIcons.bedDouble;
  }
  if (k.contains('bin') || k.contains('bag')) return LucideIcons.trash2;
  if (k.contains('clean') || k.contains('bleach') || k.contains('detergent')) return LucideIcons.sprayCan;
  return LucideIcons.package;
}
