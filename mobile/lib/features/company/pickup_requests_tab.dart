import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import '../../core/services/auth_service.dart';
import '../../core/theme/app_theme.dart';
import '../../main.dart';

class PickupRequestsTab extends ConsumerStatefulWidget {
  const PickupRequestsTab({super.key});

  @override
  ConsumerState<PickupRequestsTab> createState() => _PickupRequestsTabState();
}

class _PickupRequestsTabState extends ConsumerState<PickupRequestsTab> {
  final _api = AuthService();
  List<Map<String, dynamic>> _requests = [];
  List<Map<String, dynamic>> _vehicles = [];
  bool _loading = true;
  String? _error;
  int? _workingRequest;

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
      final values = await Future.wait([
        _api.fetchCompanyPickups(token),
        _api.fetchCompanyVehicles(token),
      ]);
      if (!mounted) return;
      setState(() {
        _requests = _maps(values[0]);
        _vehicles = _maps(values[1]);
      });
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Could not load company pickup requests.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<Map<String, dynamic>> _maps(dynamic rows) => (rows as List<dynamic>)
      .map((row) => Map<String, dynamic>.from(row as Map))
      .toList();

  @override
  Widget build(BuildContext context) {
    final active =
        _requests.where((request) => request['status'] != 'Completed').toList();
    final availableVehicles =
        _vehicles.where((vehicle) => vehicle['status'] == 'Available').length;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
        children: [
          Row(children: [
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text('Pickup requests',
                      style: Theme.of(context)
                          .textTheme
                          .headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(
                      '${active.length} open requests  ·  $availableVehicles vehicles available',
                      style: const TextStyle(color: AppTheme.muted)),
                ])),
            IconButton(
              onPressed: _addVehicle,
              tooltip: 'Add vehicle and driver',
              icon: const Icon(Icons.add_circle_outline_rounded),
            ),
            IconButton(
                onPressed: _load,
                tooltip: 'Refresh pickup requests',
                icon: const Icon(Icons.refresh_rounded)),
          ]),
          const SizedBox(height: 14),
          if (_loading)
            const Padding(
                padding: EdgeInsets.all(36),
                child: Center(child: CircularProgressIndicator()))
          else if (_error != null)
            _errorState()
          else if (_requests.isEmpty)
            const _EmptyPickupState('No pickup requests for this company yet.')
          else
            ..._requests.map(_requestCard),
        ],
      ),
    );
  }

  Widget _requestCard(Map<String, dynamic> request) {
    final status = request['status']?.toString() ?? 'Pending';
    final completed = status == 'Completed';
    final assigned = request['vehicleId'] != null;
    final priority = request['priority']?.toString() ?? 'NORMAL';
    final color = completed
        ? AppTheme.primary
        : priority == 'HIGH'
            ? AppTheme.errorRed
            : AppTheme.warningAmber;
    final requestId = (request['requestId'] as num).toInt();
    return Card(
      margin: const EdgeInsets.only(bottom: 11),
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
                child: Text(request['requestCode']?.toString() ?? '',
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.w800))),
            _PickupBadge(status, color: color),
          ]),
          const SizedBox(height: 7),
          Text(
              '${request['boothCode'] ?? 'Booth'}  ·  ${request['payloadKg'] ?? 0} kg',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          if (!completed)
            Text(
                'Current booth weight: ${((request['currentWeightKg'] as num?)?.toDouble() ?? 0).toStringAsFixed(1)} kg',
                style: const TextStyle(color: AppTheme.muted, fontSize: 12)),
          const SizedBox(height: 3),
          Text(request['locationAddress']?.toString() ?? '',
              style: const TextStyle(color: AppTheme.muted, fontSize: 12)),
          if (assigned) ...[
            const SizedBox(height: 5),
            Text(
                'Driver: ${request['driverName'] ?? 'Assigned'}  ·  Vehicle: ${request['vehicleNumber'] ?? '—'}',
                style: const TextStyle(
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w700,
                    fontSize: 12)),
            Text(
                'Booth: ${request['boothCode'] ?? '—'}  ·  Assigned: ${_dateTime(request['assignedAt'] ?? request['createdAt'])}',
                style:
                    const TextStyle(color: AppTheme.muted, fontSize: 12)),
          ],
          if (!completed) ...[
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 7, children: [
              if (status == 'Pending')
                OutlinedButton.icon(
                    onPressed: _workingRequest == requestId
                        ? null
                        : () => _accept(requestId),
                    icon: const Icon(Icons.check_rounded),
                    label: const Text('Accept')),
              if (!assigned)
                FilledButton.icon(
                    onPressed: _workingRequest == requestId
                        ? null
                        : () => _assign(requestId),
                    icon: const Icon(Icons.local_shipping_outlined),
                    label: const Text('Assign vehicle')),
            ]),
          ] else ...[
            const SizedBox(height: 8),
            if (request['collectedKg'] != null)
              Text(
                  'Collected ${((request['collectedKg'] as num).toDouble()).toStringAsFixed(3)} kg',
                  style: const TextStyle(
                      color: AppTheme.primary,
                      fontSize: 12,
                      fontWeight: FontWeight.w700)),
            Text('Completed ${_date(request['completedAt'])}',
                style: const TextStyle(
                    color: AppTheme.primary,
                    fontSize: 12,
                    fontWeight: FontWeight.w700)),
          ],
        ]),
      ),
    );
  }

  Future<void> _accept(int requestId) async => _runAction(
        requestId,
        () => _api.acceptCompanyPickup(ref.read(authTokenProvider), requestId),
        'Pickup request accepted.',
      );

  Future<void> _assign(int requestId) async {
    final available =
        _vehicles.where((vehicle) => vehicle['status'] == 'Available').toList();
    if (available.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'No available vehicles. Add or release a company vehicle first.')));
      return;
    }
    final selected = await showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
          child: ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.all(16),
              children: [
            const Padding(
                padding: EdgeInsets.only(bottom: 8),
                child: Text('Select an available vehicle',
                    style:
                        TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
            ...available.map((vehicle) => ListTile(
                  leading: const Icon(Icons.local_shipping_outlined,
                      color: AppTheme.primary),
                  title:
                      Text(vehicle['vehicleNumber']?.toString() ?? 'Vehicle'),
                  subtitle: Text(
                      '${vehicle['vehicleType'] ?? ''}  ·  ${vehicle['driverName'] ?? ''}'),
                  onTap: () => Navigator.pop(
                      context, (vehicle['vehicleId'] as num).toInt()),
                )),
          ])),
    );
    if (selected == null) return;
    await _runAction(
      requestId,
      () => _api.assignCompanyVehicle(
          ref.read(authTokenProvider), requestId, selected),
      'Vehicle assigned to pickup.',
    );
  }

  Future<void> _addVehicle() async {
    final values = await showDialog<Map<String, String>>(
      context: context,
      builder: (_) => const _AddCompanyVehicleDialog(),
    );
    if (values == null) return;
    try {
      await _api.createCompanyVehicle(ref.read(authTokenProvider), values);
      await _load();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Vehicle and driver saved.')));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text(
                'Vehicle could not be saved. Check that its number is unique.')));
      }
    }
  }

  Future<void> _runAction(
      int requestId, Future<void> Function() action, String success) async {
    setState(() => _workingRequest = requestId);
    try {
      await action();
      await _load();
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(success)));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content:
                Text('Pickup could not be updated. Refresh and try again.')));
      }
    } finally {
      if (mounted) setState(() => _workingRequest = null);
    }
  }

  Widget _errorState() => Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        Text(_error!, style: const TextStyle(color: AppTheme.muted)),
        TextButton(onPressed: _load, child: const Text('Retry'))
      ]));

  String _date(dynamic value) {
    final parsed = DateTime.tryParse(value?.toString() ?? '');
    return parsed == null
        ? ''
        : DateFormat('MMM d, y').format(parsed.toLocal());
  }

  String _dateTime(dynamic value) {
    final parsed = DateTime.tryParse(value?.toString() ?? '');
    return parsed == null
        ? 'Time unavailable'
        : DateFormat('MMM d, y · h:mm a').format(parsed.toLocal());
  }
}

