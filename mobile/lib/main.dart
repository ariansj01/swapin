import 'package:flutter/material.dart';

void main() {
  runApp(const SwaapinPlaceholder());
}

class SwaapinPlaceholder extends StatelessWidget {
  const SwaapinPlaceholder({super.key});

  @override
  Widget build(BuildContext context) {
    return const MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        backgroundColor: Colors.white,
      ),
    );
  }
}
