import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/services/auth_service.dart';
import '../../core/theme/app_theme.dart';
import '../../main.dart';
import 'company_dashboard_tab.dart';
import 'smart_booths_tab.dart';
import 'pickup_requests_tab.dart';
import 'collection_history_tab.dart';
import 'company_alerts_tab.dart';

class CompanyHomeScreen extends ConsumerStatefulWidget {
  const CompanyHomeScreen({super.key});

  @override
  ConsumerState<CompanyHomeScreen> createState() => _CompanyHomeScreenState();
}

class _CompanyHomeScreenState extends ConsumerState<CompanyHomeScreen> {
  final _api = AuthService();
  int _currentIndex = 0;

  final List<Widget> _tabs = const [
    CompanyDashboardTab(),
    SmartBoothsTab(),
    PickupRequestsTab(),
    CollectionHistoryTab(),
    CompanyAlertsTab(),
  ];

  Future<void> _logout() async {
    final token = ref.read(authTokenProvider);
    if (token != null) {
      try {
        await _api.logout(token);
      } catch (_) {}
    }
    if (!mounted) return;
    ref.read(authTokenProvider.notifier).state = null;
    ref.read(currentUserProvider.notifier).state = null;
    ref.read(userRoleProvider.notifier).state = null;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Image.asset('assets/logo/Greenify-01-01.png',
            height: 38, fit: BoxFit.contain),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout_rounded),
            onPressed: _logout,
          ),
        ],
      ),
      body: _tabs[_currentIndex],
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        onTap: (index) => setState(() => _currentIndex = index),
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppTheme.primary,
        unselectedItemColor: AppTheme.muted,
        items: const [
          BottomNavigationBarItem(
              icon: Icon(Icons.home_rounded), label: 'Home'),
          BottomNavigationBarItem(
              icon: Icon(Icons.smart_toy_rounded), label: 'Booths'),
          BottomNavigationBarItem(
              icon: Icon(Icons.local_shipping_rounded), label: 'Pickups'),
          BottomNavigationBarItem(
              icon: Icon(Icons.inventory_2_outlined), label: 'Collection'),
          BottomNavigationBarItem(
              icon: Icon(Icons.notifications_rounded), label: 'Alerts'),
        ],
      ),
    );
  }
}
