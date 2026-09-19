class AppConfig {
  AppConfig._();

  static const brandNavy = 0xFF0A2540;
  static const appName = 'سواَپین';

  /// Override at build: `--dart-define=API_BASE=https://swaapin.ir/api/v1`
  static const apiBaseFromEnv = String.fromEnvironment('API_BASE');

  static const productionApi = 'https://swaapin.ir/api/v1';
  static const emulatorApi = 'http://10.0.2.2/swaapin/api/v1';
}
