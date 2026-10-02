<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(int $status, mixed $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message): never
{
    respond($status, ['success' => false, 'message' => $message]);
}

function request_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fail(400, 'Request body must be valid JSON.');
    }
    return $body;
}

function database(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'greenify_db';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') ?: '';

    return new PDO(
        "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function config_value(PDO $db, string $key, int $fallback): int
{
    $query = $db->prepare('SELECT config_value FROM system_config WHERE config_key = ?');
    $query->execute([$key]);
    $value = $query->fetchColumn();
    return $value === false ? $fallback : (int) $value;
}

function bearer_user(PDO $db): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        fail(401, 'Authentication required.');
    }

    $tokenHash = hash('sha256', $matches[1]);
    $query = $db->prepare(
        'SELECT u.* FROM refresh_tokens t JOIN users u ON u.user_id = t.user_id
         WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > CURRENT_TIMESTAMP AND u.status = \'ACTIVE\''
    );
    $query->execute([$tokenHash]);
    $user = $query->fetch();
    if (!$user) {
        fail(401, 'Your session has expired. Please log in again.');
    }
    return $user;
}

function issue_token(PDO $db, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $query = $db->prepare(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 DAY))'
    );
    $query->execute([$userId, hash('sha256', $token)]);
    return $token;
}

function auth_response(PDO $db, array $user): array
{
    $token = issue_token($db, (int) $user['user_id']);
    return [
        'success' => true,
        'accessToken' => $token,
        'refreshToken' => $token,
        'tokenType' => 'Bearer',
        'userId' => (int) $user['user_id'],
        'fullName' => $user['full_name'],
        'phoneNumber' => $user['phone_number'],
        'role' => $user['role'],
    ];
}

function update_loyalty(PDO $db, int $userId): string
{
    $query = $db->prepare('SELECT COALESCE(SUM(plastic_weight_kg), 0) FROM plastic_deposits WHERE user_id = ?');
    $query->execute([$userId]);
    $weight = (float) $query->fetchColumn();
    $query = $db->prepare('SELECT level FROM loyalty_levels WHERE min_kg <= ? AND max_kg >= ? ORDER BY min_kg DESC LIMIT 1');
    $query->execute([$weight, $weight]);
    $level = $query->fetchColumn();
    if ($level === false) {
        $query = $db->prepare('SELECT level FROM loyalty_levels WHERE min_kg <= ? ORDER BY min_kg DESC LIMIT 1');
        $query->execute([$weight]);
        $level = $query->fetchColumn() ?: 'Eco Buddy';
    }
    $query = $db->prepare('UPDATE users SET loyalty_level = ? WHERE user_id = ?');
    $query->execute([$level, $userId]);
    return $level;
}

function require_role(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        fail(403, 'You do not have permission to access this resource.');
    }
}

function company_id_for_user(PDO $db, array $user): int
{
    $query = $db->prepare('SELECT company_id FROM recycling_companies WHERE contact_phone = ?');
    $query->execute([$user['phone_number']]);
    $companyId = $query->fetchColumn();
    if ($companyId === false) {
        fail(404, 'Company profile not found.');
    }
    return (int) $companyId;
}

function notify_company(PDO $db, int $companyId, string $title, string $message, string $category): void
{
    $query = $db->prepare(
        'INSERT INTO notifications (recipient_role, recipient_id, title, message, category) VALUES (\'COMPANY\', ?, ?, ?, ?)'
    );
    $query->execute([$companyId, $title, $message, $category]);
}

