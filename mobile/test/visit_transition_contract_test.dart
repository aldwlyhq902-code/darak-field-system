import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:darak_field/core/visit_progress.dart';

void main() {
  test('mobile transitions match the shared server contract', () async {
    final file = File(
      '${Directory.current.parent.path}${Platform.pathSeparator}contracts${Platform.pathSeparator}visit_transitions.json',
    );
    final decoded =
        jsonDecode(await file.readAsString()) as Map<String, dynamic>;
    final contract = decoded.map(
      (key, value) => MapEntry(key, List<String>.from(value as List)),
    );

    expect(VisitProgress.transitions, contract);
  });
}
