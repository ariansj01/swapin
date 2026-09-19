import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'config.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.status = 0, this.code});
  final String message;
  final int status;
  final String? code;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({this.token});

  String? token;

  static const _prefsApi = 'api_base_url';

  static Future<String> resolveBase() async {
    if (AppConfig.apiBaseFromEnv.isNotEmpty) return AppConfig.apiBaseFromEnv.replaceAll(RegExp(r'/$'), '');
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_prefsApi);
    if (saved != null && saved.isNotEmpty) return saved.replaceAll(RegExp(r'/$'), '');
    return AppConfig.productionApi;
  }

  static Future<void> saveBase(String url) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefsApi, url.replaceAll(RegExp(r'/$'), ''));
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) async {
    final uri = await _uri(path, query);
    final res = await http.get(uri, headers: await _headers());
    return _decode(res);
  }

  Future<Map<String, dynamic>> post(String path, {Map<String, dynamic>? body}) async {
    final uri = await _uri(path);
    final res = await http.post(
      uri,
      headers: await _headers(json: true),
      body: jsonEncode(body ?? {}),
    );
    return _decode(res);
  }

  Future<Map<String, dynamic>> postMultipart(
    String path, {
    required Map<String, String> fields,
    required List<http.MultipartFile> files,
  }) async {
    final uri = await _uri(path);
    final req = http.MultipartRequest('POST', uri);
    req.headers.addAll(await _headers());
    req.fields.addAll(fields);
    req.files.addAll(files);
    final streamed = await req.send();
    final res = await http.Response.fromStream(streamed);
    return _decode(res);
  }

  Future<Uri> _uri(String path, [Map<String, String>? query]) async {
    final base = await resolveBase();
    final cleaned = path.startsWith('/') ? path.substring(1) : path;
    return Uri.parse('$base/$cleaned').replace(queryParameters: query);
  }

  Future<Map<String, String>> _headers({bool json = false}) async {
    final headers = <String, String>{
      'Accept': 'application/json',
    };
    if (json) headers['Content-Type'] = 'application/json; charset=utf-8';
    if (token != null && token!.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    return headers;
  }

  Map<String, dynamic> _decode(http.Response res) {
    Map<String, dynamic> data;
    try {
      final decoded = jsonDecode(res.body);
      data = decoded is Map<String, dynamic> ? decoded : {'ok': false, 'data': decoded};
    } catch (_) {
      throw ApiException('پاسخ سرور نامعتبر است', status: res.statusCode);
    }
    if (res.statusCode >= 400 || data['ok'] == false) {
      throw ApiException(
        (data['message'] as String?) ?? 'خطای ارتباط با سرور',
        status: res.statusCode,
        code: data['error'] as String?,
      );
    }
    return data;
  }
}
