import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_client.dart';
import '../models/user.dart';

class AuthStore extends ChangeNotifier {
  AuthStore({ApiClient? client}) : _client = client ?? ApiClient();

  final ApiClient _client;
  static const _tokenKey = 'auth_token';

  UserProfile? user;
  bool ready = false;

  ApiClient get api => _client;
  bool get isLoggedIn => _client.token != null && _client.token!.isNotEmpty;

  Future<void> restore() async {
    final prefs = await SharedPreferences.getInstance();
    _client.token = prefs.getString(_tokenKey);
    if (isLoggedIn) {
      try {
        final data = await _client.get('me');
        user = UserProfile.fromJson(data['user'] as Map<String, dynamic>);
      } catch (_) {
        await logout();
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<Map<String, dynamic>> sendOtp(String phone) {
    return _client.post('auth/otp/send', body: {'phone': phone});
  }

  Future<void> verifyOtp(String phone, String code) async {
    final data = await _client.post('auth/otp/verify', body: {
      'phone': phone,
      'code': code,
      'device': 'android',
    });
    final token = data['token'] as String;
    _client.token = token;
    user = UserProfile.fromJson(data['user'] as Map<String, dynamic>);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
    notifyListeners();
  }

  Future<void> updateProfile({required String name, required String city}) async {
    final data = await _client.post('me', body: {'name': name, 'city': city});
    user = UserProfile.fromJson(data['user'] as Map<String, dynamic>);
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      if (isLoggedIn) await _client.post('auth/logout');
    } catch (_) {}
    _client.token = null;
    user = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    notifyListeners();
  }
}
