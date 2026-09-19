import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/auth_store.dart';
import '../../models/listing.dart';

class TradesScreen extends StatefulWidget {
  const TradesScreen({super.key});

  @override
  State<TradesScreen> createState() => _TradesScreenState();
}

class _TradesScreenState extends State<TradesScreen> {
  Future<List<TradeItem>>? _future;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final auth = context.read<AuthStore>();
    if (auth.isLoggedIn) {
      _future ??= _load();
    }
  }

  Future<List<TradeItem>> _load() async {
    final data = await context.read<AuthStore>().api.get('trades');
    return ((data['items'] as List?) ?? [])
        .map((e) => TradeItem.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthStore>();
    return Scaffold(
      appBar: AppBar(title: const Text('اتاق معامله')),
      body: !auth.isLoggedIn
          ? Center(
              child: FilledButton(
                onPressed: () => context.push('/login'),
                child: const Text('برای مشاهده معاملات وارد شوید'),
              ),
            )
          : FutureBuilder(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snap.hasError) return Center(child: Text('${snap.error}'));
                final items = snap.data ?? [];
                if (items.isEmpty) return const Center(child: Text('هنوز معامله‌ای ندارید'));
                return ListView.separated(
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (c, i) {
                    final t = items[i];
                    return ListTile(
                      leading: const Icon(Icons.swap_horiz),
                      title: Text(t.listingA ?? 'معامله #${t.id}'),
                      subtitle: Text(t.listingB ?? t.status),
                    );
                  },
                );
              },
            ),
    );
  }
}
