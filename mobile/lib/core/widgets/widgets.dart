// Shared building blocks, sized after the A mockups.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../features/staff/providers.dart';
import '../strings.dart';
import '../theme/tokens.dart';

class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.radius = 24,
    this.color = C.card,
    this.onTap,
    this.border = true,
    this.semanticLabel,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final double radius;
  final Color color;
  final VoidCallback? onTap;
  final bool border;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final shape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(radius),
      side: border ? const BorderSide(color: C.lineSoft) : BorderSide.none,
    );
    final content = Padding(padding: padding, child: child);
    return Semantics(
      button: onTap != null,
      label: semanticLabel,
      child: Material(
        color: color,
        shape: shape,
        clipBehavior: Clip.antiAlias,
        child: onTap == null ? content : InkWell(onTap: onTap, child: content),
      ),
    );
  }
}

class Eyebrow extends StatelessWidget {
  const Eyebrow(this.text, {super.key, this.color = C.inkMute});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Text(text.toUpperCase(), style: T.eyebrow.copyWith(color: color));
}

class StateChip extends StatelessWidget {
  const StateChip(this.label, {super.key, required this.bg, required this.fg});

  final String label;
  final Color bg;
  final Color fg;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(99)),
      child: Text(label, style: T.chip.copyWith(color: fg), maxLines: 1, softWrap: false),
    );
  }
}

class RoomBadge extends StatelessWidget {
  const RoomBadge(this.number, {super.key, required this.bg, required this.fg});

  final String number;
  final Color bg;
  final Color fg;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 64,
      height: 64,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: FittedBox(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6),
          child: Text(
            number,
            style: TextStyle(fontFamily: F.display, fontSize: 24, fontWeight: FontWeight.w700, color: fg, height: 1),
          ),
        ),
      ),
    );
  }
}

class ProgressBar extends StatelessWidget {
  const ProgressBar({super.key, required this.value, this.height = 12});

  final double value;
  final double height;

