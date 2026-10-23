// Centurion brand tokens (docs/staff-app/PLAN.md "Brand tokens").

import 'package:flutter/material.dart';

class C {
  C._();

  static const paper = Color(0xFFEEF3F7);
  static const card = Color(0xFFFFFFFF);
  static const cardDeep = Color(0xFFF4F7FA);
  static const track = Color(0xFFDCE4EC);
  static const ink = Color(0xFF1A2430);
  static const inkSoft = Color(0xFF4A5563);
  static const inkMute = Color(0xFF6B7785);
  static const inkFaint = Color(0xFF9AA4AF);
  static const inkDeep = Color(0xFF16324B);
  static const accent = Color(0xFF336799);
  static const accentDeep = Color(0xFF285480);
  static const accentSoft = Color(0x1F336799); // 12%
  static const accentSoftSolid = Color(0xFFE2EAF2);
  static const line = Color(0x1A1A2430); // 10%
  static const lineSoft = Color(0x0F1A2430); // 6%
  static const tickRing = Color(0xFFBFCAD5);

  static const toCleanBg = Color(0xFFFBE3DC);
  static const toCleanFg = Color(0xFF9A3F27);
  static const readyBg = Color(0xFFDDF0E3);
  static const readyFg = Color(0xFF1E6B3A);
  static const warnBg = Color(0xFFFCF1D6);
  static const warnFg = Color(0xFF8A5A00);
}

class F {
  F._();

  static const display = 'Bricolage';
  static const body = 'Hanken';
}

/// Text styles used across the app. Sizes follow the A mockups.
class T {
  T._();

  static const eyebrow = TextStyle(
    fontFamily: F.body,
    fontSize: 11.5,
    letterSpacing: 1.5,
    fontWeight: FontWeight.w600,
    color: C.inkMute,
  );
  static const sectionLabel = TextStyle(
    fontFamily: F.body,
    fontSize: 13,
    letterSpacing: 1.5,
    fontWeight: FontWeight.w600,
    color: C.inkMute,
  );
  static const h1 = TextStyle(
    fontFamily: F.display,
    fontSize: 30,
    height: 1.1,
    fontWeight: FontWeight.w600,
    color: C.ink,
    letterSpacing: -0.3,
  );
  static const roomTitle = TextStyle(
    fontFamily: F.display,
    fontSize: 32,
    height: 1.05,
    fontWeight: FontWeight.w600,
    color: C.ink,
    letterSpacing: -0.3,
  );
  static const sub = TextStyle(fontFamily: F.body, fontSize: 14.5, color: C.inkMute, height: 1.3);
  static const cardTitle = TextStyle(fontFamily: F.body, fontSize: 15.5, fontWeight: FontWeight.w600, color: C.ink);
  static const cardSub = TextStyle(fontFamily: F.body, fontSize: 13.5, color: C.inkMute, height: 1.3);
  static const body = TextStyle(fontFamily: F.body, fontSize: 16, color: C.ink, height: 1.3);
  static const chip = TextStyle(fontFamily: F.body, fontSize: 12.5, fontWeight: FontWeight.w600);
  static const action = TextStyle(fontFamily: F.body, fontSize: 17, fontWeight: FontWeight.w600, color: Colors.white);
}
