import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:share_plus/share_plus.dart';

/// Native replacements for PWA APIs (install, SW cache UX, share, geo, picker, alerts).
class PwaNative {
  PwaNative._();

  static final notifications = FlutterLocalNotificationsPlugin();
  static final _picker = ImagePicker();

  static Future<void> init() async {
    SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
      statusBarColor: Color(0xFF0A2540),
      statusBarIconBrightness: Brightness.light,
    ));
    const android = AndroidInitializationSettings('@mipmap/ic_launcher');
    await notifications.initialize(const InitializationSettings(android: android));
  }

  static Future<bool> requestNotifications() async {
    final status = await Permission.notification.request();
    return status.isGranted;
  }

  static Future<void> showAlert({
    required int id,
    required String title,
    required String body,
  }) async {
    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'swaapin_alerts',
        'اعلان‌های سواَپین',
        importance: Importance.high,
        priority: Priority.high,
      ),
    );
    await notifications.show(id, title, body, details);
  }

  static Future<Position?> currentPosition() async {
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return null;
    }
    if (!await Geolocator.isLocationServiceEnabled()) return null;
    return Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.medium),
    );
  }

  static Future<void> shareListing({required String title, required String url}) {
    return SharePlus.instance.share(ShareParams(text: '$title\n$url', subject: title));
  }

  static Future<List<XFile>> pickImages({int max = 8}) async {
    final files = await _picker.pickMultiImage(imageQuality: 85);
    if (files.length > max) return files.take(max).toList();
    return files;
  }

  static Future<XFile?> capturePhoto() {
    return _picker.pickImage(source: ImageSource.camera, imageQuality: 85);
  }
}