function create_full_booth_pickup(
    PDO $db,
    int $boothId,
    ?int $companyId,
    string $boothCode,
    float $weightKg,
    string $boothStatus
): ?string {
    if ($companyId === null || $weightKg <= 0 || $boothStatus !== 'Full') {
        return null;
    }

    $query = $db->prepare(
        'SELECT request_code FROM pickup_requests
         WHERE booth_id = ? AND status IN (\'Pending\', \'Accepted\', \'Vehicle Assigned\', \'On Pickup\')
         LIMIT 1 FOR UPDATE'
    );
    $query->execute([$boothId]);
    if ($query->fetchColumn() !== false) {
        return null;
    }

    $safeBoothCode = preg_replace('/[^A-Za-z0-9-]/', '', $boothCode);
    $requestCode = 'REQ-' . $safeBoothCode . '-' . strtoupper(bin2hex(random_bytes(2)));
    $query = $db->prepare(
        'INSERT INTO pickup_requests (request_code, booth_id, company_id, priority, status, payload_kg_at_request)
         VALUES (?, ?, ?, \'HIGH\', \'Pending\', ?)'
    );
    $query->execute([$requestCode, $boothId, $companyId, $weightKg]);
    notify_company(
        $db,
        $companyId,
        'Full booth pickup requested',
        $requestCode . ' is ready for collection at ' . $boothCode . '.',
        'Pickup'
    );

    return $requestCode;
}

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/api/v1#', '', $path) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($path === '/health' && $method === 'GET') {
        try {
            database()->query('SELECT 1');
            respond(200, ['status' => 'ok', 'database' => 'connected']);
        } catch (Throwable $exception) {
            respond(503, ['status' => 'error', 'database' => 'unavailable']);
        }
    }

    $db = database();

    if ($path === '/auth/otp/send' && $method === 'POST') {
        $body = request_body();
        $phone = trim((string) ($body['phoneNumber'] ?? ''));
        $purpose = (string) ($body['purpose'] ?? 'REGISTER');
        if ($phone === '') {
            fail(400, 'Phone number is required.');
        }
        if (!in_array($purpose, ['REGISTER', 'RESET'], true)) {
            fail(400, 'Invalid OTP purpose.');
        }

        $canSend = true;
        if ($purpose === 'RESET') {
            $query = $db->prepare("SELECT 1 FROM users WHERE phone_number = ? AND role IN ('USER', 'COMPANY') AND status = 'ACTIVE'");
            $query->execute([$phone]);
            $canSend = (bool) $query->fetchColumn();
        }
        if ($canSend) {
            $query = $db->prepare(
                'UPDATE otp_codes SET consumed = 1 WHERE phone_number = ? AND purpose = ? AND consumed = 0'
            );
            $query->execute([$phone, $purpose]);
            $query = $db->prepare(
                'INSERT INTO otp_codes (phone_number, code_hash, purpose, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE))'
            );
            $query->execute([$phone, password_hash('123456', PASSWORD_DEFAULT), $purpose]);
        }
        respond(200, [
            'success' => true,
            'message' => 'OTP verification code dispatched.',
            'testCode' => '123456',
        ]);
    }

    if ($path === '/auth/otp/verify' && $method === 'POST') {
        $body = request_body();
        $phone = trim((string) ($body['phoneNumber'] ?? ''));
        $purpose = (string) ($body['purpose'] ?? 'REGISTER');
        $code = trim((string) ($body['code'] ?? ''));
        if ($phone === '' || !in_array($purpose, ['REGISTER', 'RESET'], true)) {
            fail(400, 'Phone number and valid OTP purpose are required.');
        }
        $query = $db->prepare(
            'SELECT otp_id, code_hash, attempts FROM otp_codes
             WHERE phone_number = ? AND purpose = ? AND consumed = 0
               AND expires_at > CURRENT_TIMESTAMP AND attempts < 5
             ORDER BY created_at DESC, otp_id DESC LIMIT 1'
        );
        $query->execute([$phone, $purpose]);
        $otp = $query->fetch();
        if (!$otp || !password_verify($code, $otp['code_hash'])) {
            if ($otp) {
                $query = $db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = ?');
                $query->execute([$otp['otp_id']]);
            }
            fail(400, 'Invalid OTP code.');
        }
        $query = $db->prepare('UPDATE otp_codes SET consumed = 1 WHERE otp_id = ?');
        $query->execute([$otp['otp_id']]);

        $response = ['verified' => true];
        if ($purpose === 'RESET') {
            $resetToken = bin2hex(random_bytes(32));
            $query = $db->prepare(
                'INSERT INTO otp_codes (phone_number, code_hash, purpose, expires_at)
                 VALUES (?, ?, \'RESET\', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE))'
            );
            $query->execute([$phone, password_hash($resetToken, PASSWORD_DEFAULT)]);
            $response['resetToken'] = $resetToken;
        }
        respond(200, $response);
    }

    if ($path === '/auth/password/reset' && $method === 'POST') {
        $body = request_body();
        $phone = trim((string) ($body['phoneNumber'] ?? ''));
        $resetToken = (string) ($body['resetToken'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $confirmPassword = (string) ($body['confirmPassword'] ?? '');
        if ($phone === '' || $resetToken === '' || $password === '' || $confirmPassword === '') {
            fail(400, 'Phone number, reset token, and both password fields are required.');
        }
        if (!hash_equals($password, $confirmPassword)) {
            fail(400, 'Passwords do not match.');
        }
        if (strlen($password) < 8) {
            fail(400, 'Password must be at least 8 characters long.');
        }

        $db->beginTransaction();
        try {
            $query = $db->prepare(
                'SELECT otp_id, code_hash FROM otp_codes
                 WHERE phone_number = ? AND purpose = \'RESET\' AND consumed = 0
                   AND expires_at > CURRENT_TIMESTAMP
                 ORDER BY created_at DESC, otp_id DESC LIMIT 1 FOR UPDATE'
            );
            $query->execute([$phone]);
            $reset = $query->fetch();
            if (!$reset || !password_verify($resetToken, $reset['code_hash'])) {
                $db->rollBack();
                fail(400, 'Password reset verification has expired. Please request a new code.');
            }

            $query = $db->prepare(
                "UPDATE users SET password_hash = ? WHERE phone_number = ? AND role IN ('USER', 'COMPANY') AND status = 'ACTIVE'"
            );
            $query->execute([password_hash($password, PASSWORD_DEFAULT), $phone]);
            if ($query->rowCount() !== 1) {
                $db->rollBack();
                fail(404, 'Account not found or inactive.');
            }
            $query = $db->prepare('UPDATE otp_codes SET consumed = 1 WHERE otp_id = ?');
            $query->execute([$reset['otp_id']]);
            $query = $db->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = (SELECT user_id FROM users WHERE phone_number = ?)');
            $query->execute([$phone]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        respond(200, ['success' => true, 'message' => 'Password updated successfully.']);
    }

    if ($path === '/auth/register/user' && $method === 'POST') {
        $body = request_body();
        foreach (['fullName', 'phoneNumber', 'password', 'confirmPassword', 'otpCode'] as $field) {
            if (empty($body[$field])) {
                fail(400, ucfirst($field) . ' is required.');
            }
        }
        if (!hash_equals((string) $body['password'], (string) $body['confirmPassword'])) {
            fail(400, 'Passwords do not match.');
        }
        if (strlen((string) $body['password']) < 8) {
            fail(400, 'Password must be at least 8 characters long.');
        }
        if ($body['otpCode'] !== '123456') {
            fail(400, 'Invalid OTP code.');
        }

        $query = $db->prepare(
            'INSERT INTO users (full_name, phone_number, password_hash, role, status) VALUES (?, ?, ?, \'USER\', \'ACTIVE\')'
        );
        try {
            $query->execute([
                trim((string) $body['fullName']),
                trim((string) $body['phoneNumber']),
                password_hash((string) $body['password'], PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                fail(409, 'An account with this phone number already exists.');
            }
            throw $exception;
        }
        $user = $db->query('SELECT * FROM users WHERE user_id = ' . (int) $db->lastInsertId())->fetch();
        respond(201, auth_response($db, $user));
    }

    if ($path === '/auth/register/company' && $method === 'POST') {
        $body = request_body();
        foreach (['companyName', 'registrationNumber', 'contactEmail', 'contactPhone', 'companyAddress', 'password', 'confirmPassword'] as $field) {
            if (empty($body[$field])) {
                fail(400, ucfirst($field) . ' is required.');
            }
        }
        if (!hash_equals((string) $body['password'], (string) $body['confirmPassword'])) {
            fail(400, 'Passwords do not match.');
        }
        if (strlen((string) $body['password']) < 8) {
            fail(400, 'Password must be at least 8 characters long.');
        }

        $db->beginTransaction();
        try {
            $query = $db->prepare(
                'INSERT INTO users (full_name, phone_number, password_hash, role, status) VALUES (?, ?, ?, \'COMPANY\', \'ACTIVE\')'
            );
            $query->execute([
                trim((string) ($body['contactPersonName'] ?? $body['companyName'])),
                trim((string) $body['contactPhone']),
                password_hash((string) $body['password'], PASSWORD_DEFAULT),
            ]);
            $query = $db->prepare(
                'INSERT INTO recycling_companies (company_name, registration_number, permit_info, contact_email, contact_phone, contact_person_name, contact_person_phone, contact_person_email, company_address, region, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'PENDING\')'
            );
            $query->execute([
                trim((string) $body['companyName']),
                trim((string) $body['registrationNumber']),
                $body['permitInfo'] ?? '',
                trim((string) $body['contactEmail']),
                trim((string) $body['contactPhone']),
                trim((string) ($body['contactPersonName'] ?? $body['companyName'])),
                trim((string) ($body['contactPersonPhone'] ?? $body['contactPhone'])),
                trim((string) ($body['contactPersonEmail'] ?? $body['contactEmail'])),
                trim((string) $body['companyAddress']),
                trim((string) ($body['region'] ?? 'Dhaka')),
            ]);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                fail(409, 'The phone, email, or registration number is already registered.');
            }
            throw $exception;
        }
        respond(201, ['success' => true, 'message' => 'Company registration submitted for administrator review.']);
    }

    if ($path === '/auth/login' && $method === 'POST') {
        $body = request_body();
        $phone = trim((string) ($body['username'] ?? ''));
        $query = $db->prepare('SELECT * FROM users WHERE phone_number = ?');
        $query->execute([$phone]);
        $user = $query->fetch();
        if (!$user || !password_verify((string) ($body['password'] ?? ''), $user['password_hash'])) {
            fail(401, 'Invalid phone number or password.');
        }
        if ($user['status'] !== 'ACTIVE') {
            fail(403, 'This account is currently suspended.');
        }
        if ($user['role'] === 'COMPANY') {
            $query = $db->prepare('SELECT status FROM recycling_companies WHERE contact_phone = ?');
            $query->execute([$phone]);
            if ($query->fetchColumn() !== 'ACTIVE') {
                fail(403, 'Your company account is pending administrator approval.');
            }
        }
        respond(200, auth_response($db, $user));
    }

    if ($path === '/auth/refresh' && $method === 'POST') {
        $body = request_body();
        $tokenHash = hash('sha256', (string) ($body['refreshToken'] ?? ''));
        $query = $db->prepare(
            'SELECT u.* FROM refresh_tokens t JOIN users u ON u.user_id = t.user_id WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > CURRENT_TIMESTAMP'
        );
        $query->execute([$tokenHash]);
        $user = $query->fetch();
        if (!$user) {
            fail(401, 'Invalid refresh token.');
        }
        respond(200, auth_response($db, $user));
    }

    if ($path === '/auth/logout' && $method === 'POST') {
        bearer_user($db);
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        preg_match('/^Bearer\s+(.+)$/i', $header, $matches);
        $query = $db->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = ?');
        $query->execute([hash('sha256', $matches[1])]);
        respond(200, ['success' => true]);
    }

    if ($path === '/economics' && $method === 'GET') {
        respond(200, [
            'tokensPerKg' => config_value($db, 'tokens_per_kg', 100),
            'tokensPerTaka' => config_value($db, 'tokens_per_taka', 4),
            'minimumWithdrawalTaka' => config_value($db, 'min_withdrawal_taka', 100),
        ]);
    }

    if ($path === '/me/dashboard' && $method === 'GET') {
        $user = bearer_user($db);
        $query = $db->prepare(
            'SELECT COALESCE(SUM(plastic_weight_kg), 0) AS total_weight, COUNT(*) AS deposit_count FROM plastic_deposits WHERE user_id = ?'
        );
        $query->execute([$user['user_id']]);
        $stats = $query->fetch();
        $tokensPerTaka = config_value($db, 'tokens_per_taka', 4);
        $tokensPerKg = config_value($db, 'tokens_per_kg', 100);
        $minimumWithdrawalTaka = config_value($db, 'min_withdrawal_taka', 100);
        respond(200, [
            'userId' => (int) $user['user_id'],
            'fullName' => $user['full_name'],
            'phoneNumber' => $user['phone_number'],
            'bkashNumber' => $user['bkash_number'] ?? '',
            'totalTokens' => (int) $user['total_tokens'],
            'walletBalanceTaka' => (float) $user['total_tokens'] / max(1, $tokensPerTaka),
            'tokensPerKg' => $tokensPerKg,
            'tokensPerTaka' => $tokensPerTaka,
            'minimumWithdrawalTokens' => $minimumWithdrawalTaka * $tokensPerTaka,
            'loyaltyLevel' => $user['loyalty_level'],
            'totalPlasticKg' => (float) $stats['total_weight'],
            'totalDeposits' => (int) $stats['deposit_count'],
            'co2PreventedKg' => (float) $stats['total_weight'] * 1.5,
        ]);
    }

    if ($path === '/me/deposit/manual' && $method === 'POST') {
        $user = bearer_user($db);
        $body = request_body();
        $weight = filter_var($body['weightKg'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($weight === false || $weight <= 0) {
            fail(400, 'Deposit weight must be greater than 0 kg.');
        }

        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM users WHERE user_id = ? FOR UPDATE');
            $query->execute([$user['user_id']]);
            $lockedUser = $query->fetch();
            $boothId = isset($body['boothId']) ? (int) $body['boothId'] : 0;
            $query = $boothId > 0
                ? $db->prepare('SELECT * FROM smart_booths WHERE booth_id = ? FOR UPDATE')
                : $db->query('SELECT * FROM smart_booths ORDER BY booth_id LIMIT 1 FOR UPDATE');
            if ($boothId > 0) {
                $query->execute([$boothId]);
            }
            $booth = $query->fetch();
            if (!$booth) {
                fail(400, 'No collection booth is available.');
            }
            $newWeight = (float) $booth['current_weight_kg'] + $weight;
            $capacity = (float) $booth['capacity_kg'];
            if ($newWeight > $capacity) {
                fail(400, 'Submitted weight exceeds the booth\'s remaining capacity.');
            }

            $tokensPerKg = config_value($db, 'tokens_per_kg', 100);
            $tokensEarned = (int) floor($weight * $tokensPerKg + 0.000001);
            $sessionId = 'DS-MANUAL-' . strtoupper(bin2hex(random_bytes(4)));
            $query = $db->prepare(
                'INSERT INTO deposit_sessions (session_id, user_id, booth_id, qr_token_hash, expires_at, status) VALUES (?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY), \'COMPLETED\')'
            );
            $query->execute([$sessionId, $user['user_id'], $booth['booth_id'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $query = $db->prepare(
                'INSERT INTO plastic_deposits (user_id, booth_id, session_id, plastic_weight_kg, plastic_type, tokens_earned, rate_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $query->execute([$user['user_id'], $booth['booth_id'], $sessionId, $weight, $body['plasticType'] ?? 'PET/Mix', $tokensEarned, $tokensPerKg]);

            $fill = $newWeight / $capacity * 100;
            $status = $fill >= 100 ? 'Full' : ($fill >= config_value($db, 'almost_full_threshold_pct', 80) ? 'Almost Full' : 'Available');
            $query = $db->prepare('UPDATE smart_booths SET current_weight_kg = ?, booth_status = ? WHERE booth_id = ?');
            $query->execute([$newWeight, $status, $booth['booth_id']]);
            create_full_booth_pickup(
                $db,
                (int) $booth['booth_id'],
                $booth['company_id'] === null ? null : (int) $booth['company_id'],
                (string) $booth['booth_code'],
                $newWeight,
                $status
            );

            $balance = (int) $lockedUser['total_tokens'] + $tokensEarned;
            $query = $db->prepare('UPDATE users SET total_tokens = ? WHERE user_id = ?');
            $query->execute([$balance, $user['user_id']]);
            $level = update_loyalty($db, (int) $user['user_id']);
            $query = $db->prepare(
                'INSERT INTO wallet_transactions (user_id, transaction_type, tokens_delta, cash_delta, balance_after_tokens, status) VALUES (?, \'Deposit Credit\', ?, 0, ?, \'Completed\')'
            );
            $query->execute([$user['user_id'], $tokensEarned, $balance]);
            $depositId = (int) $db->lastInsertId();
            $db->commit();

            $query = $db->prepare('SELECT COALESCE(SUM(plastic_weight_kg), 0) FROM plastic_deposits WHERE user_id = ?');
            $query->execute([$user['user_id']]);
            respond(200, [
                'success' => true,
                'message' => 'Plastic deposit successfully recorded!',
                'depositId' => $depositId,
                'plasticWeightKg' => $weight,
                'tokensEarned' => $tokensEarned,
                'totalTokens' => $balance,
                'walletBalanceTaka' => $balance / 4,
                'totalPlasticKg' => (float) $query->fetchColumn(),
                'loyaltyLevel' => $level,
            ]);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    if ($path === '/me/transactions' && $method === 'GET') {
        $user = bearer_user($db);
        $query = $db->prepare(
            'SELECT transaction_id AS transactionId, transaction_type AS transactionType, tokens_delta AS tokensDelta,
                    cash_delta AS cashDelta, balance_after_tokens AS balanceAfterTokens, bkash_trx_id AS bkashTrxId,
                    status, transaction_timestamp AS transactionTimestamp
             FROM wallet_transactions WHERE user_id = ? ORDER BY transaction_timestamp DESC'
        );
        $query->execute([$user['user_id']]);
        respond(200, $query->fetchAll());
    }

    if ($path === '/me/withdraw' && $method === 'POST') {
        $user = bearer_user($db);
        $body = request_body();
        $tokens = filter_var($body['tokens'] ?? null, FILTER_VALIDATE_INT);
        $tokensPerTaka = config_value($db, 'tokens_per_taka', 4);
        $minimumTaka = config_value($db, 'min_withdrawal_taka', 100);
        if ($tokens === false || $tokens < $minimumTaka * $tokensPerTaka || $tokens % $tokensPerTaka !== 0) {
            fail(400, 'Withdrawal must be at least ' . ($minimumTaka * $tokensPerTaka) . ' tokens and a multiple of ' . $tokensPerTaka . '.');
        }
        $bkash = trim((string) ($body['bkashNumber'] ?? ''));
        if ($bkash === '') {
            fail(400, 'A bKash number is required.');
        }

        $db->beginTransaction();
        try {
            $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
            if ($idempotencyKey !== '') {
                $query = $db->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key = ?');
                $query->execute([$idempotencyKey]);
                $existing = $query->fetch();
                if ($existing) {
                    if ((int) $existing['user_id'] !== (int) $user['user_id']) {
                        fail(409, 'This idempotency key has already been used.');
                    }
                    $db->commit();
                    respond(200, [
                        'transactionId' => (int) $existing['transaction_id'],
                        'transactionType' => $existing['transaction_type'],
                        'tokensDelta' => (int) $existing['tokens_delta'],
                        'cashDelta' => (float) $existing['cash_delta'],
                        'balanceAfterTokens' => (int) $existing['balance_after_tokens'],
                        'bkashTrxId' => $existing['bkash_trx_id'],
                        'status' => $existing['status'],
                    ]);
                }
            }
            $query = $db->prepare('SELECT total_tokens FROM users WHERE user_id = ? FOR UPDATE');
            $query->execute([$user['user_id']]);
            $balance = (int) $query->fetchColumn();
            if ($balance < $tokens) {
                fail(400, 'Insufficient token balance. Current balance: ' . $balance . ' tokens.');
            }
            $newBalance = $balance - $tokens;
            $cash = $tokens / $tokensPerTaka;
            $transactionCode = 'SIM-' . strtoupper(bin2hex(random_bytes(5)));
            $query = $db->prepare('UPDATE users SET total_tokens = ?, bkash_number = ? WHERE user_id = ?');
            $query->execute([$newBalance, $bkash, $user['user_id']]);
            $query = $db->prepare(
                'INSERT INTO wallet_transactions (user_id, transaction_type, tokens_delta, cash_delta, balance_after_tokens, bkash_trx_id, status, idempotency_key)
                 VALUES (?, \'Cashback Withdrawal\', ?, ?, ?, ?, \'Completed\', ?)'
            );
            $query->execute([$user['user_id'], -$tokens, $cash, $newBalance, $transactionCode, $idempotencyKey !== '' ? $idempotencyKey : null]);
            $transactionId = (int) $db->lastInsertId();
            $db->commit();
            respond(200, [
                'transactionId' => $transactionId,
                'transactionType' => 'Cashback Withdrawal',
                'tokensDelta' => -$tokens,
                'cashDelta' => $cash,
                'balanceAfterTokens' => $newBalance,
                'bkashTrxId' => $transactionCode,
                'status' => 'Completed',
            ]);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    if (preg_match('#^/booths/(\d+)/qr$#', $path, $matches) && $method === 'POST') {
        $boothId = (int) $matches[1];
        $query = $db->prepare('SELECT booth_status FROM smart_booths WHERE booth_id = ?');
        $query->execute([$boothId]);
        $status = $query->fetchColumn();
        if ($status === false) {
            fail(404, 'Booth not found.');
        }
        if (in_array(strtolower((string) $status), ['full', 'under maintenance'], true)) {
            fail(400, 'This booth is not available for deposits.');
        }
        $ttl = config_value($db, 'qr_ttl_seconds', 60);
        $qrToken = bin2hex(random_bytes(24));
        $query = $db->prepare(
            'INSERT INTO booth_qr_tokens (token_hash, booth_id, expires_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND))'
        );
        $query->execute([hash('sha256', $qrToken), $boothId, $ttl]);
        respond(200, ['qrToken' => $qrToken, 'ttlSeconds' => $ttl]);
    }

    if ($path === '/me/deposit-sessions' && $method === 'POST') {
        $user = bearer_user($db);
        $body = request_body();
        $boothId = (int) ($body['boothId'] ?? 0);
        $qrToken = trim((string) ($body['qrToken'] ?? ''));
        if ($boothId < 1 || $qrToken === '') {
            fail(400, 'Booth ID and QR token are required.');
        }
        $query = $db->prepare('SELECT booth_id FROM smart_booths WHERE booth_id = ?');
        $query->execute([$boothId]);
        if ($query->fetchColumn() === false) {
            fail(404, 'Booth not found.');
        }
        $db->beginTransaction();
        $query = $db->prepare(
            'SELECT token_hash FROM booth_qr_tokens WHERE token_hash = ? AND booth_id = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
        );
        $query->execute([hash('sha256', $qrToken), $boothId]);
        if ($query->fetchColumn() === false) {
            $db->rollBack();
            fail(400, 'QR token is invalid, expired, or already used.');
        }
        $sessionId = 'DS-' . strtoupper(bin2hex(random_bytes(6)));
        $ttl = config_value($db, 'qr_ttl_seconds', 60);
        $query = $db->prepare(
            'INSERT INTO deposit_sessions (session_id, user_id, booth_id, qr_token_hash, expires_at, status)
             VALUES (?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND), \'PENDING\')'
        );
        $query->execute([$sessionId, $user['user_id'], $boothId, password_hash($qrToken, PASSWORD_DEFAULT), $ttl]);
        $query = $db->prepare('UPDATE booth_qr_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE token_hash = ?');
        $query->execute([hash('sha256', $qrToken)]);
        $db->commit();
        $query = $db->prepare('SELECT expires_at FROM deposit_sessions WHERE session_id = ?');
        $query->execute([$sessionId]);
        respond(201, [
            'sessionId' => $sessionId,
            'userId' => (int) $user['user_id'],
            'boothId' => $boothId,
            'expiresAt' => $query->fetchColumn(),
            'status' => 'PENDING',
        ]);
    }

    if (preg_match('#^/booths/(\d+)/deposit-sessions/([A-Za-z0-9-]+)/weight$#', $path, $matches) && $method === 'POST') {
        $boothId = (int) $matches[1];
        $sessionId = $matches[2];
        $body = request_body();
        $weight = filter_var($body['weightKg'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($weight === false || $weight <= 0) {
            fail(400, 'Deposit weight must be greater than 0 kg.');
        }

        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM deposit_sessions WHERE session_id = ? AND booth_id = ? FOR UPDATE');
            $query->execute([$sessionId, $boothId]);
            $session = $query->fetch();
            if (!$session || $session['status'] !== 'PENDING') {
                $db->rollBack();
                fail(404, 'Active deposit session not found.');
            }
            if (strtotime($session['expires_at']) <= time()) {
                $query = $db->prepare('UPDATE deposit_sessions SET status = \'EXPIRED\' WHERE session_id = ?');
                $query->execute([$sessionId]);
                $db->commit();
                fail(400, 'Deposit session has expired.');
            }
            $query = $db->prepare('SELECT * FROM smart_booths WHERE booth_id = ? FOR UPDATE');
            $query->execute([$boothId]);
            $booth = $query->fetch();
            if (!$booth || (float) $booth['current_weight_kg'] + $weight > (float) $booth['capacity_kg']) {
                $db->rollBack();
                fail(400, 'Submitted weight exceeds the booth\'s remaining capacity.');
            }
            $query = $db->prepare('SELECT * FROM users WHERE user_id = ? FOR UPDATE');
            $query->execute([$session['user_id']]);
            $user = $query->fetch();
            $tokensPerKg = config_value($db, 'tokens_per_kg', 100);
            $tokensEarned = (int) floor($weight * $tokensPerKg + 0.000001);
            $query = $db->prepare(
                'INSERT INTO plastic_deposits (user_id, booth_id, session_id, plastic_weight_kg, plastic_type, tokens_earned, rate_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $query->execute([$user['user_id'], $boothId, $sessionId, $weight, $body['plasticType'] ?? 'PET/Mix', $tokensEarned, $tokensPerKg]);
            $depositId = (int) $db->lastInsertId();

            $newWeight = (float) $booth['current_weight_kg'] + $weight;
            $fill = $newWeight / (float) $booth['capacity_kg'] * 100;
            $boothStatus = $fill >= 100 ? 'Full' : ($fill >= config_value($db, 'almost_full_threshold_pct', 80) ? 'Almost Full' : 'Available');
            $query = $db->prepare('UPDATE smart_booths SET current_weight_kg = ?, booth_status = ? WHERE booth_id = ?');
            $query->execute([$newWeight, $boothStatus, $boothId]);

            $balance = (int) $user['total_tokens'] + $tokensEarned;
            $query = $db->prepare('UPDATE users SET total_tokens = ? WHERE user_id = ?');
            $query->execute([$balance, $user['user_id']]);
            $level = update_loyalty($db, (int) $user['user_id']);
            $query = $db->prepare('UPDATE deposit_sessions SET status = \'COMPLETED\' WHERE session_id = ?');
            $query->execute([$sessionId]);
            $query = $db->prepare(
                'INSERT INTO wallet_transactions (user_id, transaction_type, tokens_delta, cash_delta, balance_after_tokens, status)
                 VALUES (?, \'Deposit Credit\', ?, 0, ?, \'Completed\')'
            );
            $query->execute([$user['user_id'], $tokensEarned, $balance]);

            create_full_booth_pickup(
                $db,
                $boothId,
                $booth['company_id'] === null ? null : (int) $booth['company_id'],
                (string) $booth['booth_code'],
                $newWeight,
                $boothStatus
            );
            $db->commit();
            respond(201, [
                'depositId' => $depositId,
                'userId' => (int) $user['user_id'],
                'boothId' => $boothId,
                'sessionId' => $sessionId,
                'plasticWeightKg' => $weight,
                'plasticType' => $body['plasticType'] ?? 'PET/Mix',
                'tokensEarned' => $tokensEarned,
                'rateSnapshot' => $tokensPerKg,
                'loyaltyLevel' => $level,
            ]);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    if ($path === '/coupons' && $method === 'GET') {
        $query = $db->query(
            'SELECT coupon_id AS couponId, brand_name AS brandName, promo_code AS promoCode,
                    discount_percentage AS discountPercentage, token_cost AS tokenCost,
                    quantity_available AS quantityAvailable, expiry_date AS expiryDate,
                    terms_and_conditions AS termsAndConditions
             FROM coupons WHERE quantity_available > 0 AND expiry_date > CURRENT_TIMESTAMP ORDER BY coupon_id'
        );
        respond(200, $query->fetchAll());
    }

    if (preg_match('#^/coupons/(\d+)/redeem$#', $path, $matches) && $method === 'POST') {
        $user = bearer_user($db);
        $couponId = (int) $matches[1];
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM coupons WHERE coupon_id = ? FOR UPDATE');
            $query->execute([$couponId]);
            $coupon = $query->fetch();
            if (!$coupon || strtotime($coupon['expiry_date']) <= time() || (int) $coupon['quantity_available'] < 1) {
                fail(400, 'This coupon is expired or unavailable.');
            }
            $query = $db->prepare('SELECT total_tokens FROM users WHERE user_id = ? FOR UPDATE');
            $query->execute([$user['user_id']]);
            $balance = (int) $query->fetchColumn();
            $cost = (int) $coupon['token_cost'];
            if ($balance < $cost) {
                fail(400, 'Insufficient token balance. Requires ' . $cost . ' tokens.');
            }
            $newBalance = $balance - $cost;
            $query = $db->prepare('UPDATE users SET total_tokens = ? WHERE user_id = ?');
            $query->execute([$newBalance, $user['user_id']]);
            $query = $db->prepare('UPDATE coupons SET quantity_available = quantity_available - 1 WHERE coupon_id = ?');
            $query->execute([$couponId]);
            $query = $db->prepare('INSERT INTO user_coupon_redemptions (user_id, coupon_id) VALUES (?, ?)');
            $query->execute([$user['user_id'], $couponId]);
            $redemptionId = (int) $db->lastInsertId();
            $query = $db->prepare(
                'INSERT INTO wallet_transactions (user_id, transaction_type, tokens_delta, cash_delta, balance_after_tokens, status) VALUES (?, \'Coupon Purchase\', ?, 0, ?, \'Completed\')'
            );
            $query->execute([$user['user_id'], -$cost, $newBalance]);
            $db->commit();
            respond(200, [
                'redemptionId' => $redemptionId,
                'couponId' => $couponId,
                'brandName' => $coupon['brand_name'],
                'promoCode' => $coupon['promo_code'],
                'tokensSpent' => $cost,
                'redeemedAt' => date(DATE_ATOM),
            ]);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    if ($path === '/leaderboard' && $method === 'GET') {
        $query = $db->query(
            'SELECT u.user_id AS userId, u.full_name AS fullName,
                    COALESCE(SUM(d.plastic_weight_kg), 0) AS totalPlasticKg,
                    u.loyalty_level AS loyaltyLevel, u.total_tokens AS totalTokens
             FROM users u LEFT JOIN plastic_deposits d ON d.user_id = u.user_id
             WHERE u.role = \'USER\' AND u.status = \'ACTIVE\'
             GROUP BY u.user_id ORDER BY totalPlasticKg DESC, u.user_id ASC'
        );
        $users = $query->fetchAll();
        foreach ($users as &$leader) {
            $leader['userId'] = (int) $leader['userId'];
            $leader['totalPlasticKg'] = (float) $leader['totalPlasticKg'];
            $leader['totalTokens'] = (int) $leader['totalTokens'];
        }
        unset($leader);
        respond(200, $users);
    }

    if ($path === '/booths' && $method === 'GET') {
        $query = $db->query(
            "SELECT booth_id AS boothId, booth_code AS boothCode, location_address AS locationAddress,
                    current_weight_kg AS currentWeightKg, capacity_kg AS capacityKg, booth_status AS boothStatus
             FROM smart_booths WHERE booth_status NOT IN ('Full', 'Under Maintenance') ORDER BY booth_code"
        );
        $booths = $query->fetchAll();
        foreach ($booths as &$booth) {
            $booth['boothId'] = (int) $booth['boothId'];
            $booth['currentWeightKg'] = (float) $booth['currentWeightKg'];
            $booth['capacityKg'] = (float) $booth['capacityKg'];
        }
        unset($booth);
        respond(200, $booths);
    }

    if (str_starts_with($path, '/company/')) {
        $user = bearer_user($db);
        require_role($user, ['COMPANY', 'ADMIN']);
        $companyId = company_id_for_user($db, $user);

        if ($path === '/company/dashboard' && $method === 'GET') {
            $query = $db->prepare('SELECT company_name FROM recycling_companies WHERE company_id = ? AND status = \'ACTIVE\'');
            $query->execute([$companyId]);
            $companyName = $query->fetchColumn();
            $query = $db->prepare('SELECT COUNT(*) FROM smart_booths WHERE company_id = ?');
            $query->execute([$companyId]);
            $boothCount = (int) $query->fetchColumn();
            $query = $db->prepare(
                'SELECT booth_status, COUNT(*) AS boothCount FROM smart_booths WHERE company_id = ? GROUP BY booth_status'
            );
            $query->execute([$companyId]);
            $breakdown = ['Empty' => 0, 'Available' => 0, 'Almost Full' => 0, 'Full' => 0, 'Under Maintenance' => 0];
            foreach ($query->fetchAll() as $row) {
                $breakdown[$row['booth_status']] = (int) $row['boothCount'];
            }
            $query = $db->prepare(
                'SELECT COUNT(*) FROM pickup_requests WHERE company_id = ? AND status IN (\'Pending\', \'Accepted\', \'Vehicle Assigned\', \'On Pickup\')'
            );
            $query->execute([$companyId]);
            $pendingCount = (int) $query->fetchColumn();
            $query = $db->prepare('SELECT COALESCE(SUM(net_weight_kg), 0) FROM collections WHERE company_id = ?');
            $query->execute([$companyId]);
            $collectedKg = (float) $query->fetchColumn();
            $query = $db->prepare(
                'SELECT COALESCE(SUM(net_weight_kg), 0) FROM collections WHERE company_id = ? AND collected_at >= DATE_FORMAT(CURRENT_DATE, \'%Y-%m-01\')'
            );
            $query->execute([$companyId]);
            $monthKg = (float) $query->fetchColumn();
            respond(200, [
                'companyId' => $companyId,
                'companyName' => $companyName,
                'assignedBoothsCount' => $boothCount,
                'totalPlasticCollectedKg' => $collectedKg,
                'thisMonthPlasticCollectedKg' => $monthKg,
                'pickupRequiredCount' => $pendingCount,
                'boothStatusBreakdown' => $breakdown,
            ]);
        }

        if ($path === '/company/booths' && $method === 'GET') {
            $query = $db->prepare(
                'SELECT b.booth_id AS boothId, b.booth_code AS boothCode, b.location_address AS locationAddress,
                        b.capacity_kg AS capacityKg, b.current_weight_kg AS currentWeightKg,
                        b.booth_status AS boothStatus, b.sensor_status AS sensorStatus,
                        b.last_pickup_date AS lastPickupDate,
                        EXISTS(SELECT 1 FROM pickup_requests p WHERE p.booth_id = b.booth_id AND p.status IN (\'Pending\', \'Accepted\', \'Vehicle Assigned\', \'On Pickup\')) AS pickupRequested,
                        (SELECT p.request_code FROM pickup_requests p WHERE p.booth_id = b.booth_id AND p.status IN (\'Pending\', \'Accepted\', \'Vehicle Assigned\', \'On Pickup\') ORDER BY p.created_at DESC LIMIT 1) AS pickupRequestCode
                 FROM smart_booths b WHERE b.company_id = ? ORDER BY b.booth_id'
            );
            $query->execute([$companyId]);
            $booths = $query->fetchAll();
            foreach ($booths as &$booth) {
                $booth['boothId'] = (int) $booth['boothId'];
                $booth['capacityKg'] = (float) $booth['capacityKg'];
                $booth['currentWeightKg'] = (float) $booth['currentWeightKg'];
                $booth['pickupRequested'] = (bool) $booth['pickupRequested'];
            }
            unset($booth);
            respond(200, $booths);
        }

        if (preg_match('#^/company/booths/(\d+)/pickup-requests$#', $path, $matches) && $method === 'POST') {
            $boothId = (int) $matches[1];
            $db->beginTransaction();
            $query = $db->prepare('SELECT * FROM smart_booths WHERE booth_id = ? AND company_id = ? FOR UPDATE');
            $query->execute([$boothId, $companyId]);
            $booth = $query->fetch();
            if (!$booth) {
                $db->rollBack();
                fail(404, 'This booth is not assigned to your company.');
            }
            if ((float) $booth['current_weight_kg'] <= 0) {
                $db->rollBack();
                fail(400, 'This booth has no plastic ready for collection.');
            }
            $query = $db->prepare(
                'SELECT request_code FROM pickup_requests WHERE booth_id = ? AND status IN (\'Pending\', \'Accepted\', \'Vehicle Assigned\', \'On Pickup\') LIMIT 1'
            );
            $query->execute([$boothId]);
            if ($query->fetchColumn() !== false) {
                $db->rollBack();
                fail(409, 'A pickup request is already active for this booth.');
            }
            $requestCode = 'REQ-' . preg_replace('/[^A-Za-z0-9-]/', '', $booth['booth_code']) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $priority = $booth['booth_status'] === 'Full' ? 'HIGH' : 'NORMAL';
            $query = $db->prepare(
                'INSERT INTO pickup_requests (request_code, booth_id, company_id, priority, status, payload_kg_at_request)
                 VALUES (?, ?, ?, ?, \'Pending\', ?)'
            );
            $query->execute([$requestCode, $boothId, $companyId, $priority, $booth['current_weight_kg']]);
            $requestId = (int) $db->lastInsertId();
            notify_company($db, $companyId, 'Pickup requested', $requestCode . ' dispatched for ' . $booth['booth_code'] . '.', 'Pickup');
            $db->commit();
            respond(201, [
                'requestId' => $requestId,
                'requestCode' => $requestCode,
                'boothId' => $boothId,
                'boothCode' => $booth['booth_code'],
                'payloadKg' => (float) $booth['current_weight_kg'],
                'priority' => $priority,
                'status' => 'Pending',
            ]);
        }

        if ($path === '/company/alerts' && $method === 'GET') {
            $query = $db->prepare(
                'SELECT notification_id AS alertId, title, message, category,
                        is_read AS isRead, created_at AS createdAt
                 FROM notifications WHERE recipient_role = \'COMPANY\' AND recipient_id = ?
                 ORDER BY created_at DESC LIMIT 100'
            );
            $query->execute([$companyId]);
            respond(200, $query->fetchAll());
        }

        if (preg_match('#^/company/alerts/(\d+)/read$#', $path, $matches) && $method === 'POST') {
            $query = $db->prepare(
                'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND recipient_role = \'COMPANY\' AND recipient_id = ?'
            );
            $query->execute([(int) $matches[1], $companyId]);
            if ($query->rowCount() !== 1) {
                fail(404, 'Alert not found.');
            }
            respond(200, ['success' => true]);
        }

        if ($path === '/company/pickup-requests' && $method === 'GET') {
            $status = trim((string) ($_GET['status'] ?? ''));
            $db->beginTransaction();
            try {
                $query = $db->prepare(
                    'SELECT booth_id, booth_code, current_weight_kg
                     FROM smart_booths
                     WHERE company_id = ? AND booth_status = \'Full\' AND current_weight_kg > 0
                     FOR UPDATE'
                );
                $query->execute([$companyId]);
                foreach ($query->fetchAll() as $fullBooth) {
                    create_full_booth_pickup(
                        $db,
                        (int) $fullBooth['booth_id'],
                        $companyId,
                        (string) $fullBooth['booth_code'],
                        (float) $fullBooth['current_weight_kg'],
                        'Full'
                    );
                }
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }

            $sql = 'SELECT p.request_id AS requestId, p.request_code AS requestCode, p.status, p.priority,
                           p.payload_kg_at_request AS payloadKg, p.vehicle_id AS vehicleId,
                           p.created_at AS createdAt, p.accepted_at AS assignedAt,
                           p.completed_at AS completedAt,
                           b.booth_code AS boothCode,
                           b.location_address AS locationAddress, b.booth_status AS boothStatus,
                           b.current_weight_kg AS currentWeightKg,
                           v.vehicle_number AS vehicleNumber, v.driver_name AS driverName,
                           cl.net_weight_kg AS collectedKg
                    FROM pickup_requests p JOIN smart_booths b ON b.booth_id = p.booth_id
                    LEFT JOIN vehicles v ON v.vehicle_id = p.vehicle_id
                    LEFT JOIN collections cl ON cl.pickup_request_id = p.request_id
                    WHERE p.company_id = ?';
            $params = [$companyId];
            if ($status !== '') {
                $sql .= ' AND p.status = ?';
                $params[] = $status;
            }
            $sql .= ' ORDER BY p.created_at DESC';
            $query = $db->prepare($sql);
            $query->execute($params);
            $pickups = $query->fetchAll();
            foreach ($pickups as &$pickup) {
                $pickup['requestId'] = (int) $pickup['requestId'];
                $pickup['payloadKg'] = (float) $pickup['payloadKg'];
                $pickup['currentWeightKg'] = (float) $pickup['currentWeightKg'];
                $pickup['collectedKg'] = $pickup['collectedKg'] === null ? null : (float) $pickup['collectedKg'];
                $pickup['vehicleId'] = $pickup['vehicleId'] === null ? null : (int) $pickup['vehicleId'];
            }
            unset($pickup);
            respond(200, $pickups);
        }

        if (preg_match('#^/company/pickup-requests/(\d+)/accept$#', $path, $matches) && $method === 'POST') {
            $query = $db->prepare(
                'UPDATE pickup_requests SET status = \'Accepted\', accepted_at = CURRENT_TIMESTAMP WHERE request_id = ? AND company_id = ? AND status = \'Pending\''
            );
            $query->execute([(int) $matches[1], $companyId]);
            if ($query->rowCount() !== 1) {
                fail(404, 'Pending pickup request not found.');
            }
            $query = $db->prepare('SELECT * FROM pickup_requests WHERE request_id = ?');
            $query->execute([(int) $matches[1]]);
            respond(200, $query->fetch());
        }

        if (preg_match('#^/company/pickup-requests/(\d+)/assign-vehicle$#', $path, $matches) && $method === 'POST') {
            $body = request_body();
            $vehicleId = (int) ($body['vehicleId'] ?? 0);
            $db->beginTransaction();
            $query = $db->prepare(
                'SELECT request_id FROM pickup_requests
                 WHERE request_id = ? AND company_id = ? AND status IN (\'Accepted\', \'Pending\')
                 FOR UPDATE'
            );
            $query->execute([(int) $matches[1], $companyId]);
            if ($query->fetchColumn() === false) {
                $db->rollBack();
                fail(404, 'Pickup request cannot be assigned.');
            }
            $query = $db->prepare(
                'SELECT vehicle_id FROM vehicles
                 WHERE vehicle_id = ? AND company_id = ? AND status = \'Available\' FOR UPDATE'
            );
            $query->execute([$vehicleId, $companyId]);
            if ($query->fetchColumn() === false) {
                $db->rollBack();
                fail(400, 'Available company vehicle not found.');
            }
            $query = $db->prepare(
                'UPDATE pickup_requests
                 SET vehicle_id = ?, status = \'Vehicle Assigned\',
                     accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP)
                 WHERE request_id = ? AND company_id = ? AND status IN (\'Accepted\', \'Pending\')'
            );
            $query->execute([$vehicleId, (int) $matches[1], $companyId]);
            if ($query->rowCount() !== 1) {
                $db->rollBack();
                fail(404, 'Pickup request cannot be assigned.');
            }
            $query = $db->prepare('UPDATE vehicles SET status = \'Assigned\' WHERE vehicle_id = ?');
            $query->execute([$vehicleId]);
            $db->commit();
            $query = $db->prepare('SELECT * FROM pickup_requests WHERE request_id = ?');
            $query->execute([(int) $matches[1]]);
            respond(200, $query->fetch());
        }

        if (preg_match('#^/company/pickup-requests/(\d+)/complete$#', $path, $matches) && $method === 'POST') {
            $body = request_body();
            $requestedWeight = array_key_exists('collectedWeightKg', $body)
                ? filter_var($body['collectedWeightKg'], FILTER_VALIDATE_FLOAT)
                : null;
            if (array_key_exists('collectedWeightKg', $body)
                && ($requestedWeight === false || $requestedWeight <= 0)) {
                fail(400, 'Collected weight must be greater than zero.');
            }
            $db->beginTransaction();
            $query = $db->prepare('SELECT * FROM pickup_requests WHERE request_id = ? AND company_id = ? FOR UPDATE');
            $query->execute([(int) $matches[1], $companyId]);
            $pickup = $query->fetch();
            if (!$pickup || $pickup['status'] !== 'Vehicle Assigned' || $pickup['vehicle_id'] === null) {
                $db->rollBack();
                fail(409, 'Assign a vehicle to this open pickup before completing collection.');
            }
            $query = $db->prepare(
                'SELECT current_weight_kg, capacity_kg, booth_status FROM smart_booths WHERE booth_id = ? AND company_id = ? FOR UPDATE'
            );
            $query->execute([$pickup['booth_id'], $companyId]);
            $booth = $query->fetch();
            if (!$booth) {
                $db->rollBack();
                fail(404, 'Assigned booth not found.');
            }
            $currentWeight = (float) $booth['current_weight_kg'];
            $collectedWeight = round($requestedWeight === null ? $currentWeight : (float) $requestedWeight, 3);
            if ($collectedWeight <= 0) {
                $db->rollBack();
                fail(400, 'Collected weight must be at least 0.001 kg.');
            }
            if ($collectedWeight > $currentWeight + 0.0005) {
                $db->rollBack();
                fail(400, 'Collected weight cannot exceed the plastic currently in the booth.');
            }
            $remainingWeight = round(max(0, $currentWeight - $collectedWeight), 3);
            $fillPercentage = (float) $booth['capacity_kg'] > 0
                ? ($remainingWeight / (float) $booth['capacity_kg']) * 100
                : 0;
            $boothStatus = $booth['booth_status'] === 'Under Maintenance'
                ? 'Under Maintenance'
                : ($remainingWeight <= 0
                    ? 'Empty'
                    : ($fillPercentage >= 100
                        ? 'Full'
                        : ($fillPercentage >= config_value($db, 'almost_full_threshold_pct', 80)
                            ? 'Almost Full'
                            : 'Available')));
            $query = $db->prepare(
                'SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? AND company_id = ? AND status = \'Assigned\' FOR UPDATE'
            );
            $query->execute([$pickup['vehicle_id'], $companyId]);
            if ($query->fetchColumn() === false) {
                $db->rollBack();
                fail(409, 'The vehicle assigned to this pickup is no longer available.');
            }
            $query = $db->prepare(
                'INSERT INTO collections (pickup_request_id, booth_id, company_id, net_weight_kg, plastic_grade) VALUES (?, ?, ?, ?, ?)'
            );
            $query->execute([
                $pickup['request_id'],
                $pickup['booth_id'],
                $companyId,
                $collectedWeight,
                trim((string) ($body['plasticGrade'] ?? 'PET 100% Sorted')),
            ]);
            $collectionId = (int) $db->lastInsertId();
            $query = $db->prepare(
                'UPDATE smart_booths SET current_weight_kg = ?, booth_status = ?, last_pickup_date = CURRENT_TIMESTAMP WHERE booth_id = ?'
            );
            $query->execute([$remainingWeight, $boothStatus, $pickup['booth_id']]);
            $query = $db->prepare('UPDATE pickup_requests SET status = \'Completed\', completed_at = CURRENT_TIMESTAMP WHERE request_id = ? AND status = \'Vehicle Assigned\'');
            $query->execute([$pickup['request_id']]);
            if ($query->rowCount() !== 1) {
                $db->rollBack();
                fail(409, 'Pickup request was already completed.');
            }
            $query = $db->prepare('UPDATE vehicles SET status = \'Available\' WHERE vehicle_id = ? AND company_id = ?');
            $query->execute([$pickup['vehicle_id'], $companyId]);
            notify_company(
                $db,
                $companyId,
                'Pickup completed',
                $pickup['request_code'] . ' collected ' . number_format($collectedWeight, 3) . ' kg. ' .
                    number_format($remainingWeight, 3) . ' kg remains at the booth.',
                'Collection'
            );
            $db->commit();
            $query = $db->prepare('SELECT * FROM collections WHERE collection_id = ?');
            $query->execute([$collectionId]);
            respond(200, $query->fetch());
        }

        if ($path === '/company/vehicles' && $method === 'GET') {
            $query = $db->prepare(
                'SELECT vehicle_id AS vehicleId, vehicle_number AS vehicleNumber,
                        vehicle_type AS vehicleType, driver_name AS driverName,
                        driver_phone AS driverPhone, status
                 FROM vehicles WHERE company_id = ? ORDER BY vehicle_id'
            );
            $query->execute([$companyId]);
            respond(200, $query->fetchAll());
        }

        if ($path === '/company/vehicles' && $method === 'POST') {
            $body = request_body();
            foreach (['vehicleNumber', 'vehicleType', 'driverName', 'driverPhone'] as $field) {
                if (empty($body[$field])) {
                    fail(400, ucfirst($field) . ' is required.');
                }
            }
            $query = $db->prepare(
                'INSERT INTO vehicles (company_id, vehicle_number, vehicle_type, driver_name, driver_phone, status) VALUES (?, ?, ?, ?, ?, \'Available\')'
            );
            $query->execute([$companyId, $body['vehicleNumber'], $body['vehicleType'], $body['driverName'], $body['driverPhone']]);
            $vehicleId = (int) $db->lastInsertId();
            $query = $db->prepare(
                'SELECT vehicle_id AS vehicleId, vehicle_number AS vehicleNumber,
                        vehicle_type AS vehicleType, driver_name AS driverName,
                        driver_phone AS driverPhone, status
                 FROM vehicles WHERE vehicle_id = ? AND company_id = ?'
            );
            $query->execute([$vehicleId, $companyId]);
            respond(201, $query->fetch());
        }

        if ($path === '/company/collections' && $method === 'GET') {
            $query = $db->prepare(
                'SELECT cl.collection_id AS collectionId, cl.pickup_request_id AS pickupRequestId,
                        pr.request_code AS requestCode, b.booth_code AS boothCode,
                        b.location_address AS locationAddress, cl.net_weight_kg AS netWeightKg,
                        cl.plastic_grade AS plasticGrade, cl.collected_at AS collectedAt,
                        v.vehicle_number AS vehicleNumber, v.driver_name AS driverName
                 FROM collections cl JOIN pickup_requests pr ON pr.request_id = cl.pickup_request_id
                 JOIN smart_booths b ON b.booth_id = cl.booth_id
                 LEFT JOIN vehicles v ON v.vehicle_id = pr.vehicle_id
                 WHERE cl.company_id = ? ORDER BY cl.collected_at DESC'
            );
            $query->execute([$companyId]);
            $collections = $query->fetchAll();
            foreach ($collections as &$collection) {
                $collection['collectionId'] = (int) $collection['collectionId'];
                $collection['netWeightKg'] = (float) $collection['netWeightKg'];
            }
            unset($collection);
            respond(200, $collections);
        }
    }

    if (str_starts_with($path, '/admin/')) {
        $admin = bearer_user($db);
        require_role($admin, ['ADMIN']);

        if ($path === '/admin/dashboard/metrics' && $method === 'GET') {
            $metrics = [];
            $metrics['totalUsers'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'USER'")->fetchColumn();
            $metrics['totalCompanies'] = (int) $db->query('SELECT COUNT(*) FROM recycling_companies')->fetchColumn();
            $metrics['totalBooths'] = (int) $db->query('SELECT COUNT(*) FROM smart_booths')->fetchColumn();
            $metrics['totalPlasticCollectedKg'] = (float) $db->query('SELECT COALESCE(SUM(plastic_weight_kg), 0) FROM plastic_deposits')->fetchColumn();
            $metrics['totalTokensIssued'] = (int) $db->query('SELECT COALESCE(SUM(tokens_earned), 0) FROM plastic_deposits')->fetchColumn();
            $metrics['totalCashbackTaka'] = (float) $db->query('SELECT COALESCE(SUM(cash_delta), 0) FROM wallet_transactions WHERE transaction_type = \'Cashback Withdrawal\' AND status = \'Completed\'')->fetchColumn();
            respond(200, $metrics);
        }

             if ($path === '/admin/dashboard/activity' && $method === 'GET') {
                 $limit = min(50, max(1, (int) ($_GET['limit'] ?? 12)));
                 $query = $db->prepare(
                  'SELECT activityType, title, description, createdAt FROM (
                      SELECT \'user\' AS activityType, \'New user registered\' AS title,
                          CONCAT(full_name, \' joined Greenify\') AS description, created_at AS createdAt
                      FROM users WHERE role = \'USER\'
                      UNION ALL
                      SELECT \'deposit\', \'Plastic deposited\',
                          CONCAT(u.full_name, \' deposited \', d.plastic_weight_kg, \' kg at \', b.booth_code), d.deposit_timestamp
                      FROM plastic_deposits d JOIN users u ON u.user_id = d.user_id JOIN smart_booths b ON b.booth_id = d.booth_id
                      UNION ALL
                      SELECT \'cashback\', \'Cashback withdrawn\',
                          CONCAT(u.full_name, \' withdrew ৳\', FORMAT(t.cash_delta, 2)), t.transaction_timestamp
                      FROM wallet_transactions t JOIN users u ON u.user_id = t.user_id
                      WHERE t.transaction_type = \'Cashback Withdrawal\' AND t.status = \'Completed\'
                      UNION ALL
                      SELECT \'company\', \'Company registration submitted\',
                          CONCAT(company_name, \' submitted a registration\'), member_since
                      FROM recycling_companies
                      UNION ALL
                      SELECT \'collection\', \'Booth collection completed\',
                          CONCAT(c.company_name, \' collected \', cl.net_weight_kg, \' kg from \', b.booth_code), cl.collected_at
                      FROM collections cl JOIN recycling_companies c ON c.company_id = cl.company_id
                      JOIN smart_booths b ON b.booth_id = cl.booth_id
                  ) activity ORDER BY createdAt DESC LIMIT ?'
                 );
                 $query->bindValue(1, $limit, PDO::PARAM_INT);
                 $query->execute();
                 respond(200, $query->fetchAll());
             }

        if ($path === '/admin/users' && $method === 'GET') {
            $query = $db->query(
                'SELECT user_id AS userId, full_name AS fullName, phone_number AS phoneNumber, bkash_number AS bkashNumber,
                        address, total_tokens AS totalTokens, loyalty_level AS loyaltyLevel, role, status, created_at AS createdAt FROM users ORDER BY user_id'
            );
            respond(200, $query->fetchAll());
        }

        if (preg_match('#^/admin/users/(\d+)$#', $path, $matches) && $method === 'GET') {
            $userId = (int) $matches[1];
            $query = $db->prepare(
                'SELECT user_id AS userId, full_name AS fullName, phone_number AS phoneNumber, bkash_number AS bkashNumber,
                        address, total_tokens AS totalTokens, loyalty_level AS loyaltyLevel, role, status, created_at AS createdAt
                 FROM users WHERE user_id = ?'
            );
            $query->execute([$userId]);
            $profile = $query->fetch();
            if (!$profile) {
                fail(404, 'User not found.');
            }
            $query = $db->prepare(
                'SELECT deposit_id AS depositId, plastic_weight_kg AS plasticWeightKg, plastic_type AS plasticType,
                        tokens_earned AS tokensEarned, deposit_timestamp AS depositedAt, b.booth_code AS boothCode
                 FROM plastic_deposits d JOIN smart_booths b ON b.booth_id = d.booth_id
                 WHERE d.user_id = ? ORDER BY d.deposit_timestamp DESC LIMIT 10'
            );
            $query->execute([$userId]);
            $profile['recentDeposits'] = $query->fetchAll();
            $query = $db->prepare(
                'SELECT transaction_id AS transactionId, transaction_type AS transactionType, tokens_delta AS tokensDelta,
                        cash_delta AS cashDelta, status, transaction_timestamp AS createdAt
                 FROM wallet_transactions WHERE user_id = ? ORDER BY transaction_timestamp DESC LIMIT 10'
            );
            $query->execute([$userId]);
            $profile['recentTransactions'] = $query->fetchAll();
            respond(200, $profile);
        }

        if ($path === '/admin/collections' && $method === 'GET') {
            $query = $db->query(
                'SELECT cl.collection_id AS collectionId, cl.pickup_request_id AS pickupRequestId,
                        pr.request_code AS requestCode, b.booth_code AS boothCode, b.location_address AS locationAddress,
                        c.company_name AS companyName, cl.net_weight_kg AS netWeightKg,
                        cl.plastic_grade AS plasticGrade, cl.collected_at AS collectedAt
                 FROM collections cl JOIN pickup_requests pr ON pr.request_id = cl.pickup_request_id
                 JOIN smart_booths b ON b.booth_id = cl.booth_id
                 JOIN recycling_companies c ON c.company_id = cl.company_id
                 ORDER BY cl.collected_at DESC LIMIT 200'
            );
            $collections = $query->fetchAll();
            foreach ($collections as &$collection) {
                $collection['collectionId'] = (int) $collection['collectionId'];
                $collection['pickupRequestId'] = (int) $collection['pickupRequestId'];
                $collection['netWeightKg'] = (float) $collection['netWeightKg'];
            }
            unset($collection);
            respond(200, $collections);
        }

        if ($path === '/admin/pickup-requests' && $method === 'GET') {
            $query = $db->query(
                'SELECT pr.request_id AS requestId, pr.request_code AS requestCode, pr.status, pr.priority,
                        pr.payload_kg_at_request AS payloadKg, pr.created_at AS createdAt,
                        b.booth_code AS boothCode, b.location_address AS locationAddress, c.company_name AS companyName
                 FROM pickup_requests pr JOIN smart_booths b ON b.booth_id = pr.booth_id
                 JOIN recycling_companies c ON c.company_id = pr.company_id
                 ORDER BY FIELD(pr.status, \'Pending\', \'Accepted\', \'Vehicle Assigned\', \'Completed\'), pr.created_at DESC LIMIT 200'
            );
            respond(200, $query->fetchAll());
        }

        if ($path === '/admin/booths' && $method === 'GET') {
            $query = $db->query(
                'SELECT b.booth_id AS boothId, b.booth_code AS boothCode, b.location_address AS locationAddress,
                        b.company_id AS companyId, c.company_name AS companyName, b.capacity_kg AS capacityKg,
                        b.current_weight_kg AS currentWeightKg, b.booth_status AS boothStatus, b.sensor_status AS sensorStatus,
                        b.last_pickup_date AS lastPickupDate
                 FROM smart_booths b LEFT JOIN recycling_companies c ON c.company_id = b.company_id ORDER BY b.booth_id'
            );
            $booths = $query->fetchAll();
            foreach ($booths as &$booth) {
                $booth['boothId'] = (int) $booth['boothId'];
                $booth['companyId'] = $booth['companyId'] === null ? null : (int) $booth['companyId'];
                $booth['capacityKg'] = (float) $booth['capacityKg'];
                $booth['currentWeightKg'] = (float) $booth['currentWeightKg'];
            }
            unset($booth);
            respond(200, $booths);
        }

        if ($path === '/admin/booths' && $method === 'POST') {
            $body = request_body();
            foreach (['boothCode', 'locationAddress'] as $field) {
                if (empty($body[$field])) {
                    fail(400, ucfirst($field) . ' is required.');
                }
            }
            $capacity = filter_var($body['capacityKg'] ?? 100, FILTER_VALIDATE_FLOAT);
            if ($capacity === false || $capacity <= 0) {
                fail(400, 'Booth capacity must be greater than zero.');
            }
            $companyId = !empty($body['companyId']) ? (int) $body['companyId'] : null;
            if ($companyId !== null) {
                $query = $db->prepare('SELECT 1 FROM recycling_companies WHERE company_id = ? AND status = \'ACTIVE\'');
                $query->execute([$companyId]);
                if (!$query->fetchColumn()) {
                    fail(400, 'Booths can only be assigned to approved companies.');
                }
            }
            $query = $db->prepare(
                'INSERT INTO smart_booths (booth_code, company_id, location_address, capacity_kg, current_weight_kg, booth_status, sensor_status)
                 VALUES (?, ?, ?, ?, 0, \'Empty\', \'Online\')'
            );
            $query->execute([$body['boothCode'], $companyId, $body['locationAddress'], $capacity]);
            $boothId = (int) $db->lastInsertId();
            if ($companyId !== null) {
                notify_company($db, $companyId, 'New booth assigned', $body['boothCode'] . ' was assigned to your company.', 'Booth');
            }
            $query = $db->prepare('SELECT booth_id AS boothId, booth_code AS boothCode, location_address AS locationAddress, capacity_kg AS capacityKg, current_weight_kg AS currentWeightKg, booth_status AS boothStatus FROM smart_booths WHERE booth_id = ?');
            $query->execute([$boothId]);
            respond(201, $query->fetch());
        }

        if (preg_match('#^/admin/booths/(\d+)$#', $path, $matches) && $method === 'PUT') {
            $body = request_body();
            $boothId = (int) $matches[1];
            $db->beginTransaction();
            try {
                $query = $db->prepare(
                    'SELECT company_id, booth_code, current_weight_kg FROM smart_booths WHERE booth_id = ? FOR UPDATE'
                );
                $query->execute([$boothId]);
                $currentBooth = $query->fetch();
                if (!$currentBooth) {
                    $db->rollBack();
                    fail(404, 'Booth not found.');
                }
                $companyId = !empty($body['companyId']) ? (int) $body['companyId'] : null;
                if ($companyId !== null) {
                    $query = $db->prepare('SELECT 1 FROM recycling_companies WHERE company_id = ? AND status = \'ACTIVE\'');
                    $query->execute([$companyId]);
                    if (!$query->fetchColumn()) {
                        $db->rollBack();
                        fail(400, 'Booths can only be assigned to approved companies.');
                    }
                }
                $boothCode = trim((string) ($body['boothCode'] ?? ''));
                $capacity = max(0.001, (float) ($body['capacityKg'] ?? 100));
                $currentWeight = (float) $currentBooth['current_weight_kg'];
                $requestedStatus = trim((string) ($body['boothStatus'] ?? 'Available'));
                $boothStatus = $requestedStatus === 'Under Maintenance'
                    ? 'Under Maintenance'
                    : ($currentWeight >= $capacity ? 'Full' : $requestedStatus);
                $query = $db->prepare(
                    'UPDATE smart_booths SET booth_code = ?, location_address = ?, capacity_kg = ?, company_id = ?, booth_status = ? WHERE booth_id = ?'
                );
                $query->execute([
                    $boothCode,
                    trim((string) ($body['locationAddress'] ?? '')),
                    $capacity,
                    $companyId,
                    $boothStatus,
                    $boothId,
                ]);
                if ($companyId !== null && (int) ($currentBooth['company_id'] ?? 0) !== $companyId) {
                    notify_company($db, $companyId, 'Booth assigned', $boothCode . ' is now assigned to your company.', 'Booth');
                }
                create_full_booth_pickup(
                    $db,
                    $boothId,
                    $companyId,
                    $boothCode,
                    $currentWeight,
                    $boothStatus
                );
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            respond(200, ['success' => true]);
        }

        if (preg_match('#^/admin/users/(\d+)/toggle-status$#', $path, $matches) && $method === 'POST') {
            $userId = (int) $matches[1];
            $query = $db->prepare("UPDATE users SET status = IF(status = 'ACTIVE', 'SUSPENDED', 'ACTIVE') WHERE user_id = ? AND role <> 'ADMIN'");
            $query->execute([$userId]);
            if ($query->rowCount() !== 1) {
                fail(404, 'User not found or cannot be suspended.');
            }
            $query = $db->prepare('SELECT user_id AS userId, full_name AS fullName, phone_number AS phoneNumber, role, status FROM users WHERE user_id = ?');
            $query->execute([$userId]);
            respond(200, $query->fetch());
        }

        if ($path === '/admin/companies/pending' && $method === 'GET') {
            $query = $db->query("SELECT * FROM recycling_companies WHERE status = 'PENDING' ORDER BY member_since");
            respond(200, $query->fetchAll());
        }

        if ($path === '/admin/companies/active' && $method === 'GET') {
            $query = $db->query(
                "SELECT company_id AS companyId, company_name AS companyName, region
                 FROM recycling_companies WHERE status = 'ACTIVE' ORDER BY company_name"
            );
            respond(200, $query->fetchAll());
        }

        if (preg_match('#^/admin/companies/(\d+)/(approve|reject)$#', $path, $matches) && $method === 'POST') {
            $companyId = (int) $matches[1];
            $status = $matches[2] === 'approve' ? 'ACTIVE' : 'REJECTED';
            $query = $db->prepare("UPDATE recycling_companies SET status = ? WHERE company_id = ? AND status = 'PENDING'");
            $query->execute([$status, $companyId]);
            if ($query->rowCount() !== 1) {
                fail(404, 'Pending company not found.');
            }
            $query = $db->prepare('SELECT * FROM recycling_companies WHERE company_id = ?');
            $query->execute([$companyId]);
            respond(200, $query->fetch());
        }

        if ($path === '/admin/config/economics' && $method === 'POST') {
            $body = request_body();
            $key = trim((string) ($body['key'] ?? ''));
            $value = trim((string) ($body['value'] ?? ''));
            $numericValue = filter_var($value, FILTER_VALIDATE_FLOAT);
            if (!in_array($key, ['tokens_per_kg', 'tokens_per_taka', 'min_withdrawal_taka', 'almost_full_threshold_pct', 'co2_kg_per_plastic_kg'], true) || $numericValue === false || $numericValue <= 0) {
                fail(400, 'A supported economics key and value are required.');
            }
            if (in_array($key, ['tokens_per_kg', 'tokens_per_taka', 'min_withdrawal_taka', 'almost_full_threshold_pct'], true) && floor($numericValue) !== $numericValue) {
                fail(400, 'This economics value must be a whole number.');
            }
            $query = $db->prepare(
                'INSERT INTO system_config (config_key, config_value, description) VALUES (?, ?, \'Updated by administrator\')
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
            );
            $query->execute([$key, $value]);
            respond(200, ['success' => true, 'message' => 'Economics rule updated successfully.']);
        }

        if ($path === '/admin/config/economics' && $method === 'GET') {
            $query = $db->query('SELECT config_key AS configKey, config_value AS configValue, description FROM system_config ORDER BY config_key');
            respond(200, $query->fetchAll());
        }

        if ($path === '/admin/coupons' && $method === 'GET') {
            $query = $db->query(
                'SELECT coupon_id AS couponId, brand_name AS brandName, promo_code AS promoCode,
                        discount_percentage AS discountPercentage, token_cost AS tokenCost,
                        quantity_available AS quantityAvailable, expiry_date AS expiryDate,
                        terms_and_conditions AS termsAndConditions
                 FROM coupons ORDER BY created_at DESC'
            );
            respond(200, $query->fetchAll());
        }

        if ($path === '/admin/coupons' && $method === 'POST') {
            $body = request_body();
            $discount = filter_var($body['discountPercentage'] ?? null, FILTER_VALIDATE_INT);
            $cost = filter_var($body['tokenCost'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($body['quantityAvailable'] ?? null, FILTER_VALIDATE_INT);
            if (empty($body['brandName']) || empty($body['promoCode']) || $discount === false || $discount < 1 || $discount > 100 || $cost === false || $cost < 1 || $quantity === false || $quantity < 0 || empty($body['expiryDate'])) {
                fail(400, 'Provide a brand, unique promo code, valid discount, token cost, quantity, and expiry date.');
            }
            $query = $db->prepare(
                'INSERT INTO coupons (brand_name, promo_code, discount_percentage, token_cost, quantity_available, expiry_date, terms_and_conditions)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $query->execute([$body['brandName'], $body['promoCode'], $discount, $cost, $quantity, $body['expiryDate'], $body['termsAndConditions'] ?? null]);
            respond(201, ['success' => true, 'couponId' => (int) $db->lastInsertId()]);
        }

        if (preg_match('#^/admin/coupons/(\d+)$#', $path, $matches) && $method === 'PUT') {
            $body = request_body();
            $discount = filter_var($body['discountPercentage'] ?? null, FILTER_VALIDATE_INT);
            $cost = filter_var($body['tokenCost'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($body['quantityAvailable'] ?? null, FILTER_VALIDATE_INT);
            if (empty($body['brandName']) || empty($body['promoCode']) || $discount === false || $discount < 1 || $discount > 100 || $cost === false || $cost < 1 || $quantity === false || $quantity < 0 || empty($body['expiryDate'])) {
                fail(400, 'Provide a brand, promo code, valid discount, token cost, quantity, and expiry date.');
            }
            $query = $db->prepare(
                'UPDATE coupons SET brand_name = ?, promo_code = ?, discount_percentage = ?, token_cost = ?,
                        quantity_available = ?, expiry_date = ?, terms_and_conditions = ? WHERE coupon_id = ?'
            );
            $query->execute([$body['brandName'], $body['promoCode'], $discount, $cost, $quantity, $body['expiryDate'], $body['termsAndConditions'] ?? null, (int) $matches[1]]);
            if ($query->rowCount() === 0) {
                $exists = $db->prepare('SELECT 1 FROM coupons WHERE coupon_id = ?');
                $exists->execute([(int) $matches[1]]);
                if (!$exists->fetchColumn()) {
                    fail(404, 'Coupon not found.');
                }
            }
            respond(200, ['success' => true]);
        }

        if (preg_match('#^/admin/coupons/(\d+)$#', $path, $matches) && $method === 'DELETE') {
            $query = $db->prepare('UPDATE coupons SET quantity_available = 0 WHERE coupon_id = ?');
            $query->execute([(int) $matches[1]]);
            if ($query->rowCount() === 0) {
                fail(404, 'Coupon not found.');
            }
            respond(200, ['success' => true, 'message' => 'Coupon archived.']);
        }

        if ($path === '/admin/loyalty-levels' && $method === 'GET') {
            $query = $db->query('SELECT level, min_kg AS minKg, max_kg AS maxKg, benefits, badge FROM loyalty_levels ORDER BY min_kg');
            respond(200, $query->fetchAll());
        }

        if (preg_match('#^/admin/loyalty-levels/(.+)$#', $path, $matches) && $method === 'PUT') {
            $body = request_body();
            $minKg = filter_var($body['minKg'] ?? null, FILTER_VALIDATE_FLOAT);
            $maxKg = filter_var($body['maxKg'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($minKg === false || $maxKg === false || $minKg < 0 || $maxKg <= $minKg || empty($body['benefits'])) {
                fail(400, 'Provide valid non-negative tier bounds and benefits.');
            }
            $query = $db->prepare('UPDATE loyalty_levels SET min_kg = ?, max_kg = ?, benefits = ?, badge = ? WHERE level = ?');
            $query->execute([$minKg, $maxKg, $body['benefits'], $body['badge'] ?? null, rawurldecode($matches[1])]);
            if ($query->rowCount() === 0) {
                $exists = $db->prepare('SELECT 1 FROM loyalty_levels WHERE level = ?');
                $exists->execute([rawurldecode($matches[1])]);
                if (!$exists->fetchColumn()) {
                    fail(404, 'Loyalty tier not found.');
                }
            }
            respond(200, ['success' => true]);
        }

        if ($path === '/admin/campaigns' && $method === 'GET') {
            $query = $db->query(
                'SELECT campaign_id AS campaignId, title, type, description, start_date AS startDate,
                        end_date AS endDate, status, created_at AS createdAt FROM campaigns ORDER BY start_date DESC'
            );
            respond(200, $query->fetchAll());
        }

        if ($path === '/admin/campaigns' && $method === 'POST') {
            $body = request_body();
            foreach (['title', 'type', 'startDate', 'endDate'] as $field) {
                if (empty($body[$field])) {
                    fail(400, ucfirst($field) . ' is required.');
                }
            }
            if (strtotime($body['endDate']) <= strtotime($body['startDate'])) {
                fail(400, 'Campaign end date must be after its start date.');
            }
            $query = $db->prepare(
                'INSERT INTO campaigns (title, type, description, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $query->execute([$body['title'], $body['type'], $body['description'] ?? '', $body['startDate'], $body['endDate'], $body['status'] ?? 'Upcoming']);
            respond(201, ['success' => true, 'campaignId' => (int) $db->lastInsertId()]);
        }

        if (preg_match('#^/admin/campaigns/(\d+)$#', $path, $matches) && $method === 'PUT') {
            $body = request_body();
            $query = $db->prepare(
                'UPDATE campaigns SET title = ?, type = ?, description = ?, start_date = ?, end_date = ?, status = ? WHERE campaign_id = ?'
            );
            $query->execute([
                trim((string) ($body['title'] ?? '')),
                trim((string) ($body['type'] ?? 'Community')),
                trim((string) ($body['description'] ?? '')),
                $body['startDate'] ?? date('Y-m-d H:i:s'),
                $body['endDate'] ?? date('Y-m-d H:i:s', strtotime('+1 day')),
                trim((string) ($body['status'] ?? 'Upcoming')),
                (int) $matches[1],
            ]);
            if ($query->rowCount() === 0) {
                fail(404, 'Campaign not found or unchanged.');
            }
            respond(200, ['success' => true]);
        }

        if (preg_match('#^/admin/campaigns/(\d+)$#', $path, $matches) && $method === 'DELETE') {
            $query = $db->prepare("UPDATE campaigns SET status = 'Archived' WHERE campaign_id = ?");
            $query->execute([(int) $matches[1]]);
            if ($query->rowCount() === 0) {
                fail(404, 'Campaign not found.');
            }
            respond(200, ['success' => true, 'message' => 'Campaign archived.']);
        }

        if ($path === '/admin/reports/analytics' && $method === 'GET') {
            $days = min(365, max(7, (int) ($_GET['days'] ?? 30)));
            $query = $db->query(
                "SELECT DATE(deposit_timestamp) AS date, ROUND(SUM(plastic_weight_kg), 2) AS plasticKg,
                        SUM(tokens_earned) AS tokensIssued, COUNT(*) AS deposits
                 FROM plastic_deposits WHERE deposit_timestamp >= DATE_SUB(CURRENT_DATE, INTERVAL $days DAY)
                 GROUP BY DATE(deposit_timestamp) ORDER BY date"
            );
            $daily = $query->fetchAll();
            foreach ($daily as &$day) {
                $day['plasticKg'] = (float) $day['plasticKg'];
                $day['tokensIssued'] = (int) $day['tokensIssued'];
                $day['deposits'] = (int) $day['deposits'];
            }
            unset($day);
            $query = $db->query(
                'SELECT b.booth_code AS boothCode, b.location_address AS locationAddress,
                        ROUND(COALESCE(SUM(d.plastic_weight_kg), 0), 2) AS plasticKg
                 FROM smart_booths b LEFT JOIN plastic_deposits d ON d.booth_id = b.booth_id
                 GROUP BY b.booth_id ORDER BY plasticKg DESC LIMIT 5'
            );
            $topBooths = $query->fetchAll();
            foreach ($topBooths as &$booth) {
                $booth['plasticKg'] = (float) $booth['plasticKg'];
            }
            unset($booth);
            respond(200, ['days' => $days, 'daily' => $daily, 'topBooths' => $topBooths]);
        }

    }

    fail(404, 'API route not found.');
} catch (Throwable $exception) {
    error_log((string) $exception);
    fail(500, 'The server could not complete the request. Check the PHP log for details.');
}