import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../models/listing.dart';

class ListingTile extends StatelessWidget {
  const ListingTile({super.key, required this.item});

  final ListingCard item;

  @override
  Widget build(BuildContext context) {
    final fmt = NumberFormat.decimalPattern('fa');
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        onTap: () => context.push('/listing/${item.id}'),
        child: Row(
          children: [
            SizedBox(
              width: 110,
              height: 110,
              child: item.thumb != null
                  ? CachedNetworkImage(imageUrl: item.thumb!, fit: BoxFit.cover)
                  : Container(
                      color: const Color(0xFFE2E8F0),
                      child: const Icon(Icons.image_outlined),
                    ),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(item.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 6),
                    Text(
                      [item.city, item.category].whereType<String>().where((e) => e.isNotEmpty).join(' · '),
                      style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      item.estimatedValue > 0 ? '${fmt.format(item.estimatedValue)} تومان' : 'معاوضه',
                      style: const TextStyle(color: Color(0xFF0A2540), fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
