import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/auth/session.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/widgets.dart';
import 'login_flow.dart';

class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.size = 72, this.onLongPress});

  final double size;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: S.hotelName,
      image: true,
      child: GestureDetector(
        onLongPress: onLongPress,
        child: Container(
          width: size,
          height: size,
          padding: EdgeInsets.all(size * 0.14),
          decoration: BoxDecoration(color: C.card, borderRadius: BorderRadius.circular(size * 0.3)),
          child: Image.asset('assets/branding/logo.png', fit: BoxFit.contain),
        ),
      ),
    );
  }
}

class FieldLabel extends StatelessWidget {
  const FieldLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 8),
        child: Text(text, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: C.inkSoft)),
      );
}

class PhoneScreen extends ConsumerStatefulWidget {
  const PhoneScreen({super.key});

  @override
  ConsumerState<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends ConsumerState<PhoneScreen> {
  final _phone = TextEditingController();
  final _host = TextEditingController();
  bool _showHost = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final flow = ref.read(loginFlowProvider);
    _phone.text = flow.phone;
    _host.text = flow.host;
  }

  @override
  void dispose() {
    _phone.dispose();
    _host.dispose();
    super.dispose();
  }

  Future<void> _next() async {
    final flow = ref.read(loginFlowProvider.notifier);
    if (_showHost || flow.needsHost) {
      if (_host.text.trim().isEmpty) {
        setState(() => _error = S.hotelAddressMissing);
        return;
      }
      await flow.setHost(_host.text);
    }
    final digits = _phone.text.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 7) {
      setState(() => _error = S.phoneTooShort);
      return;
    }
    setState(() => _error = null);
    flow.setPhone(_phone.text);
    if (mounted) context.push('/signin/pin');
  }

  @override
  Widget build(BuildContext context) {
    final flow = ref.watch(loginFlowProvider);
    // Host loads from secure storage; fill it in once it arrives.
    ref.listen(loginFlowProvider.select((f) => f.host), (_, h) {
      if (_host.text.isEmpty) _host.text = h;
    });
    final needsHost = flow.hostLoaded && ref.read(loginFlowProvider.notifier).needsHost;
    final showHost = _showHost || needsHost;
    final kickedOut = ref.watch(sessionProvider.select((s) => s.kickedOut));

    return Scaffold(
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, box) => SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 32, 24, 16),
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: box.maxHeight - 48),
              child: IntrinsicHeight(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    BrandLogo(onLongPress: () => setState(() => _showHost = !_showHost)),
                    const SizedBox(height: 28),
                    const Eyebrow(S.hotelName),
                    const SizedBox(height: 6),
                    const Text(S.signInTitle, style: T.h1),
                    const SizedBox(height: 6),
                    const Text(S.signInBody, style: T.sub),
                    if (kickedOut) ...[
                      const SizedBox(height: 16),
                      const _Note(S.signedOutByServer),
                    ],
                    const SizedBox(height: 28),
                    if (showHost) ...[
                      const FieldLabel(S.hotelAddress),
                      TextField(
                        key: const Key('host-field'),
                        controller: _host,
                        keyboardType: TextInputType.url,
                        autocorrect: false,
                        style: const TextStyle(fontSize: 17),
                        decoration: const InputDecoration(hintText: S.hotelAddressHint),
                      ),
                      const SizedBox(height: 20),
                    ],
                    const FieldLabel(S.phoneLabel),
                    TextField(
                      key: const Key('phone-field'),
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      autofillHints: const [AutofillHints.telephoneNumber],
                      inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ \-]'))],
                      textInputAction: TextInputAction.next,
                      onSubmitted: (_) => _next(),
                      style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w600, letterSpacing: 0.5),
                      decoration: const InputDecoration(
                        hintText: S.phoneHint,
                        prefixIcon: Icon(LucideIcons.phone, color: C.inkMute),
                      ),
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(_error!,
                          style: const TextStyle(color: C.toCleanFg, fontSize: 15, fontWeight: FontWeight.w600),),
                    ],
                    const Spacer(),
                    const SizedBox(height: 24),
                    ActionPill(label: S.next, icon: LucideIcons.arrowRight, onTap: _next),
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

class _Note extends StatelessWidget {
  const _Note(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: C.warnBg, borderRadius: BorderRadius.circular(16)),
        child: Text(text, style: const TextStyle(color: C.warnFg, fontWeight: FontWeight.w600)),
      );
}
