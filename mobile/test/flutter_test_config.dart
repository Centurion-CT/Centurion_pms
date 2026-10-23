// Load the real bundled fonts so layout (and the 1.3x overflow checks)
// match the device instead of the square test font.

import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';

Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  Future<void> load(String family, List<String> files) async {
    final loader = FontLoader(family);
    for (final f in files) {
      final bytes = File('assets/fonts/$f').readAsBytesSync();
      loader.addFont(Future.value(ByteData.view(Uint8List.fromList(bytes).buffer)));
    }
    await loader.load();
  }

  await load('Hanken', [
    'HankenGrotesk-Regular.ttf',
    'HankenGrotesk-Medium.ttf',
    'HankenGrotesk-SemiBold.ttf',
    'HankenGrotesk-Bold.ttf',
  ]);
  await load('Bricolage', ['BricolageGrotesque-SemiBold.ttf', 'BricolageGrotesque-Bold.ttf']);
  await testMain();
}
