import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../models/listing.dart';
import '../../native/pwa_native.dart';

class CreateListingScreen extends StatefulWidget {
  const CreateListingScreen({super.key});

  @override
  State<CreateListingScreen> createState() => _CreateListingScreenState();
}

class _CreateListingScreenState extends State<CreateListingScreen> {
  final _title = TextEditingController();
  final _desc = TextEditingController();
  final _want = TextEditingController();
  final _city = TextEditingController();
  final _neighborhood = TextEditingController();
  final _lat = TextEditingController();
  final _lng = TextEditingController();
  List<CategoryItem> _cats = [];
  int? _catId;
  List<XFile> _images = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadCats();
  }

  Future<void> _loadCats() async {
    final data = await context.read<AuthStore>().api.get('categories');
    final items = ((data['items'] as List?) ?? []).map((e) => CategoryItem.fromJson(e as Map<String, dynamic>)).toList();
    setState(() => _cats = items.where((c) => c.parentId != null).toList());
  }

  @override
  void dispose() {
    _title.dispose();
    _desc.dispose();
    _want.dispose();
    _city.dispose();
    _neighborhood.dispose();
    _lat.dispose();
    _lng.dispose();
    super.dispose();
  }

  Future<void> _fillLocation() async {
    final pos = await PwaNative.currentPosition();
    if (pos == null || !mounted) return;
    _lat.text = pos.latitude.toStringAsFixed(6);
    _lng.text = pos.longitude.toStringAsFixed(6);
    try {
      final data = await context.read<AuthStore>().api.get('nearby-cities', query: {
        'lat': '${pos.latitude}',
        'lng': '${pos.longitude}',
      });
      _city.text = '${data['nearest'] ?? ''}';
      setState(() {});
    } catch (_) {}
  }

  Future<void> _submit() async {
    final auth = context.read<AuthStore>();
    if (!auth.isLoggedIn) {
      context.push('/login');
      return;
    }
    if (!auth.user!.profileComplete) {
      context.push('/complete-profile');
      return;
    }
    if (_images.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('حداقل یک تصویر لازم است')));
      return;
    }
    setState(() => _busy = true);
    try {
      final files = <http.MultipartFile>[];
      for (final img in _images) {
        files.add(await http.MultipartFile.fromPath('images[]', img.path, filename: img.name));
      }
      await auth.api.postMultipart('listings', fields: {
        'title': _title.text.trim(),
        'description': _desc.text.trim(),
        'category_id': '${_catId ?? 0}',
        'want_description': _want.text.trim(),
        'city': _city.text.trim(),
        'neighborhood': _neighborhood.text.trim(),
        'latitude': _lat.text.trim(),
        'longitude': _lng.text.trim(),
        'condition': 'good',
      }, files: files);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('آگهی ثبت شد و در انتظار بررسی است')));
      _title.clear();
      _desc.clear();
      _want.clear();
      _images = [];
      setState(() {});
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('ثبت آگهی')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(controller: _title, decoration: const InputDecoration(labelText: 'عنوان')),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            initialValue: _catId,
            items: _cats.map((c) => DropdownMenuItem(value: c.id, child: Text(c.name))).toList(),
            onChanged: (v) => setState(() => _catId = v),
            decoration: const InputDecoration(labelText: 'دسته‌بندی'),
          ),
          const SizedBox(height: 12),
          TextField(controller: _desc, minLines: 4, maxLines: 6, decoration: const InputDecoration(labelText: 'توضیحات')),
          const SizedBox(height: 12),
          TextField(controller: _want, decoration: const InputDecoration(labelText: 'در ازای چه چیزی؟')),
          const SizedBox(height: 12),
          TextField(controller: _city, decoration: const InputDecoration(labelText: 'شهر')),
          const SizedBox(height: 12),
          TextField(controller: _neighborhood, decoration: const InputDecoration(labelText: 'محله')),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(child: TextField(controller: _lat, decoration: const InputDecoration(labelText: 'عرض'))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: _lng, decoration: const InputDecoration(labelText: 'طول'))),
            ],
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _fillLocation,
            icon: const Icon(Icons.my_location),
            label: const Text('موقعیت فعلی'),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            children: [
              ActionChip(
                avatar: const Icon(Icons.photo_library, size: 18),
                label: const Text('گالری'),
                onPressed: () async {
                  final files = await PwaNative.pickImages();
                  setState(() => _images = files);
                },
              ),
              ActionChip(
                avatar: const Icon(Icons.photo_camera, size: 18),
                label: const Text('دوربین'),
                onPressed: () async {
                  final shot = await PwaNative.capturePhoto();
                  if (shot != null) setState(() => _images = [..._images, shot]);
                },
              ),
              Text('${_images.length} تصویر'),
            ],
          ),
          const SizedBox(height: 24),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy ? const CircularProgressIndicator.adaptive() : const Text('ثبت آگهی'),
          ),
        ],
      ),
    );
  }
}
