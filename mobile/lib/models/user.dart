class UserProfile {
  UserProfile({
    required this.id,
    required this.name,
    required this.phone,
    this.city,
    this.avatar,
    this.rating = 0,
    this.ratingCount = 0,
    this.creditBalance = 0,
    this.profileComplete = false,
    this.isStore = false,
  });

  final int id;
  final String name;
  final String phone;
  final String? city;
  final String? avatar;
  final double rating;
  final int ratingCount;
  final int creditBalance;
  final bool profileComplete;
  final bool isStore;

  factory UserProfile.fromJson(Map<String, dynamic> json) {
    return UserProfile(
      id: json['id'] as int? ?? 0,
      name: json['name'] as String? ?? '',
      phone: json['phone'] as String? ?? '',
      city: json['city'] as String?,
      avatar: json['avatar'] as String?,
      rating: (json['rating'] as num?)?.toDouble() ?? 0,
      ratingCount: json['rating_count'] as int? ?? 0,
      creditBalance: json['credit_balance'] as int? ?? 0,
      profileComplete: json['profile_complete'] as bool? ?? false,
      isStore: json['is_store'] as bool? ?? false,
    );
  }
}
