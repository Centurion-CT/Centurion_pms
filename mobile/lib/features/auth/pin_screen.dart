// PIN keypad. PINs are 4 to 6 digits, so there is no auto submit at 4:
// the "Sign in" key submits from 4 digits and a 6th digit submits on its own.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/widgets.dart';
import 'login_flow.dart';

const kPinMin = 4;
const kPinMax = 6;

class PinScreen extends ConsumerStatefulWidget {
  const PinScreen({super.key});

  @override
  ConsumerState<PinScreen> createState() => _PinScreenState();
}

class _PinScreenState extends ConsumerState<PinScreen> {
  String _pin = '';
  bool _busy = false;
  String? _error;

  void _digit(String d) {
    if (_busy || _pin.length >= kPinMax) return;
    HapticFeedback.selectionClick();
    setState(() {
      _pin += d;
      _error = null;
    });
    if (_pin.length == kPinMax) _submit();
  }

  void _backspace() {
    if (_busy || _pin.isEmpty) return;
    setState(() => _pin = _pin.substring(0, _pin.length - 1));
  }

  Future<void> _submit() async {
    if (_busy || _pin.length < kPinMin) return;
    setState(() => _busy = true);
    final flow = ref.read(loginFlowProvider.notifier);
    final outcome = await flow.submit(_pin);
    if (!mounted) return;
    switch (outcome) {
      case LoginOutcome.signedIn:
        // The router moves on once the session lands.
        break;
      case LoginOutcome.pickTenant:
        setState(() {
          _busy = false;
          _pin = '';
        });
        context.push('/signin/hotel');
      case LoginOutcome.staffOnly:
        setState(() {
          _busy = false;
          _pin = '';
        });
        context.go('/not-for-you');
      case LoginOutcome.failed:
        HapticFeedback.heavyImpact();
        setState(() {
          _busy = false;
          _pin = '';
          _error = flow.lastError ?? S.somethingWrong;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    final phone = ref.watch(loginFlowProvider.select((f) => f.phone));
    final slots = _pin.length < kPinMin ? kPinMin : _pin.length;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
              child: Row(children: [BackCircle(onTap: () => context.pop())]),
            ),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 24),
                child: Column(
                  children: [
                    const SizedBox(height: 8),
                    const Text(S.pinTitle, style: T.h1, textAlign: TextAlign.center),
                    const SizedBox(height: 6),
                    Text(S.pinFor(phone), style: T.sub, textAlign: TextAlign.center),
                    const SizedBox(height: 28),
                    Semantics(
                      label: '${_pin.length} of $slots digits entered',
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          for (var i = 0; i < slots; i++)
                            AnimatedContainer(
                              duration: const Duration(milliseconds: 120),
                              margin: const EdgeInsets.symmetric(horizontal: 8),
                              width: 18,
                              height: 18,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: i < _pin.length ? C.inkDeep : Colors.transparent,
                                border: Border.all(color: i < _pin.length ? C.inkDeep : C.tickRing, width: 2),
                              ),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    SizedBox(
                      height: 44,
                      child: _error == null
                          ? null
                          : Text(
                              _error!,
                              key: const Key('pin-error'),
                              textAlign: TextAlign.center,
                              style: const TextStyle(color: C.toCleanFg, fontSize: 15, fontWeight: FontWeight.w600),
                            ),
                    ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 16),
              child: _Keypad(
                onDigit: _digit,
                onBackspace: _backspace,
                onSubmit: _pin.length >= kPinMin ? _submit : null,
                busy: _busy,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Keypad extends StatelessWidget {
  const _Keypad({required this.onDigit, required this.onBackspace, required this.onSubmit, required this.busy});

  final void Function(String) onDigit;
  final VoidCallback onBackspace;
  final VoidCallback? onSubmit;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    Widget row(List<Widget> keys) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(children: [for (final k in keys) Expanded(child: k)]),
        );
    Widget digit(String d) => _Key(
          key: Key('key-$d'),
          label: d,
          semantics: d,
          onTap: () => onDigit(d),
          child: Text(d, style: const TextStyle(fontFamily: F.display, fontSize: 30, fontWeight: FontWeight.w600)),
        );
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        row([digit('1'), digit('2'), digit('3')]),
        row([digit('4'), digit('5'), digit('6')]),
        row([digit('7'), digit('8'), digit('9')]),
        row([
          _Key(
            key: const Key('key-back'),
            label: '',
            semantics: S.deleteDigit,
            plain: true,
            onTap: onBackspace,
            child: const Icon(LucideIcons.delete, size: 26, color: C.inkSoft),
          ),
          digit('0'),
          _Key(
            key: const Key('key-submit'),
            label: S.signIn,
            semantics: S.signIn,
            dark: true,
            onTap: onSubmit,
            child: busy
                ? const SizedBox(
                    width: 24,
                    height: 24,
                    child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                  )
                : const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Flexible(
                        child: Text(
                          S.signIn,
                          maxLines: 1,
                          overflow: TextOverflow.fade,
                          softWrap: false,
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: Colors.white),
                        ),
                      ),
                    ],
                  ),
          ),
        ]),
      ],
    );
  }
}

class _Key extends StatelessWidget {
  const _Key({
    super.key,
    required this.label,
    required this.semantics,
    required this.onTap,
    required this.child,
    this.dark = false,
    this.plain = false,
  });

  final String label;
  final String semantics;
  final VoidCallback? onTap;
  final Widget child;
  final bool dark;
  final bool plain;

  @override
  Widget build(BuildContext context) {
    final bg = dark ? C.inkDeep : (plain ? Colors.transparent : C.card);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6),
      child: Semantics(
        button: true,
        label: semantics,
        enabled: onTap != null,
        excludeSemantics: true,
        child: Opacity(
          opacity: onTap == null && dark ? 0.4 : 1,
          child: Material(
            color: bg,
            borderRadius: BorderRadius.circular(22),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: onTap,
              child: SizedBox(height: 68, child: Center(child: child)),
            ),
          ),
        ),
      ),
    );
  }
}
