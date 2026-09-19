import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../models/listing.dart';
import '../../native/pwa_native.dart';
import '../../widgets/listing_tile.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late Future<List<ListingCard>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<ListingCard>> _load({String? nearby}) async {
    final api = context.read<AuthStore>().api;
    final query = <String, String>{};
    if (nearby != null && nearby.isNotEmpty) query['nearby_cities'] = nearby;
    final data = await api.get('listings', query: query.isEmpty ? null : query);
    final items = (data['items'] as List?) ?? [];
    return items.map((e) => ListingCard.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> _nearby() async {
    final pos = await PwaNative.currentPosition();
    if (!mounted) return;
    if (pos == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('دسترسی موقعیت رد شد')));
      return;
    }
    try {
      final api = context.read<AuthStore>().api;
      final data = await api.get('nearby-cities', query: {
        'lat': '${pos.latitude}',
        'lng': '${pos.longitude}',
      });
      final cities = ((data['cities'] as List?) ?? []).map((e) => '$e').join(',');
      setState(() => _future = _load(nearby: cities));
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('سواَپین'),
        actions: [
          IconButton(
            tooltip: 'نزدیک من',
            onPressed: _nearby,
            icon: const Icon(Icons.near_me_outlined),
          ),
          IconButton(
            onPressed: () => context.push('/notifications'),
            icon: const Icon(Icons.notifications_outlined),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          setState(() => _future = _load());
          await _future;
        },
        child: FutureBuilder(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snap.hasError) {
              return ListView(children: [
                Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text('خطا در دریافت آگهی‌ها\n${snap.error}', textAlign: TextAlign.center),
                ),
              ]);
            }
            final items = snap.data ?? [];
            if (items.isEmpty) {
              return ListView(children: const [
                Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('آگهی‌ای پیدا نشد', textAlign: TextAlign.center),
                ),
              ]);
            }
            return ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: items.length,
              itemBuilder: (c, i) => ListingTile(item: items[i]),
            );
          },
        ),
      ),
    );
  }
}