class _AddCompanyVehicleDialog extends StatefulWidget {
  const _AddCompanyVehicleDialog();

  @override
  State<_AddCompanyVehicleDialog> createState() =>
      _AddCompanyVehicleDialogState();
}

class _AddCompanyVehicleDialogState extends State<_AddCompanyVehicleDialog> {
  final _formKey = GlobalKey<FormState>();
  final _numberController = TextEditingController();
  final _typeController = TextEditingController();
  final _driverNameController = TextEditingController();
  final _driverPhoneController = TextEditingController();

  @override
  void dispose() {
    _numberController.dispose();
    _typeController.dispose();
    _driverNameController.dispose();
    _driverPhoneController.dispose();
    super.dispose();
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    Navigator.pop(context, {
      'vehicleNumber': _numberController.text.trim(),
      'vehicleType': _typeController.text.trim(),
      'driverName': _driverNameController.text.trim(),
      'driverPhone': _driverPhoneController.text.trim(),
    });
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('Add company vehicle'),
        content: Form(
          key: _formKey,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                _vehicleField(_numberController, 'Vehicle number',
                    'Enter the plate number'),
                _vehicleField(_typeController, 'Vehicle type', 'e.g. Truck'),
                _vehicleField(_driverNameController, 'Driver / employee name',
                    'Enter the assigned employee'),
                _vehicleField(_driverPhoneController, 'Driver phone',
                    'Enter a contact number'),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: _submit,
            child: const Text('Save vehicle'),
          ),
        ],
      );

  Widget _vehicleField(
    TextEditingController controller,
    String label,
    String hint,
  ) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextFormField(
          controller: controller,
          decoration: InputDecoration(labelText: label, hintText: hint),
          validator: (value) =>
              value == null || value.trim().isEmpty ? 'Required' : null,
        ),
      );
}

class _PickupBadge extends StatelessWidget {
  const _PickupBadge(this.label, {required this.color});
  final String label;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(5)),
      child: Text(label.toUpperCase(),
          style: TextStyle(
              color: color, fontSize: 10, fontWeight: FontWeight.w800)));
}

class _EmptyPickupState extends StatelessWidget {
  const _EmptyPickupState(this.message);
  final String message;
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 36),
      child: Center(
          child: Text(message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppTheme.muted))));
}
