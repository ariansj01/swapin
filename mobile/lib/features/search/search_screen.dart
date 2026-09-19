import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_store.dart';
import '../../models/listing.dart';
import '../../widgets/listing_tile.dart';

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _q = TextEditingController();
  Future<List<ListingCard>>? _future;

  Future<List<ListingCard>> _search() async {
    final api = context.read<AuthStore>().api;
    final data = await api.get('listings', query: {'q': _q.text.trim()});
    return ((data['items'] as List?) ?? [])
        .map((e) => ListingCard.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('جستجو')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: TextField(
              controller: _q,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'عنوان، توضیح یا خواسته...',
                suffixIcon: IconButton(
                  icon: const Icon(Icons.search),
                  onPressed: () => setState(() => _future = _search()),
                ),
              ),
              onSubmitted: (_) => setState(() => _future = _search()),
            ),
          ),
          Expanded(
            child: _future == null
                ? const Center(child: Text('عبارت مورد نظر را جستجو کنید'))
                : FutureBuilder(
                    future: _future,
                    builder: (context, snap) {
                      if (snap.connectionState != ConnectionState.done) {
                        return const Center(child: CircularProgressIndicator());
                      }
                      final items = snap.data ?? [];
                      if (items.isEmpty) return const Center(child: Text('نتیجه‌ای نیست'));
                      return ListView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: items.length,
                        itemBuilder: (c, i) => ListingTile(item: items[i]),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
