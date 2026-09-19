import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:swaapin_mobile/core/theme.dart';

void main() {
  test('theme uses brand navy', () {
    expect(SwaapinTheme.navy, const Color(0xFF0A2540));
  });
}
