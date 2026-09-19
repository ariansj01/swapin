import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/offline.dart';

class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final offline = context.watch<OfflineController>();
    if (offline.online) return const SizedBox.shrink();
    return Material(
      color: const Color(0xFFB45309),
      child: const SafeArea(
        bottom: false,
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Text(
            'اتصال اینترنت قطع است',
            textAlign: TextAlign.center,
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
          ),
        ),
      ),
    );
  }
}
