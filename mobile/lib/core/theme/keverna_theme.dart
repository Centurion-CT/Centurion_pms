// Keverna brand tokens — mirrors the web CSS custom properties in
// resources/css/app.css. When the web palette changes, mirror the
// change here so both surfaces stay in sync.
//
// Web token          →  Dart constant
// --color-accent     →  accent           (lime green, primary CTA)
// --color-accent-deep→  accentDeep       (button hover / active glyph)
// --color-card       →  card             (cream, background of surfaces)
// --color-card-deep  →  cardDeep         (input fill, muted chips)
// --color-ink        →  ink              (body text)
// --color-ink-soft   →  inkSoft          (secondary text, labels)
// --color-ink-mute   →  inkMute          (tertiary text, timestamps)
// --color-line-soft  →  lineSoft         (subtle borders)

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class KevernaColors {
  // Web accent tokens — do not tweak per-widget; alias here.
  static const Color accent = Color(0xFFBEE44E);
  static const Color accentDeep = Color(0xFF6F8B24);

  // Surfaces.
  static const Color card = Color(0xFFF6F3EA);
  static const Color cardDeep = Color(0xFFECE7D8);

  // Text.
  static const Color ink = Color(0xFF1D1E1C);
  static const Color inkSoft = Color(0xFF5B5D57);
  static const Color inkMute = Color(0xFF8B8E85);

  // Borders + errors.
  static const Color lineSoft = Color(0xFFE2DED0);
  static const Color danger = Color(0xFFB85C42);
  static const Color success = Color(0xFF3D7B4F);
  static const Color warn = Color(0xFFF59E0B);

  // Sidebar (dark navigation, mirroring web sidebar palette).
  static const Color sidebarBg = Color(0xFF191A18);
  static const Color sidebarInk = Color(0xFFF4EFE6);
}

class KevernaTheme {
  KevernaTheme._();

  static ThemeData light() {
    final base = ThemeData.light(useMaterial3: true);

    // Google Fonts is cached to disk after the first fetch — the
    // first cold-start is the only slow one; subsequent launches
    // render offline. Fraunces for display copy, Inter for body.
    final display = GoogleFonts.fraunces();
    final body = GoogleFonts.inter();

    return base.copyWith(
      colorScheme: ColorScheme.fromSeed(
        seedColor: KevernaColors.accent,
        primary: KevernaColors.accent,
        onPrimary: KevernaColors.ink,
        secondary: KevernaColors.accentDeep,
        surface: KevernaColors.card,
        onSurface: KevernaColors.ink,
        error: KevernaColors.danger,
      ),
      scaffoldBackgroundColor: KevernaColors.card,
      canvasColor: KevernaColors.card,
      dividerColor: KevernaColors.lineSoft,

      textTheme: base.textTheme.copyWith(
        displayLarge: display.copyWith(color: KevernaColors.ink, fontWeight: FontWeight.w600),
        displayMedium: display.copyWith(color: KevernaColors.ink, fontWeight: FontWeight.w600),
        displaySmall: display.copyWith(color: KevernaColors.ink, fontWeight: FontWeight.w600),
        headlineMedium: display.copyWith(color: KevernaColors.ink, fontWeight: FontWeight.w600, fontSize: 22),
        titleLarge: body.copyWith(color: KevernaColors.ink, fontWeight: FontWeight.w600),
        titleMedium: body.copyWith(color: KevernaColors.ink, fontWeight: FontWeight.w500),
        bodyLarge: body.copyWith(color: KevernaColors.ink),
        bodyMedium: body.copyWith(color: KevernaColors.inkSoft, fontSize: 14),
        bodySmall: body.copyWith(color: KevernaColors.inkMute, fontSize: 12),
        labelSmall: body.copyWith(
          color: KevernaColors.inkMute,
          letterSpacing: 1.4,
          fontWeight: FontWeight.w600,
          fontSize: 11,
        ),
      ),

      appBarTheme: AppBarTheme(
        backgroundColor: KevernaColors.card,
        foregroundColor: KevernaColors.ink,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        centerTitle: false,
        titleTextStyle: display.copyWith(
          color: KevernaColors.ink,
          fontWeight: FontWeight.w600,
          fontSize: 18,
        ),
      ),

      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: KevernaColors.accent,
          foregroundColor: KevernaColors.ink,
          textStyle: body.copyWith(fontWeight: FontWeight.w600),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 0,
        ),
      ),

      cardTheme: CardThemeData(
        color: KevernaColors.card,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: KevernaColors.lineSoft),
        ),
        margin: EdgeInsets.zero,
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: KevernaColors.cardDeep,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: KevernaColors.lineSoft),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: KevernaColors.accentDeep, width: 1.4),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      ),

      snackBarTheme: SnackBarThemeData(
        backgroundColor: KevernaColors.ink,
        contentTextStyle: body.copyWith(color: KevernaColors.card),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
    );
  }
}
