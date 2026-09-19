class ListingCard {
  ListingCard({
    required this.id,
    required this.title,
    this.city,
    this.neighborhood,
    this.thumb,
    this.estimatedValue = 0,
    this.sellPrice = 0,
    this.listingMode = 'swap',
    this.wantInReturn = '',
    this.category,
    this.sellerName,
    this.timeAgo = '',
    this.description,
    this.images = const [],
    this.shareUrl,
    this.saved = false,
    this.isOwner = false,
  });

  final int id;
  final String title;
  final String? city;
  final String? neighborhood;
  final String? thumb;
  final int estimatedValue;
  final double sellPrice;
  final String listingMode;
  final String wantInReturn;
  final String? category;
  final String? sellerName;
  final String timeAgo;
  final String? description;
  final List<String> images;
  final String? shareUrl;
  final bool saved;
  final bool isOwner;

  factory ListingCard.fromJson(Map<String, dynamic> json) {
    final listing = json['listing'] is Map<String, dynamic>
        ? json['listing'] as Map<String, dynamic>
        : json;
    return ListingCard(
      id: listing['id'] as int? ?? 0,
      title: listing['title'] as String? ?? '',
      city: listing['city'] as String?,
      neighborhood: listing['neighborhood'] as String?,
      thumb: listing['thumb'] as String?,
      estimatedValue: listing['estimated_value'] as int? ?? 0,
      sellPrice: (listing['sell_price'] as num?)?.toDouble() ?? 0,
      listingMode: listing['listing_mode'] as String? ?? 'swap',
      wantInReturn: listing['want_in_return'] as String? ?? '',
      category: listing['category'] as String?,
      sellerName: listing['seller_name'] as String? ??
          (listing['seller'] is Map ? listing['seller']['name'] as String? : null),
      timeAgo: listing['time_ago'] as String? ?? '',
      description: listing['description'] as String?,
      images: (listing['images'] as List?)?.map((e) => '$e').toList() ?? const [],
      shareUrl: listing['share_url'] as String?,
      saved: listing['saved'] as bool? ?? false,
      isOwner: listing['is_owner'] as bool? ?? false,
    );
  }
}

class CategoryItem {
  CategoryItem({required this.id, required this.name, required this.slug, this.parentId});
  final int id;
  final String name;
  final String slug;
  final int? parentId;

  factory CategoryItem.fromJson(Map<String, dynamic> json) => CategoryItem(
        id: json['id'] as int? ?? 0,
        name: json['name'] as String? ?? '',
        slug: json['slug'] as String? ?? '',
        parentId: json['parent_id'] as int?,
      );
}

class AppNotification {
  AppNotification({
    required this.id,
    required this.title,
    required this.body,
    this.timeAgo = '',
    this.url,
  });
  final dynamic id;
  final String title;
  final String body;
  final String timeAgo;
  final String? url;

  factory AppNotification.fromJson(Map<String, dynamic> json) => AppNotification(
        id: json['id'],
        title: json['title'] as String? ?? '',
        body: json['body'] as String? ?? '',
        timeAgo: json['time_ago'] as String? ?? '',
        url: json['url'] as String?,
      );
}

class TradeItem {
  TradeItem({required this.id, required this.status, this.listingA, this.listingB, this.thumb});
  final int id;
  final String status;
  final String? listingA;
  final String? listingB;
  final String? thumb;

  factory TradeItem.fromJson(Map<String, dynamic> json) => TradeItem(
        id: json['id'] as int? ?? 0,
        status: json['status'] as String? ?? '',
        listingA: json['listing_a_title'] as String?,
        listingB: json['listing_b_title'] as String?,
        thumb: json['thumb'] as String?,
      );
}