  @override
  Widget build(BuildContext context) {
    final v = value.isNaN ? 0.0 : value.clamp(0.0, 1.0);
    return ClipRRect(
      borderRadius: BorderRadius.circular(99),
      child: SizedBox(
        height: height,
        width: double.infinity,
        child: Stack(
          children: [
            const Positioned.fill(child: ColoredBox(color: C.track)),
            FractionallySizedBox(
              widthFactor: v,
              child: Container(
                decoration: BoxDecoration(color: C.accent, borderRadius: BorderRadius.circular(99)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The main action: ink-deep pill, label left, round blue icon right.
class ActionPill extends StatelessWidget {
  const ActionPill({
    super.key,
    required this.label,
    required this.icon,
    required this.onTap,
    this.busy = false,
    this.enabled = true,
  });

  final String label;
  final IconData icon;
  final VoidCallback? onTap;
  final bool busy;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final active = enabled && !busy && onTap != null;
    return Semantics(
      button: true,
      enabled: active,
      label: label,
      excludeSemantics: true,
      child: Opacity(
        opacity: enabled ? 1 : 0.55,
        child: Material(
          color: C.inkDeep,
          shape: const StadiumBorder(),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: active ? onTap : null,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 64),
              child: Padding(
                padding: const EdgeInsets.only(left: 28, right: 8, top: 8, bottom: 8),
                child: Row(
                  children: [
                    Expanded(child: Text(label, style: T.action, maxLines: 2)),
                    const SizedBox(width: 12),
                    Container(
                      width: 48,
                      height: 48,
                      decoration: const BoxDecoration(color: C.accent, shape: BoxShape.circle),
                      alignment: Alignment.center,
                      child: busy
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                            )
                          : Icon(icon, size: 22, color: Colors.white),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class OutlinePill extends StatelessWidget {
  const OutlinePill({super.key, required this.label, required this.onTap, this.color = C.toCleanFg, this.icon});

  final String label;
  final VoidCallback? onTap;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: C.card,
      shape: const StadiumBorder(side: BorderSide(color: Color(0x261A2430))),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 64),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (icon != null) ...[Icon(icon, size: 20, color: color), const SizedBox(width: 8)],
                Flexible(
                  child: Text(
                    label,
                    style: TextStyle(fontFamily: F.body, fontSize: 16, fontWeight: FontWeight.w600, color: color),
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// 44px white circle with a back arrow, inside a 56px tap target.
class BackCircle extends StatelessWidget {
  const BackCircle({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: S.back,
      excludeSemantics: true,
      child: InkResponse(
        onTap: onTap,
        radius: 28,
        child: const SizedBox(
          width: 56,
          height: 56,
          child: Center(
            child: DecoratedBox(
              decoration: BoxDecoration(color: C.card, shape: BoxShape.circle),
              child: SizedBox(width: 44, height: 44, child: Icon(LucideIcons.arrowLeft, size: 20, color: C.ink)),
            ),
          ),
        ),
      ),
    );
  }
}

class InitialsAvatar extends StatelessWidget {
  const InitialsAvatar(this.initials, {super.key, this.size = 48, this.bg = C.cardDeep, this.fg = C.ink});

  final String initials;
  final double size;
  final Color bg;
  final Color fg;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
      child: Text(
        initials,
        style: TextStyle(fontFamily: F.body, fontWeight: FontWeight.w700, fontSize: size * 0.31, color: fg),
      ),
    );
  }
}

/// Slim banner while offline or while changes wait to send.
class OfflineBanner extends ConsumerWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final online = ref.watch(connectivityProvider).valueOrNull ?? true;
    final pending = ref.watch(outboxCountProvider).valueOrNull ?? 0;
    if (online && pending == 0) return const SizedBox.shrink();
    final offline = !online;
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        margin: const EdgeInsets.fromLTRB(16, 4, 16, 4),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: offline ? C.warnBg : C.accentSoftSolid,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Row(
          children: [
            Icon(
              offline ? LucideIcons.wifiOff : LucideIcons.refreshCw,
              size: 18,
              color: offline ? C.warnFg : C.accentDeep,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                offline ? S.offline : S.sending,
                style: TextStyle(
                  fontFamily: F.body,
                  fontSize: 13.5,
                  fontWeight: FontWeight.w600,
                  color: offline ? C.warnFg : C.accentDeep,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.text, this.icon = LucideIcons.sparkles});

  final String text;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(32, 48, 32, 32),
      child: Column(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: const BoxDecoration(color: C.card, shape: BoxShape.circle),
            child: Icon(icon, color: C.accent, size: 28),
          ),
          const SizedBox(height: 16),
          Text(text, textAlign: TextAlign.center, style: T.sub.copyWith(fontSize: 16)),
        ],
      ),
    );
  }
}

class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.onRetry, this.text = S.loadFailed});

  final VoidCallback onRetry;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(32, 48, 32, 32),
      child: Column(
        children: [
          const Icon(LucideIcons.cloudOff, color: C.inkMute, size: 32),
          const SizedBox(height: 12),
          Text(text, textAlign: TextAlign.center, style: T.sub.copyWith(fontSize: 16)),
          const SizedBox(height: 16),
          OutlinePill(label: S.tryAgain, onTap: onRetry, color: C.accentDeep),
        ],
      ),
    );
  }
}

class LoadingBlock extends StatelessWidget {
  const LoadingBlock({super.key});

  @override
  Widget build(BuildContext context) => const Padding(
        padding: EdgeInsets.all(48),
        child: Center(child: CircularProgressIndicator()),
      );
}

/// Pinned bottom area with a fade from the paper colour (mockup style).
class BottomActionArea extends StatelessWidget {
  const BottomActionArea({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0x00EEF3F7), C.paper, C.paper],
          stops: [0, 0.35, 1],
        ),
      ),
      padding: const EdgeInsets.fromLTRB(16, 24, 16, 12),
      child: SafeArea(top: false, child: child),
    );
  }
}

void showSnack(BuildContext context, String text) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(text)));
}
