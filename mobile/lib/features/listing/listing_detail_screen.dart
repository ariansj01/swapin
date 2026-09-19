import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../models/listing.dart';
import '../../native/pwa_native.dart';

class ListingDetailScreen extends StatefulWidget {
  const ListingDetailScreen({super.key, required this.id});
  final int id;

  @override
  State<ListingDetailScreen> createState() => _ListingDetailScreenState();
}

class _ListingDetailScreenState extends State<ListingDetailScreen> {
  late Future<ListingCard> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<ListingCard> _load() async {
    final data = await context.read<AuthStore>().api.get('listings/${widget.id}');
    return ListingCard.fromJson(data);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('جزئیات آگهی')),
      body: FutureBuilder(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snap.hasError) {
            return Center(child: Text('${snap.error}'));
          }
          final item = snap.data!;
          final images = item.images.isNotEmpty ? item.images : [if (item.thumb != null) item.thumb!];
          return ListView(
            padding: const EdgeInsets.only(bottom: 32),
            children: [
              SizedBox(
                height: 280,
                child: images.isEmpty
                    ? const ColoredBox(color: Color(0xFFE2E8F0), child: Icon(Icons.image, size: 64))
                    : PageView(
                        children: images
                            .map((url) => CachedNetworkImage(imageUrl: url, fit: BoxFit.cover))
                            .toList(),
                      ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(item.title, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 8),
                    Text([item.city, item.neighborhood, item.category].whereType<String>().where((e) => e.isNotEmpty).join(' · ')),
                    const SizedBox(height: 16),
                    Text(item.description ?? '', style: const TextStyle(height: 1.7)),
                    if (item.wantInReturn.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      Text('در ازای: ${item.wantInReturn}'),
                    ],
                    const SizedBox(height: 24),
                    Row(
                      children: [
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: item.shareUrl == null
                                ? null
                                : () => PwaNative.shareListing(title: item.title, url: item.shareUrl!),
                            icon: const Icon(Icons.share),
                            label: const Text('اشتراک‌گذاری'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: OutlinedButton(
                            onPressed: () async {
                              final auth = context.read<AuthStore>();
                              if (!auth.isLoggedIn) {
                                context.push('/login');
                                return;
                              }
                              try {
                                await auth.api.post('listings/${item.id}/save');
                                if (context.mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('وضعیت علاقه‌مندی به‌روز شد')));
                                }
                              } on ApiException catch (e) {
                                if (context.mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
                                }
                              }
                            },
                            child: const Text('ذخیره'),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
