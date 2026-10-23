// Greeting block shared by My rooms and To check: date + shift eyebrow,
// "Good morning, {first_name}" (device clock) and a summary line, then the
// "Today" progress card.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/widgets.dart';
import '../staff/providers.dart';

class HomeHeader extends ConsumerWidget {
  const HomeHeader({super.key, required this.summary});

  final String summary;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final info = ref.watch(staffSessionProvider).valueOrNull;
    final session = ref.watch(sessionProvider.select((s) => s.session));
    final first = info?.operator.firstName ?? session?.operator.firstName ?? '';
    final eyebrow = info == null ? '' : S.eyebrow(info.dateLabel, info.shiftLabel);
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(height: 16, child: eyebrow.isEmpty ? null : Eyebrow(eyebrow)),
          const SizedBox(height: 4),
          Semantics(header: true, child: Text(S.greeting(DateTime.now().hour, first), style: T.h1)),
          const SizedBox(height: 4),
          Text(summary, style: T.sub),
        ],
      ),
    );
  }
}

class TodayCard extends StatelessWidget {
  const TodayCard({super.key, required this.done, required this.total, required this.ofText});

  final int done;
  final int total;
  final String ofText;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
      child: AppCard(
        child: Column(
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                const Text(S.today, style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w600)),
                const SizedBox(width: 12),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '$done ',
                          style: const TextStyle(
                            fontFamily: F.display,
                            fontSize: 24,
                            fontWeight: FontWeight.w700,
                            color: C.ink,
                          ),
                        ),
                        TextSpan(text: ofText, style: T.sub),
                      ],
                    ),
                    textAlign: TextAlign.right,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            ProgressBar(value: total == 0 ? 0 : done / total),
          ],
        ),
      ),
    );
  }
}
