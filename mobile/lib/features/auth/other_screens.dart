import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/widgets.dart';
import 'login_flow.dart';
import 'phone_screen.dart';

class HotelPickScreen extends ConsumerStatefulWidget {
  const HotelPickScreen({super.key});

  @override
  ConsumerState<HotelPickScreen> createState() => _HotelPickScreenState();
}

class _HotelPickScreenState extends ConsumerState<HotelPickScreen> {
  int? _busyId;
  String? _error;

  Future<void> _pick(int id) async {
    setState(() {
      _busyId = id;
      _error = null;
    });
    final flow = ref.read(loginFlowProvider.notifier);
    final outcome = await flow.pickTenant(id);
    if (!mounted) return;
    setState(() => _busyId = null);
    switch (outcome) {
      case LoginOutcome.signedIn:
      case LoginOutcome.pickTenant:
        break;
      case LoginOutcome.staffOnly:
        context.go('/not-for-you');
      case LoginOutcome.failed:
        setState(() => _error = flow.lastError);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tenants = ref.watch(loginFlowProvider.select((f) => f.tenants));
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(8, 8, 8, 24),
          children: [
            Row(children: [BackCircle(onTap: () => context.pop())]),
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(S.pickHotelTitle, style: T.h1),
                  SizedBox(height: 6),
                  Text(S.pickHotelBody, style: T.sub),
                ],
              ),
            ),
            const SizedBox(height: 20),
            for (final t in tenants)
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
                child: AppCard(
                  onTap: _busyId == null ? () => _pick(t.id) : null,
                  semanticLabel: t.name,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(minHeight: 40),
                    child: Row(
                      children: [
                        const Icon(LucideIcons.building2, color: C.accent),
                        const SizedBox(width: 14),
                        Expanded(child: Text(t.name, style: T.cardTitle.copyWith(fontSize: 17))),
                        if (_busyId == t.id)
                          const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                        else
                          const Icon(LucideIcons.chevronRight, color: C.inkFaint),
                      ],
                    ),
                  ),
                ),
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text(_error!, style: const TextStyle(color: C.toCleanFg, fontWeight: FontWeight.w600)),
              ),
          ],
        ),
      ),
    );
  }
}

class NotForYouScreen extends StatelessWidget {
  const NotForYouScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 48, 24, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const BrandLogo(),
              const SizedBox(height: 28),
              const Text(S.notForYouTitle, style: T.h1),
              const SizedBox(height: 12),
              Text(S.notForYouBody, style: T.sub.copyWith(fontSize: 17)),
              const Spacer(),
              SizedBox(
                width: double.infinity,
                child: OutlinePill(label: S.backToSignIn, color: C.accentDeep, onTap: () => context.go('/signin')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: BrandLogo(size: 96)));
}
