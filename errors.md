PS C:\xampp\htdocs\swaapin\mobile\android> .\gradlew.bat :app:assembleDebug

> Task :app:compileDebugJavaWithJavac FAILED
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:5: error: cannot find symbol
import io.flutter.Log;
                 ^
  symbol:   class Log
  location: package io.flutter
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:7: error: package io.flutter.embedding.engine does not exist
import io.flutter.embedding.engine.FlutterEngine;
                                  ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:17: error: cannot find symbol
  public static void registerWith(@NonNull FlutterEngine flutterEngine) {
                                           ^
  symbol:   class FlutterEngine
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:19: error: package dev.fluttercommunity.plus.connectivity does not exist
      flutterEngine.getPlugins().add(new dev.fluttercommunity.plus.connectivity.ConnectivityPlugin());
                                                                               ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:21: error: cannot find symbol
      Log.e(TAG, "Error registering plugin connectivity_plus, dev.fluttercommunity.plus.connectivity.ConnectivityPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:24: error: package com.dexterous.flutterlocalnotifications does not exist
      flutterEngine.getPlugins().add(new com.dexterous.flutterlocalnotifications.FlutterLocalNotificationsPlugin());
                                                                                ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:26: error: cannot find symbol
      Log.e(TAG, "Error registering plugin flutter_local_notifications, com.dexterous.flutterlocalnotifications.FlutterLocalNotificationsPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:29: error: package io.flutter.plugins.flutter_plugin_android_lifecycle does not exist
      flutterEngine.getPlugins().add(new io.flutter.plugins.flutter_plugin_android_lifecycle.FlutterAndroidLifecyclePlugin());
                                                                                            ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:31: error: cannot find symbol
      Log.e(TAG, "Error registering plugin flutter_plugin_android_lifecycle, io.flutter.plugins.flutter_plugin_android_lifecycle.FlutterAndroidLifecyclePlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:34: error: package com.baseflow.geolocator does not exist
      flutterEngine.getPlugins().add(new com.baseflow.geolocator.GeolocatorPlugin());
                                                                ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:36: error: cannot find symbol
      Log.e(TAG, "Error registering plugin geolocator_android, com.baseflow.geolocator.GeolocatorPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:39: error: package io.flutter.plugins.imagepicker does not exist
      flutterEngine.getPlugins().add(new io.flutter.plugins.imagepicker.ImagePickerPlugin());
                                                                       ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:41: error: cannot find symbol
      Log.e(TAG, "Error registering plugin image_picker_android, io.flutter.plugins.imagepicker.ImagePickerPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:44: error: package com.github.dart_lang.jni does not exist
      flutterEngine.getPlugins().add(new com.github.dart_lang.jni.JniPlugin());
                                                                 ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:46: error: cannot find symbol
      Log.e(TAG, "Error registering plugin jni, com.github.dart_lang.jni.JniPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:49: error: package com.github.dart_lang.jni_flutter does not exist
      flutterEngine.getPlugins().add(new com.github.dart_lang.jni_flutter.JniFlutterPlugin());
                                                                         ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:51: error: cannot find symbol
      Log.e(TAG, "Error registering plugin jni_flutter, com.github.dart_lang.jni_flutter.JniFlutterPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:54: error: package dev.fluttercommunity.plus.packageinfo does not exist
      flutterEngine.getPlugins().add(new dev.fluttercommunity.plus.packageinfo.PackageInfoPlugin());
                                                                              ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:56: error: cannot find symbol
      Log.e(TAG, "Error registering plugin package_info_plus, dev.fluttercommunity.plus.packageinfo.PackageInfoPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:59: error: package com.baseflow.permissionhandler does not exist
      flutterEngine.getPlugins().add(new com.baseflow.permissionhandler.PermissionHandlerPlugin());
                                                                       ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:61: error: cannot find symbol
      Log.e(TAG, "Error registering plugin permission_handler_android, com.baseflow.permissionhandler.PermissionHandlerPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:64: error: package dev.fluttercommunity.plus.share does not exist
      flutterEngine.getPlugins().add(new dev.fluttercommunity.plus.share.SharePlusPlugin());
                                                                        ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:66: error: cannot find symbol
      Log.e(TAG, "Error registering plugin share_plus, dev.fluttercommunity.plus.share.SharePlusPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:69: error: package io.flutter.plugins.sharedpreferences does not exist
      flutterEngine.getPlugins().add(new io.flutter.plugins.sharedpreferences.SharedPreferencesPlugin());
                                                                             ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:71: error: cannot find symbol
      Log.e(TAG, "Error registering plugin shared_preferences_android, io.flutter.plugins.sharedpreferences.SharedPreferencesPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:74: error: package com.tekartik.sqflite does not exist
      flutterEngine.getPlugins().add(new com.tekartik.sqflite.SqflitePlugin());
                                                             ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:76: error: cannot find symbol
      Log.e(TAG, "Error registering plugin sqflite_android, com.tekartik.sqflite.SqflitePlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:79: error: package io.flutter.plugins.urllauncher does not exist
      flutterEngine.getPlugins().add(new io.flutter.plugins.urllauncher.UrlLauncherPlugin());
                                                                       ^
C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:81: error: cannot find symbol
      Log.e(TAG, "Error registering plugin url_launcher_android, io.flutter.plugins.urllauncher.UrlLauncherPlugin", e);
      ^
  symbol:   variable Log
  location: class GeneratedPluginRegistrant
29 errors

[Incubating] Problems report is available at: file:///C:/xampp/htdocs/swaapin/mobile/android/build/reports/problems/problems-report.html

FAILURE: Build failed with an exception.

* What went wrong:
Execution failed for task ':app:compileDebugJavaWithJavac'.
> Compilation failed; see the compiler output below.
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:5: error: cannot find symbol
  import io.flutter.Log;
                   ^
    symbol:   class Log
    location: package io.flutter
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:17: error: cannot find symbol
    public static void registerWith(@NonNull FlutterEngine flutterEngine) {
                                             ^
    symbol:   class FlutterEngine
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:21: error: cannot find symbol
        Log.e(TAG, "Error registering plugin connectivity_plus, dev.fluttercommunity.plus.connectivity.ConnectivityPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:26: error: cannot find symbol
        Log.e(TAG, "Error registering plugin flutter_local_notifications, com.dexterous.flutterlocalnotifications.FlutterLocalNotificationsPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:31: error: cannot find symbol
        Log.e(TAG, "Error registering plugin flutter_plugin_android_lifecycle, io.flutter.plugins.flutter_plugin_android_lifecycle.FlutterAndroidLifecyclePlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:36: error: cannot find symbol
        Log.e(TAG, "Error registering plugin geolocator_android, com.baseflow.geolocator.GeolocatorPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:41: error: cannot find symbol
        Log.e(TAG, "Error registering plugin image_picker_android, io.flutter.plugins.imagepicker.ImagePickerPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:46: error: cannot find symbol
        Log.e(TAG, "Error registering plugin jni, com.github.dart_lang.jni.JniPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:51: error: cannot find symbol
        Log.e(TAG, "Error registering plugin jni_flutter, com.github.dart_lang.jni_flutter.JniFlutterPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:56: error: cannot find symbol
        Log.e(TAG, "Error registering plugin package_info_plus, dev.fluttercommunity.plus.packageinfo.PackageInfoPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:61: error: cannot find symbol
        Log.e(TAG, "Error registering plugin permission_handler_android, com.baseflow.permissionhandler.PermissionHandlerPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:66: error: cannot find symbol
        Log.e(TAG, "Error registering plugin share_plus, dev.fluttercommunity.plus.share.SharePlusPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:71: error: cannot find symbol
        Log.e(TAG, "Error registering plugin shared_preferences_android, io.flutter.plugins.sharedpreferences.SharedPreferencesPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:76: error: cannot find symbol
        Log.e(TAG, "Error registering plugin sqflite_android, com.tekartik.sqflite.SqflitePlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:81: error: cannot find symbol
        Log.e(TAG, "Error registering plugin url_launcher_android, io.flutter.plugins.urllauncher.UrlLauncherPlugin", e);
        ^
    symbol:   variable Log
    location: class GeneratedPluginRegistrant
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:7: error: package io.flutter.embedding.engine does not exist
  import io.flutter.embedding.engine.FlutterEngine;
                                    ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:19: error: package dev.fluttercommunity.plus.connectivity does not exist
        flutterEngine.getPlugins().add(new dev.fluttercommunity.plus.connectivity.ConnectivityPlugin());
                                                                                 ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:24: error: package com.dexterous.flutterlocalnotifications does not exist
        flutterEngine.getPlugins().add(new com.dexterous.flutterlocalnotifications.FlutterLocalNotificationsPlugin());
                                                                                  ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:29: error: package io.flutter.plugins.flutter_plugin_android_lifecycle does not exist
        flutterEngine.getPlugins().add(new io.flutter.plugins.flutter_plugin_android_lifecycle.FlutterAndroidLifecyclePlugin());
                                                                                              ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:34: error: package com.baseflow.geolocator does not exist
        flutterEngine.getPlugins().add(new com.baseflow.geolocator.GeolocatorPlugin());
                                                                  ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:39: error: package io.flutter.plugins.imagepicker does not exist
        flutterEngine.getPlugins().add(new io.flutter.plugins.imagepicker.ImagePickerPlugin());
                                                                         ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:44: error: package com.github.dart_lang.jni does not exist
        flutterEngine.getPlugins().add(new com.github.dart_lang.jni.JniPlugin());
                                                                   ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:49: error: package com.github.dart_lang.jni_flutter does not exist
        flutterEngine.getPlugins().add(new com.github.dart_lang.jni_flutter.JniFlutterPlugin());
                                                                           ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:54: error: package dev.fluttercommunity.plus.packageinfo does not exist
        flutterEngine.getPlugins().add(new dev.fluttercommunity.plus.packageinfo.PackageInfoPlugin());
                                                                                ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:59: error: package com.baseflow.permissionhandler does not exist
        flutterEngine.getPlugins().add(new com.baseflow.permissionhandler.PermissionHandlerPlugin());
                                                                         ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:64: error: package dev.fluttercommunity.plus.share does not exist
        flutterEngine.getPlugins().add(new dev.fluttercommunity.plus.share.SharePlusPlugin());
                                                                          ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:69: error: package io.flutter.plugins.sharedpreferences does not exist
        flutterEngine.getPlugins().add(new io.flutter.plugins.sharedpreferences.SharedPreferencesPlugin());
                                                                               ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:74: error: package com.tekartik.sqflite does not exist
        flutterEngine.getPlugins().add(new com.tekartik.sqflite.SqflitePlugin());
                                                               ^
  C:\xampp\htdocs\swaapin\mobile\android\app\src\main\java\io\flutter\plugins\GeneratedPluginRegistrant.java:79: error: package io.flutter.plugins.urllauncher does not exist
        flutterEngine.getPlugins().add(new io.flutter.plugins.urllauncher.UrlLauncherPlugin());
                                                                         ^
  29 errors

* Try:
> Check your code and dependencies to fix the compilation error(s)
> Run with --scan to get full insights.

Deprecated Gradle features were used in this build, making it incompatible with Gradle 9.0.

You can use '--warning-mode all' to show the individual deprecation warnings and determine if they come from your own scripts or plugins.

For more on this, please refer to https://docs.gradle.org/8.14/userguide/command_line_interface.html#sec:command_line_warnings in the Gradle documentation.

BUILD FAILED in 2s
17 actionable tasks: 2 executed, 15 up-to-date