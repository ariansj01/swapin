import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/config.dart';
import '../../native/pwa_native.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _api = TextEditingController();

  @override
  void initState() {
    super.initState();
    ApiClient.resolveBase().then((v) {
      if (mounted) _api.text = v;
    });
  }

  @override
  void dispose() {
    _api.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthStore>();
    final user = auth.user;
    final money = NumberFormat.decimalPattern('fa');

    return Scaffold(
      appBar: AppBar(title: const Text('پروفایل')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (!auth.isLoggedIn)
            FilledButton(
              onPressed: () => context.push('/login'),
              child: const Text('ورود / ثبت‌نام'),
            )
          else ...[
            ListTile(
              leading: const CircleAvatar(child: Icon(Icons.person)),
              title: Text(user?.name.isNotEmpty == true ? user!.name : 'کاربر سواَپین'),
              subtitle: Text(user?.phone ?? ''),
            ),
            ListTile(
              leading: const Icon(Icons.account_balance_wallet_outlined),
              title: const Text('کیف پول'),
              subtitle: Text('${money.format(user?.creditBalance ?? 0)} تومان'),
            ),
            ListTile(
              leading: const Icon(Icons.notifications_active_outlined),
              title: const Text('فعال‌سازی اعلان'),
              onTap: () async {
                final ok = await PwaNative.requestNotifications();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(ok ? 'اعلان فعال شد' : 'اجازه اعلان داده نشد')),
                  );
                }
              },
            ),
            ListTile(
              leading: const Icon(Icons.edit_outlined),
              title: const Text('تکمیل پروفایل'),
              onTap: () => context.push('/complete-profile'),
            ),
            FilledButton.tonal(
              onPressed: () => auth.logout(),
              child: const Text('خروج'),
            ),
          ],
          const SizedBox(height: 24),
          TextField(
            controller: _api,
            decoration: InputDecoration(
              labelText: 'آدرس API',
              helperText: 'برای تست لوکال مثلاً ${AppConfig.emulatorApi}',
            ),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              await ApiClient.saveBase(_api.text.trim());
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('آدرس API ذخیره شد')));
              }
            },
            child: const Text('ذخیره آدرس سرور'),
          ),
        ],
      ),
    );
  }
}
