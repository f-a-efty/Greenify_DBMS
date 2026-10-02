import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/services/auth_service.dart';
import '../../core/theme/app_theme.dart';
import '../../main.dart';

class CollectionHistoryTab extends ConsumerStatefulWidget {
  const CollectionHistoryTab({super.key});

  @override
  ConsumerState<CollectionHistoryTab> createState() =>
      _CollectionHistoryTabState();
}

class _CollectionHistoryTabState extends ConsumerState<CollectionHistoryTab> {
  final _api = AuthService();
  List<Map<String, dynamic>> _pickups = [];
  List<Map<String, dynamic>> _collections = [];
  final Set<int> _workingRequests = {};
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final token = ref.read(authTokenProvider);
      final results = await Future.wait<List<dynamic>>([
        _api.fetchCompanyPickups(token),
        _api.fetchCompanyCollections(token),
      ]);
      if (!mounted) return;
      setState(() {
        _pickups = _maps(results[0]);
        _collections = _maps(results[1]);
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = error.toString();
      });
    }
  }

  List<Map<String, dynamic>> _maps(List<dynamic> rows) =>
      rows.map((row) => Map<String, dynamic>.from(row as Map)).toList();

  Future<void> _collect(Map<String, dynamic> pickup) async {
    final requestId = (pickup['requestId'] as num).toInt();
    setState(() => _workingRequests.add(requestId));
    try {
      await _api.completeCompanyPickup(
        ref.read(authTokenProvider),
        requestId,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Full booth collection recorded successfully.'),
      ));
      await _load();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Collection failed: $error'),
      ));
    } finally {
      if (mounted) setState(() => _workingRequests.remove(requestId));
    }
  }

  @override
  Widget build(BuildContext context) {
    final totalKg = _collections.fold<double>(
      0,
      (total, item) => total + ((item['netWeightKg'] as num?)?.toDouble() ?? 0),
    );
    final activePickups = _pickups
        .where((pickup) =>
            pickup['status']?.toString().toLowerCase() == 'vehicle assigned')
        .toList();

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Row(
            children: [
              const Expanded(
                child: Text('Collection',
                    style:
                        TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
              ),
              IconButton(
                onPressed: _loading ? null : _load,
                icon: const Icon(Icons.refresh_rounded),
                tooltip: 'Refresh collections',
              ),
            ],
          ),
          const SizedBox(height: 4),
          const Text(
            'Complete assigned booth pickups and review collection history.',
            style: TextStyle(color: AppTheme.muted),
          ),
          const SizedBox(height: 18),
          if (_error != null)
            Card(
              color: AppTheme.errorRed.withValues(alpha: 0.08),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text('Could not load collection data: $_error'),
              ),
            ),
          if (_loading)
            const Center(
                child: Padding(
              padding: EdgeInsets.all(24),
              child: CircularProgressIndicator(),
            ))
          else ...[
            _summaryCard(_collections.length, totalKg),
            const SizedBox(height: 22),
            const Text('Ready for collection',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 10),
            if (activePickups.isEmpty)
              _emptyMessage('No vehicle-assigned pickups are waiting.')
            else
              ...activePickups.map(_assignedPickupCard),
            const SizedBox(height: 22),
            const Text('Collection history',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 10),
            if (_collections.isEmpty)
              _emptyMessage('Completed collections will appear here.')
            else
              ..._collections.map(_collectionCard),
          ],
        ],
      ),
    );
  }

  Widget _summaryCard(int count, double totalKg) => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: AppTheme.primary.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Row(
          children: [
            const Icon(Icons.scale_rounded, color: AppTheme.primary),
            const SizedBox(width: 12),
            Text('$count collections · ${totalKg.toStringAsFixed(2)} kg total',
                style: const TextStyle(fontWeight: FontWeight.w700)),
          ],
        ),
      );

  Widget _assignedPickupCard(Map<String, dynamic> pickup) {
    final requestId = (pickup['requestId'] as num).toInt();
    final working = _workingRequests.contains(requestId);
    final booth = pickup['boothCode']?.toString() ?? '—';
    final driver = pickup['driverName']?.toString() ?? '—';
    final vehicle = pickup['vehicleNumber']?.toString() ?? '—';
    final time = _formatDateTime(pickup['assignedAt'] ?? pickup['createdAt']);
    final weight = (pickup['currentWeightKg'] as num?)?.toDouble() ?? 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Collection booth: $booth',
                style:
                    const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
            const SizedBox(height: 7),
            Text('Assigned: $driver'),
            Text('Vehicle: $vehicle'),
            Text('Time: $time'),
            Text('Booth currently contains ${weight.toStringAsFixed(2)} kg'),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: working ? null : () => _collect(pickup),
                icon: working
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.local_shipping_rounded),
                label: Text(working ? 'Collecting…' : 'Collection'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _collectionCard(Map<String, dynamic> collection) {
    final booth = collection['boothCode']?.toString() ?? '—';
    final driver = collection['driverName']?.toString() ?? '—';
    final vehicle = collection['vehicleNumber']?.toString() ?? '—';
    final weight = (collection['netWeightKg'] as num?)?.toDouble() ?? 0;
    final date = _formatDateTime(collection['collectedAt']);

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: ListTile(
        leading: const CircleAvatar(
          backgroundColor: Color(0xFFE8F5E9),
          child: Icon(Icons.recycling_rounded, color: AppTheme.primary),
        ),
        title: Text('$booth · ${weight.toStringAsFixed(2)} kg',
            style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text('Assigned: $driver · Vehicle: $vehicle\n$date'),
        isThreeLine: true,
      ),
    );
  }

  Widget _emptyMessage(String message) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Text(message, style: const TextStyle(color: AppTheme.muted)),
      );

  String _formatDateTime(dynamic value) {
    final parsed = DateTime.tryParse(value?.toString() ?? '');
    return parsed == null
        ? 'Time unavailable'
        : DateFormat('MMM d, y · h:mm a').format(parsed.toLocal());
  }
}
