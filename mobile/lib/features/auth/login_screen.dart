// Sign-in screen. Primary entry-point when the app has no stored
// session. Email + password → LoginService → either home or the
// tenant-picker screen. A discreet "Or scan a QR from your
// dashboard" link at the bottom leads to the existing PairScreen
// for operators onboarding a shared/managed device.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/keverna_theme.dart';
import 'login_service.dart';
import 'tenant_picker_screen.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _emailCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _errorMessage;

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    final email = _emailCtrl.text.trim();
    final password = _passwordCtrl.text;
    if (email.isEmpty || password.isEmpty) {
      setState(() => _errorMessage = 'Enter your email and password.');
      return;
    }
    setState(() {
      _busy = true;
      _errorMessage = null;
    });

    try {
      final outcome = await ref.read(loginServiceProvider).signIn(
            email: email,
            password: password,
          );
      switch (outcome) {
        case LoginCompleted _:
          // Router redirect will take us to /home the moment the
          // session state fires.
          break;
        case LoginNeedsTenantPick(:final tenants, :final email, :final password):
          if (!mounted) return;
          await Navigator.of(context).push<void>(
            MaterialPageRoute(
              builder: (_) => TenantPickerScreen(
                tenants: tenants,
                email: email,
                password: password,
              ),
            ),
          );
      }
    } on LoginFailure catch (e) {
      setState(() => _errorMessage = e.message);
    } catch (e) {
      setState(() => _errorMessage = 'Something went wrong. $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 40),
                  _brandMark(),
                  const SizedBox(height: 32),
                  Text('Sign in', style: Theme.of(context).textTheme.displaySmall),
                  const SizedBox(height: 6),
                  Text(
                    'Use the email and password you use on the web dashboard.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 24),

                  TextField(
                    controller: _emailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    enableSuggestions: false,
                    textInputAction: TextInputAction.next,
                    decoration: const InputDecoration(
                      labelText: 'Email',
                      prefixIcon: Icon(Icons.mail_outline, size: 20),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _passwordCtrl,
                    obscureText: _obscure,
                    textInputAction: TextInputAction.done,
                    onSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      labelText: 'Password',
                      prefixIcon: const Icon(Icons.lock_outline, size: 20),
                      suffixIcon: IconButton(
                        icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                            size: 20),
                        onPressed: () => setState(() => _obscure = !_obscure),
                      ),
                    ),
                  ),

                  if (_errorMessage != null) ...[
                    const SizedBox(height: 14),
                    Text(
                      _errorMessage!,
                      style: Theme.of(context)
                          .textTheme
                          .bodyMedium
                          ?.copyWith(color: KevernaColors.danger),
                    ),
                  ],

                  const SizedBox(height: 22),
                  ElevatedButton(
                    onPressed: _busy ? null : _submit,
                    child: _busy
                        ? const SizedBox(
                            width: 20, height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2, color: KevernaColors.ink,
                            ),
                          )
                        : const Text('Sign in'),
                  ),

                  const SizedBox(height: 28),
                  Row(
                    children: const [
                      Expanded(child: Divider()),
                      Padding(
                        padding: EdgeInsets.symmetric(horizontal: 12),
                        child: Text('OR', style: TextStyle(
                          color: KevernaColors.inkMute,
                          fontSize: 11, fontWeight: FontWeight.w600, letterSpacing: 1.2,
                        )),
                      ),
                      Expanded(child: Divider()),
                    ],
                  ),
                  const SizedBox(height: 16),
                  OutlinedButton.icon(
                    onPressed: _busy ? null : () => context.push('/pair'),
                    icon: const Icon(Icons.qr_code_scanner, size: 18),
                    label: const Text('Scan a QR from your dashboard'),
                  ),

                  const SizedBox(height: 24),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _brandMark() {
    return Center(
      child: Container(
        width: 88, height: 88,
        decoration: BoxDecoration(
          color: KevernaColors.accent,
          borderRadius: BorderRadius.circular(22),
        ),
        alignment: Alignment.center,
        child: Text(
          'K',
          style: TextStyle(
            fontSize: 56,
            fontWeight: FontWeight.w700,
            color: KevernaColors.ink,
            fontFamily: Theme.of(context).textTheme.displayLarge?.fontFamily,
            height: 1,
          ),
        ),
      ),
    );
  }
}
