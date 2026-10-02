import 'package:dio/dio.dart';

class AuthService {
  static const String _baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://127.0.0.1:8000/api/v1',
  );

  final Dio _dio = Dio(BaseOptions(
    baseUrl: _baseUrl,
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 10),
    headers: {'Content-Type': 'application/json'},
  ));

  Future<Map<String, dynamic>> login(String phone, String password) async {
    final response = await _dio.post('/auth/login', data: {
      'username': phone,
      'password': password,
    });
    return response.data as Map<String, dynamic>;
  }

  Future<void> logout(String? token) async {
    await _dio.post('/auth/logout', options: _adminOptions(token));
  }

  Future<String?> sendOtp(String phone) async {
    final response = await _dio.post('/auth/otp/send', data: {
      'phoneNumber': phone,
      'purpose': 'REGISTER',
    });
    return response.data['testCode'] as String?;
  }

  Future<String?> sendPasswordResetOtp(String phone) async {
    final response = await _dio.post('/auth/otp/send', data: {
      'phoneNumber': phone,
      'purpose': 'RESET',
    });
    return response.data['testCode'] as String?;
  }

  Future<String> verifyPasswordResetOtp(String phone, String code) async {
    final response = await _dio.post('/auth/otp/verify', data: {
      'phoneNumber': phone,
      'code': code,
      'purpose': 'RESET',
    });
    final token = response.data['resetToken'] as String?;
    if (response.data['verified'] != true || token == null || token.isEmpty) {
      throw const FormatException('Password reset verification failed.');
    }
    return token;
  }

  Future<void> resetPassword({
    required String phone,
    required String resetToken,
    required String password,
    required String confirmPassword,
  }) async {
    await _dio.post('/auth/password/reset', data: {
      'phoneNumber': phone,
      'resetToken': resetToken,
      'password': password,
      'confirmPassword': confirmPassword,
    });
  }

  Future<Map<String, dynamic>> registerUser({
    required String fullName,
    required String phoneNumber,
    required String password,
    required String confirmPassword,
    required String otpCode,
  }) async {
    final response = await _dio.post('/auth/register/user', data: {
      'fullName': fullName,
      'phoneNumber': phoneNumber,
      'password': password,
      'confirmPassword': confirmPassword,
      'otpCode': otpCode,
    });
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> registerCompany({
    required String companyName,
    required String registrationNumber,
    required String contactEmail,
    required String contactPhone,
    required String companyAddress,
    required String password,
    required String confirmPassword,
  }) async {
    final response = await _dio.post('/auth/register/company', data: {
      'companyName': companyName,
      'registrationNumber': registrationNumber,
      'permitInfo': '',
      'contactEmail': contactEmail,
      'contactPhone': contactPhone,
      'contactPersonName': companyName,
      'contactPersonPhone': contactPhone,
      'contactPersonEmail': contactEmail,
      'companyAddress': companyAddress,
      'region': 'Dhaka',
      'password': password,
      'confirmPassword': confirmPassword,
    });
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> fetchUserDashboard(String? token) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response = await _dio.get('/me/dashboard', options: options);
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> manualDeposit(
    String? token, {
    required double weightKg,
    String plasticType = 'PET/Mix',
    int? boothId,
  }) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response = await _dio.post(
      '/me/deposit/manual',
      data: {
        'weightKg': weightKg,
        'plasticType': plasticType,
        if (boothId != null) 'boothId': boothId,
      },
      options: options,
    );
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchTransactions(String? token) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response = await _dio.get('/me/transactions', options: options);
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> withdrawToBkash(
    String? token, {
    required int tokens,
    required String bkashNumber,
  }) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response = await _dio.post(
      '/me/withdraw',
      data: {
        'tokens': tokens,
        'bkashNumber': bkashNumber,
      },
      options: options,
    );
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchCoupons(String? token) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response = await _dio.get('/coupons', options: options);
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> redeemCoupon(String? token, int couponId) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response =
        await _dio.post('/coupons/$couponId/redeem', options: options);
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchLeaderboard(String? token) async {
    final options = token != null
        ? Options(headers: {'Authorization': 'Bearer $token'})
        : null;
    final response = await _dio.get('/leaderboard', options: options);
    return response.data as List<dynamic>;
  }

  Future<List<dynamic>> fetchBooths() async {
    final response = await _dio.get('/booths');
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> fetchPublicEconomics() async {
    final response = await _dio.get('/economics');
    return response.data as Map<String, dynamic>;
  }

  Options _adminOptions(String? token) =>
      Options(headers: {'Authorization': 'Bearer $token'});

  Future<Map<String, dynamic>> fetchAdminMetrics(String? token) async {
    final response = await _dio.get('/admin/dashboard/metrics',
        options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchAdminActivity(String? token) async {
    final response = await _dio.get('/admin/dashboard/activity',
        options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<List<dynamic>> fetchAdminUsers(String? token) async {
    final response =
        await _dio.get('/admin/users', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> fetchAdminUser(String? token, int userId) async {
    final response =
        await _dio.get('/admin/users/$userId', options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> toggleAdminUserStatus(
      String? token, int userId) async {
    final response = await _dio.post('/admin/users/$userId/toggle-status',
        options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchAdminBooths(String? token) async {
    final response =
        await _dio.get('/admin/booths', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> createAdminBooth(
      String? token, Map<String, dynamic> body) async {
    final response = await _dio.post('/admin/booths',
        data: body, options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<void> updateAdminBooth(
      String? token, int boothId, Map<String, dynamic> body) async {
    await _dio.put('/admin/booths/$boothId',
        data: body, options: _adminOptions(token));
  }

  Future<List<dynamic>> fetchAdminCollections(String? token) async {
    final response =
        await _dio.get('/admin/collections', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<List<dynamic>> fetchAdminPickupRequests(String? token) async {
    final response =
        await _dio.get('/admin/pickup-requests', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<List<dynamic>> fetchPendingCompanies(String? token) async {
    final response = await _dio.get('/admin/companies/pending',
        options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<List<dynamic>> fetchAdminCompanies(String? token) async {
    final response = await _dio.get('/admin/companies/active',
        options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<void> reviewCompany(String? token, int companyId,
      {required bool approve}) async {
    await _dio.post(
        '/admin/companies/$companyId/${approve ? 'approve' : 'reject'}',
        options: _adminOptions(token));
  }

  Future<List<dynamic>> fetchEconomics(String? token) async {
    final response = await _dio.get('/admin/config/economics',
        options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<void> updateEconomics(String? token, String key, String value) async {
    await _dio.post('/admin/config/economics',
        data: {'key': key, 'value': value}, options: _adminOptions(token));
  }

  Future<List<dynamic>> fetchAdminCoupons(String? token) async {
    final response =
        await _dio.get('/admin/coupons', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> saveAdminCoupon(
      String? token, Map<String, dynamic> body,
      {int? couponId}) async {
    final response = couponId == null
        ? await _dio.post('/admin/coupons',
            data: body, options: _adminOptions(token))
        : await _dio.put('/admin/coupons/$couponId',
            data: body, options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<void> archiveAdminCoupon(String? token, int couponId) async {
    await _dio.delete('/admin/coupons/$couponId',
        options: _adminOptions(token));
  }

  Future<List<dynamic>> fetchLoyaltyLevels(String? token) async {
    final response =
        await _dio.get('/admin/loyalty-levels', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<void> updateLoyaltyLevel(
      String? token, String level, Map<String, dynamic> body) async {
    await _dio.put('/admin/loyalty-levels/${Uri.encodeComponent(level)}',
        data: body, options: _adminOptions(token));
  }

  Future<List<dynamic>> fetchCampaigns(String? token) async {
    final response =
        await _dio.get('/admin/campaigns', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> saveCampaign(
      String? token, Map<String, dynamic> body,
      {int? campaignId}) async {
    final response = campaignId == null
        ? await _dio.post('/admin/campaigns',
            data: body, options: _adminOptions(token))
        : await _dio.put('/admin/campaigns/$campaignId',
            data: body, options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<void> archiveCampaign(String? token, int campaignId) async {
    await _dio.delete('/admin/campaigns/$campaignId',
        options: _adminOptions(token));
  }

  Future<Map<String, dynamic>> fetchAdminAnalytics(String? token,
      {int days = 30}) async {
    final response = await _dio.get('/admin/reports/analytics',
        queryParameters: {'days': days}, options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchCompanyPickups(String? token) async {
    final response = await _dio.get('/company/pickup-requests',
        options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> fetchCompanyDashboard(String? token) async {
    final response =
        await _dio.get('/company/dashboard', options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchCompanyBooths(String? token) async {
    final response =
        await _dio.get('/company/booths', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<Map<String, dynamic>> requestCompanyPickup(
      String? token, int boothId) async {
    final response = await _dio.post('/company/booths/$boothId/pickup-requests',
        options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }

  Future<List<dynamic>> fetchCompanyVehicles(String? token) async {
    final response =
        await _dio.get('/company/vehicles', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<void> acceptCompanyPickup(String? token, int requestId) async {
    await _dio.post('/company/pickup-requests/$requestId/accept',
        options: _adminOptions(token));
  }

  Future<void> assignCompanyVehicle(
      String? token, int requestId, int vehicleId) async {
    await _dio.post('/company/pickup-requests/$requestId/assign-vehicle',
        data: {'vehicleId': vehicleId}, options: _adminOptions(token));
  }

  Future<void> completeCompanyPickup(String? token, int requestId,
      {double? collectedWeightKg,
      String plasticGrade = 'PET 100% Sorted'}) async {
    await _dio.post('/company/pickup-requests/$requestId/complete',
        data: {
          if (collectedWeightKg != null)
            'collectedWeightKg': collectedWeightKg,
          'plasticGrade': plasticGrade,
        },
        options: _adminOptions(token));
  }

  Future<List<dynamic>> fetchCompanyCollections(String? token) async {
    final response =
        await _dio.get('/company/collections', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<List<dynamic>> fetchCompanyAlerts(String? token) async {
    final response =
        await _dio.get('/company/alerts', options: _adminOptions(token));
    return response.data as List<dynamic>;
  }

  Future<void> markCompanyAlertRead(String? token, int notificationId) async {
    await _dio.post('/company/alerts/$notificationId/read',
        options: _adminOptions(token));
  }

  Future<Map<String, dynamic>> createCompanyVehicle(
      String? token, Map<String, dynamic> body) async {
    final response = await _dio.post('/company/vehicles',
        data: body, options: _adminOptions(token));
    return response.data as Map<String, dynamic>;
  }
}
