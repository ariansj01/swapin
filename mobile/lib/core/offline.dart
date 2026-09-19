import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

class OfflineController extends ChangeNotifier {
  bool online = true;
  StreamSubscription<List<ConnectivityResult>>? _sub;

  Future<void> start() async {
    final current = await Connectivity().checkConnectivity();
    _set(current);
    _sub = Connectivity().onConnectivityChanged.listen(_set);
  }

  void _set(List<ConnectivityResult> results) {
    final next = results.any((r) => r != ConnectivityResult.none);
    if (next != online) {
      online = next;
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }
}
