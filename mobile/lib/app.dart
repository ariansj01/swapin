import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';

import 'core/auth_store.dart';
import 'core/theme.dart';
import 'features/auth/complete_profile_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/otp_screen.dart';
import 'features/home/home_screen.dart';
import 'features/listing/create_listing_screen.dart';
import 'features/listing/listing_detail_screen.dart';
import 'features/notifications/notifications_screen.dart';
import 'features/profile/profile_screen.dart';
import 'features/search/search_screen.dart';
import 'features/shell/main_shell.dart';
import 'features/trades/trades_screen.dart';

class SwaapinApp extends StatefulWidget {
  const SwaapinApp({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<SwaapinApp> createState() => _SwaapinAppState();
}

class _SwaapinAppState extends State<SwaapinApp> {
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    _router = GoRouter(
      initialLocation: '/',
      refreshListenable: widget.auth,
      routes: [
        GoRoute(path: '/login', builder: (c, s) => const LoginScreen()),
        GoRoute(
          path: '/otp',
          builder: (c, s) => OtpScreen(phone: s.uri.queryParameters['phone'] ?? ''),
        ),
        GoRoute(path: '/complete-profile', builder: (c, s) => const CompleteProfileScreen()),
        StatefulShellRoute.indexedStack(
          builder: (context, state, navigationShell) => MainShell(navigationShell: navigationShell),
          branches: [
            StatefulShellBranch(routes: [
              GoRoute(path: '/', builder: (c, s) => const HomeScreen()),
            ]),
            StatefulShellBranch(routes: [
              GoRoute(path: '/search', builder: (c, s) => const SearchScreen()),
            ]),
            StatefulShellBranch(routes: [
              GoRoute(path: '/create', builder: (c, s) => const CreateListingScreen()),
            ]),
            StatefulShellBranch(routes: [
              GoRoute(path: '/trades', builder: (c, s) => const TradesScreen()),
            ]),
            StatefulShellBranch(routes: [
              GoRoute(path: '/profile', builder: (c, s) => const ProfileScreen()),
            ]),
          ],
        ),
        GoRoute(
          path: '/listing/:id',
          builder: (c, s) => ListingDetailScreen(id: int.tryParse(s.pathParameters['id'] ?? '') ?? 0),
        ),
        GoRoute(path: '/notifications', builder: (c, s) => const NotificationsScreen()),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'سواَپین',
      debugShowCheckedModeBanner: false,
      theme: SwaapinTheme.light,
      locale: const Locale('fa'),
      supportedLocales: const [Locale('fa'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) {
        return Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        );
      },
      routerConfig: _router,
    );
  }
}
