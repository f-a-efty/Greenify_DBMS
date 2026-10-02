import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../core/services/auth_service.dart';
import '../../core/theme/app_theme.dart';

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _authService = AuthService();
  final _phoneController = TextEditingController();
  final _otpControllers = List.generate(6, (_) => TextEditingController());
  final _newPasswordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();
  int _step = 1;
  bool _isLoading = false;
  String? _resetToken;

  @override
  void dispose() {
    _phoneController.dispose();
    for (final controller in _otpControllers) {
      controller.dispose();
    }
    _newPasswordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  String get _normalizedPhone {
    final phone = _phoneController.text.trim();
    if (phone.startsWith('0') && phone.length == 11) {
      return '+880${phone.substring(1)}';
    }
    return phone.startsWith('+880') ? phone : '+880$phone';
  }

  String get _enteredOtp =>
      _otpControllers.map((controller) => controller.text).join();

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message), backgroundColor: Colors.red.shade700),
    );
  }

  String _errorMessage(Object error, String fallback) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] != null) {
        return data['message'].toString();
      }
    }
    if (error is FormatException) return error.message;
    return fallback;
  }

  Future<void> _sendOtp() async {
    if (_phoneController.text.trim().isEmpty) {
      _showError('Enter your registered phone number.');
      return;
    }
    setState(() => _isLoading = true);
    try {
      await _authService.sendPasswordResetOtp(_normalizedPhone);
      if (mounted) setState(() => _step = 2);
    } catch (error) {
      if (mounted) {
        _showError(
            _errorMessage(error, 'Could not send the verification code.'));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _verifyOtp() async {
    if (_enteredOtp.length != 6) {
      _showError('Enter the complete 6-digit verification code.');
      return;
    }
    setState(() => _isLoading = true);
    try {
      final token = await _authService.verifyPasswordResetOtp(
        _normalizedPhone,
        _enteredOtp,
      );
      if (mounted) {
        setState(() {
          _resetToken = token;
          _step = 3;
        });
      }
    } catch (error) {
      if (mounted) {
        _showError(_errorMessage(error, 'The verification code is invalid.'));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _saveNewPassword() async {
    final password = _newPasswordController.text;
    final confirmPassword = _confirmPasswordController.text;
    if (password.length < 8) {
      _showError('Password must be at least 8 characters long.');
      return;
    }
    if (password != confirmPassword) {
      _showError('Passwords do not match.');
      return;
    }
    final resetToken = _resetToken;
    if (resetToken == null) {
      _showError('Verify your phone number before resetting the password.');
      return;
    }

    setState(() => _isLoading = true);
    try {
      await _authService.resetPassword(
        phone: _normalizedPhone,
        resetToken: resetToken,
        password: password,
        confirmPassword: confirmPassword,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Password reset successfully. Please log in.'),
        ),
      );
      Navigator.pop(context);
    } catch (error) {
      if (mounted) {
        _showError(_errorMessage(error, 'Could not update your password.'));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reset Password')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: _step == 1
              ? _buildPhoneStep()
              : _step == 2
                  ? _buildOtpStep()
                  : _buildPasswordStep(),
        ),
      ),
    );
  }

  Widget _buildPhoneStep() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Enter your registered phone number to receive a verification code.',
            style: TextStyle(color: AppTheme.muted),
          ),
          const SizedBox(height: 24),
          const Text('Phone Number',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          TextField(
            controller: _phoneController,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(hintText: '017XXXXXXXX'),
          ),
          const SizedBox(height: 32),
          _actionButton('Send Verification OTP', _sendOtp),
        ],
      );

  Widget _buildOtpStep() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'Enter the 6-digit verification code sent to your phone. (Dev test mode: 123456)',
            style: TextStyle(color: AppTheme.muted),
          ),
          const SizedBox(height: 32),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: List.generate(
              6,
              (index) => SizedBox(
                width: 45,
                height: 55,
                child: TextField(
                  controller: _otpControllers[index],
                  textAlign: TextAlign.center,
                  keyboardType: TextInputType.number,
                  maxLength: 1,
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    color: AppTheme.primary,
                  ),
                  decoration: const InputDecoration(
                    counterText: '',
                    contentPadding: EdgeInsets.zero,
                  ),
                  onChanged: (value) {
                    if (value.isNotEmpty && index < 5) {
                      FocusScope.of(context).nextFocus();
                    }
                  },
                ),
              ),
            ),
          ),
          const SizedBox(height: 32),
          _actionButton('Verify OTP', _verifyOtp),
        ],
      );

  Widget _buildPasswordStep() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Set your new password.',
              style: TextStyle(color: AppTheme.muted)),
          const SizedBox(height: 24),
          const Text('New Password',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          TextField(
            controller: _newPasswordController,
            obscureText: true,
            decoration:
                const InputDecoration(hintText: 'At least 8 characters'),
          ),
          const SizedBox(height: 16),
          const Text('Confirm New Password',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          TextField(
            controller: _confirmPasswordController,
            obscureText: true,
            decoration:
                const InputDecoration(hintText: 'Repeat your new password'),
          ),
          const SizedBox(height: 32),
          _actionButton('Reset Password & Return to Login', _saveNewPassword),
        ],
      );

  Widget _actionButton(String label, VoidCallback action) => ElevatedButton(
        onPressed: _isLoading ? null : action,
        child: _isLoading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  color: Colors.white,
                ),
              )
            : Text(label),
      );
}
