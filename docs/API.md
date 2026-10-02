# Greenify PHP API (`/api/v1`)

Base URL: `http://127.0.0.1:8000/api/v1`. Responses are JSON. Protected routes require `Authorization: Bearer <accessToken>`.

## Health

- `GET /health`: returns `200` when MySQL is reachable, or `503` when it is not.

## Authentication

- `POST /auth/otp/send`: `{ "phoneNumber": "+8801711111111", "purpose": "REGISTER" }`. Development OTP is `123456`.
- `POST /auth/otp/verify`: `{ "phoneNumber": "+8801711111111", "code": "123456", "purpose": "REGISTER" }`.
- Password reset uses `POST /auth/otp/send` and `POST /auth/otp/verify` with `"purpose": "RESET"`, followed by `POST /auth/password/reset` with the returned `resetToken`, `phoneNumber`, `password`, and `confirmPassword`. Reset codes and tokens expire after 10 minutes and can only be used once.
- `POST /auth/register/user`: requires `fullName`, `phoneNumber`, `password`, `confirmPassword`, and `otpCode`; returns access and refresh tokens.
- `POST /auth/register/company`: creates a company account pending admin approval.
- `POST /auth/login`: `{ "username": "+8801711111111", "password": "..." }`.
- `POST /auth/refresh`: `{ "refreshToken": "..." }`.

## Citizen

- `GET /economics`: active public token reward, cashback conversion, and minimum withdrawal settings.
- `GET /booths`: booths currently open for citizen deposits.
- `GET /me/dashboard`: token balance, plastic total, deposit count, loyalty level, economics, and estimated CO2 offset.
- `POST /me/deposit/manual`: `{ "weightKg": 1.5, "plasticType": "PET/Mix", "boothId": 1 }`.
- `GET /me/transactions`: wallet ledger.
- `POST /me/withdraw`: `{ "tokens": 400, "bkashNumber": "+8801711111111" }`; tokens must be a multiple of four and at least 400. `Idempotency-Key` is optional.
- `GET /coupons`: currently available coupons.
- `POST /coupons/{couponId}/redeem`: redeem a coupon using the authenticated account.
- `GET /leaderboard`: active citizen accounts ranked by deposited plastic weight.

## Recycling Company

Company bearer token required. Registration remains pending until an administrator approves it.

- `GET /company/dashboard`, `GET /company/booths`, `GET /company/collections`, `GET /company/alerts`.
- When a user deposit fills a booth assigned to an approved company, the API automatically creates one high-priority pending pickup request and notifies the company. The pickup list also reconciles already-full booths that do not yet have an active request. The recycler can use `POST /company/booths/{id}/pickup-requests` to request pickup before a booth is full.
- `GET /company/pickup-requests?status=Pending`.
- `POST /company/pickup-requests/{id}/accept`.
- `POST /company/pickup-requests/{id}/assign-vehicle`: `{ "vehicleId": 1 }`.
- `POST /company/pickup-requests/{id}/complete`: `{ "plasticGrade": "PET 100% Sorted" }` records a full collection of the booth's current weight; an optional `collectedWeightKg` may be supplied for a partial collection. The operation updates the booth weight and status, returns the assigned vehicle to the available fleet, and writes the collection to company and admin history.
- `POST /company/alerts/{id}/read`.
- `GET /company/vehicles`, `POST /company/vehicles`: requires `vehicleNumber`, `vehicleType`, `driverName`, and `driverPhone`. Available vehicles can be assigned to a pickup; the associated driver is recorded in collection history.

## Administration

Administrator bearer token required.

- `GET /admin/dashboard/metrics`, `GET /admin/dashboard/activity`.
- `GET /admin/users`, `GET /admin/users/{id}`, `POST /admin/users/{id}/toggle-status`.
- `GET /admin/booths`, `POST /admin/booths`, `PUT /admin/booths/{id}`.
- `GET /admin/collections`, `GET /admin/pickup-requests`.
- `GET /admin/companies/pending`, `GET /admin/companies/active`, `POST /admin/companies/{id}/approve`, `POST /admin/companies/{id}/reject`.
- `GET /admin/config/economics`, `POST /admin/config/economics`.
- `GET /admin/coupons`, `POST /admin/coupons`, `PUT /admin/coupons/{id}`, `DELETE /admin/coupons/{id}` (archive).
- `GET /admin/loyalty-levels`, `PUT /admin/loyalty-levels/{level}`.
- `GET /admin/campaigns`, `POST /admin/campaigns`, `PUT /admin/campaigns/{id}`, `DELETE /admin/campaigns/{id}` (archive).
- `GET /admin/reports/analytics?days=30`.

New booths are unassigned by default. Admin may assign or unassign them using `companyId` on booth create/update; only active, approved recycling companies are accepted. Recycler booth and dashboard routes return only booths explicitly assigned to the authenticated company.

## Booth Hardware

- `POST /booths/{id}/qr`: issue a short-lived QR token.
- `POST /me/deposit-sessions`: `{ "boothId": 1, "qrToken": "..." }`; requires a citizen bearer token and consumes the QR token once.
- `POST /booths/{id}/deposit-sessions/{sessionId}/weight`: `{ "weightKg": 1.5, "plasticType": "PET/Mix" }`; records the deposit, credits tokens, updates booth fill, and can create a pickup request.

## Notes

- OTP is fixed to `123456` for development only.
- Tokens are stored as SHA-256 hashes in `refresh_tokens`; passwords use PHP's `password_hash`.
- bKash withdrawals are simulated and recorded in the wallet ledger; no live payment gateway is connected.
- Admin and recycler views are connected to their database-backed routes.
- Errors use `{ "success": false, "message": "..." }`.
