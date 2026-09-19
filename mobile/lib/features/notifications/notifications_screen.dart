import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/auth_store.dart';
import '../../models/listing.dart';
import '../../native/pwa_native.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late Future<List<AppNotification>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<AppNotification>> _load() async {
    final auth = context.read<AuthStore>();
    if (!auth.isLoggedIn) return [];
    final data = await auth.api.get('notifications');
    final items = ((data['items'] as List?) ?? [])
        .map((e) => AppNotification.fromJson(e as Map<String, dynamic>))
        .toList();
    if (items.isNotEmpty) {
      final first = items.first;
      await PwaNative.showAlert(
        id: first.id.hashCode,
        title: first.title,
        body: first.body,
      );
    }
    return items;
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthStore>();
    return Scaffold(
      appBar: AppBar(title: const Text('اعلان‌ها')),
      body: !auth.isLoggedIn
          ? Center(
              child: FilledButton(
                onPressed: () => context.push('/login'),
                child: const Text('ورود'),
              ),
            )
          : FutureBuilder(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                final items = snap.data ?? [];
                if (items.isEmpty) return const Center(child: Text('اعلانی نیست'));
                return ListView.separated(
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (c, i) {
                    final n = items[i];
                    return ListTile(
                      title: Text(n.title),
                      subtitle: Text(n.body),
                      trailing: Text(n.timeAgo, style: const TextStyle(fontSize: 11)),
                    );
                  },
                );
              },
            ),
    );
  }
}
