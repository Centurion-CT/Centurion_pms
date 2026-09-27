// Second step of email + password sign-in when the operator belongs
// to more than one tenant. Shown after the server returns 300 with
// a tenants list; operator taps the property they want, we re-POST
// /login with tenant_id + the same credentials.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/theme/keverna_theme.dart';
import 'login_service.dart';

class TenantPickerScreen extends ConsumerStatefulWidget {
  const TenantPickerScreen({
    super.key,
    required this.tenants,
    required this.email,
    required this.password,
  });

  final List<LoginTenantOption> tenants;
  final String email;
  final String password;

  @override
  ConsumerState<TenantPickerScreen> createState() => _TenantPickerScreenState();
}

class _TenantPickerScreenState extends ConsumerState<TenantPickerScreen> {
  int? _pickingId;
  String? _errorMessage;

  Future<void> _pick(LoginTenantOption tenant) async {
    if (_pickingId != null) return;
    setState(() {
      _pickingId = tenant.id;
      _errorMessage = null;
    });
    try {
      final outcome = await ref.read(loginServiceProvider).signIn(
            email: widget.email,
            password: widget.password,
            tenantId: tenant.id,
          );
      if (!mounted) return;
      if (outcome is LoginCompleted) {
        // Session is stored — router redirect takes us to /home.
        // Pop back out of the picker in case the redirect hasn't
        // fired yet (it shouldn't matter — the picker's parent
        // route is /login which the redirect will replace).
        Navigator.of(context).pop();
      }
    } on LoginFailure catch (e) {
      setState(() => _errorMessage = e.message);
    } catch (e) {
      setState(() => _errorMessage = 'Sign-in failed. $e');
    } finally {
      if (mounted) setState(() => _pickingId = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pick a property')),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Text(
                  'Your account has access to more than one property.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 4),
                Text('Tap the one you\'re signing into.',
                    style: Theme.of(context).textTheme.bodyMedium),
                const SizedBox(height: 20),

                if (_errorMessage != null) ...[
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(
                      _errorMessage!,
                      style: Theme.of(context)
                          .textTheme
                          .bodyMedium
                          ?.copyWith(color: KevernaColors.danger),
                    ),
                  ),
                ],

                for (final tenant in widget.tenants)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _tenantCard(tenant),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _tenantCard(LoginTenantOption tenant) {
    final busy = _pickingId == tenant.id;
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: _pickingId == null ? () => _pick(tenant) : null,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              CircleAvatar(
                radius: 22,
                backgroundColor: KevernaColors.accent,
                child: Text(
                  tenant.name.isNotEmpty ? tenant.name[0].toUpperCase() : 'K',
                  style: const TextStyle(
                    color: KevernaColors.ink,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tenant.name.isEmpty ? tenant.slug : tenant.name,
                        style: Theme.of(context).textTheme.titleMedium,
                        maxLines: 1, overflow: TextOverflow.ellipsis),
                    Text(
                      [
                        if (tenant.role != null) tenant.role!.replaceAll('_', ' '),
                        if (tenant.lastLoginAt != null)
                          'last ${DateFormat('d MMM').format(tenant.lastLoginAt!)}',
                      ].where((s) => s.isNotEmpty).join(' · '),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              busy
                  ? const SizedBox(
                      width: 20, height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2, color: KevernaColors.accentDeep,
                      ),
                    )
                  : const Icon(Icons.arrow_forward_ios,
                      size: 14, color: KevernaColors.inkMute),
            ],
          ),
        ),
      ),
    );
  }
}
