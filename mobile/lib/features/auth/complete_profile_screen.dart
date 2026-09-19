import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';

class CompleteProfileScreen extends StatefulWidget {
  const CompleteProfileScreen({super.key});

  @override
  State<CompleteProfileScreen> createState() => _CompleteProfileScreenState();
}

class _CompleteProfileScreenState extends State<CompleteProfileScreen> {
  final _name = TextEditingController();
  String? _city;
  List<String> _cities = [];
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final user = context.read<AuthStore>().user;
    _name.text = user?.name ?? '';
    _city = user?.city;
    _loadCities();
  }

  Future<void> _loadCities() async {
    final data = await context.read<AuthStore>().api.get('cities');
    setState(() => _cities = ((data['cities'] as List?) ?? []).map((e) => '$e').toList());
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<AuthStore>().updateProfile(name: _name.text.trim(), city: _city ?? '');
      if (mounted) context.go('/');
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تکمیل پروفایل')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            TextField(controller: _name, decoration: const InputDecoration(labelText: 'نام')),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _cities.contains(_city) ? _city : null,
              items: _cities.map((c) => DropdownMenuItem(value: c, child: Text(c))).toList(),
              onChanged: (v) => setState(() => _city = v),
              decoration: const InputDecoration(labelText: 'شهر'),
            ),
            if (_error != null) Text(_error!, style: const TextStyle(color: Colors.red)),
            const SizedBox(height: 16),
            FilledButton(onPressed: _busy ? null : _save, child: const Text('ذخیره')),
          ],
        ),
      ),
    );
  }
}
