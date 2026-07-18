# Aike — API Inventory, Mobile API Specs & Database Impact

## 1. Existing endpoints (session-cookie, web-coupled)

The current AJAX endpoints (e.g. `bookings/ajax_fetch_riders.php`, `rider/ajax_workflow_action.php`,
`payments/initialize.php`, `chat/ajax_send_message.php`, …) authenticate with the **PHP session
cookie + CSRF** and sometimes redirect or return HTML. They are **not** directly consumable by a
native app. They are kept as-is for the web client; the mobile app uses a new `/api/**` layer that
**wraps the same underlying functions** in `config/*.php` (no logic duplication).

## 2. Mobile API design (new `logistics-app/api/` namespace)

- **Base:** `/api/v1/**`, JSON only, HTTPS only.
- **Auth:** `Authorization: Bearer <access_token>`. No cookies, no CSRF (bearer tokens are not
  sent automatically by browsers, so CSRF does not apply).
- **Envelope (always):**
  ```json
  { "ok": true, "data": { }, "error": null, "meta": { "requestId": "…" } }
  ```
  Errors: `{ "ok": false, "data": null, "error": { "code": "STRING_CODE", "message": "…", "fields": {} } }`
- **Status codes:** 200/201 success, 400 validation, 401 auth, 403 role, 404, 409 conflict
  (e.g. rider at cap / duplicate), 419 replaced by 401 (no CSRF), 422 domain rule, 429 rate limit,
  503 backend/DB.
- **Idempotency:** unsafe POSTs (create booking, request rider, pay, withdraw) accept
  `Idempotency-Key` header; the server dedupes so an interrupted/retried request never double-acts.
- **Rate limiting:** reuse `rate_limit_attempts`; per-token + per-IP limits on auth and expensive
  endpoints.
- **Validation:** server-side on every field; never trust client role/price/status.
- **Bootstrapping:** a thin `api/bootstrap.php` includes `config/functions.php` + `config/db.php`,
  resolves the bearer token → user, applies role checks, emits the JSON envelope, and calls the
  same business functions the web pages use.

### Auth flow
```
POST /api/v1/auth/login {email,password}          -> {accessToken, refreshToken, user}
POST /api/v1/auth/refresh {refreshToken}           -> {accessToken}
POST /api/v1/auth/logout  (Bearer)                 -> revokes current device token
POST /api/v1/auth/register {...}                   -> account + tokens (idempotent by email)
POST /api/v1/auth/google  {idToken|authCode}       -> tokens (links existing account, no dup)
POST /api/v1/auth/forgot {email}  /auth/reset {token,password}
```
Tokens: access token ~15 min, refresh token ~30 days, both **hashed at rest**, per-device,
revocable (logout, suspension, admin action). Suspension or role change invalidates tokens.

### Representative endpoint specs (full set generated in Phase 3)

**Rider discovery (wraps existing ranking — no re-implementation):**
```
GET /api/v1/bookings/{id}/riders            (sender, owns booking)
-> data.riders: [{ userId, fullName, vehicleType, rating, distanceKm, etaMinutes,
                   suggestedFee, lastSeenSecondsAgo, pricingAvailable }]   // ≤ 10
   data.phase: "online_first" | "full_list"
   data.pricingPending: bool
```
Server reuses `ajax_fetch_riders.php` logic: online-first, top-10, per-rider vehicle pricing,
cached route metrics, stale-location flag. Mobile only renders.

**Pricing estimate (backend-computed):**
```
POST /api/v1/pricing/estimate {pickup:{lat,lng}, dropoff:{lat,lng}, vehicleType}
-> data: { distanceKm, durationMinutes, breakdown:{minimumFee, perKm, multiplier, tax}, total }
```

**Create booking (idempotent):**
```
POST /api/v1/bookings   Header: Idempotency-Key
Body: { pickup, dropoff, vehicleType, items, notes, ... }
-> 201 data.booking (status "submitted")   // retry with same key returns the same booking
```

**Booking transitions (server-validated; no invalid jumps):**
```
POST /api/v1/rider/bookings/{id}/transition { to: "arrived_at_pickup" | "package_received" | "in_transit" | "delivered" }
-> 200 data.booking  | 422 if transition not allowed from current status/role
```

**Payments (secrets stay server-side; no duplicates):**
```
POST /api/v1/payments/init { bookingId }  Header: Idempotency-Key
-> data: { reference, authorizationUrl | accessCode }      // Paystack init on server
POST /api/v1/payments/verify { reference }
-> data: { paymentStatus }                                  // server verifies with Paystack; webhook remains authoritative
```

**Rider location (only while online/active):**
```
POST /api/v1/rider/location { lat, lng, at }   -> 204   // last-wins; ignored if rider offline
```

**Notifications device token:**
```
POST /api/v1/notifications/device { platform:"android"|"ios", token }  -> 204
GET  /api/v1/notifications?cursor=  -> paginated list (source of truth: push_notifications)
POST /api/v1/notifications/{id}/read
```

Geo (`/api/v1/geo/search|reverse|route`) proxy Mapbox so the **secret token never leaves the
server**; results NG-restricted; route uses the rider's actual vehicle profile.

Full CRUD/lifecycle endpoints for every parity-matrix row are specified in Phase 3 before any
screen consumes them (spec: define → implement → auth → validate → rate-limit → document → test).

## 3. Database impact assessment

**No mobile-only database.** Same MariaDB. Additive, idempotent migrations only (module17+):

| Change | Purpose | Risk |
|---|---|---|
| `api_tokens` (id, user_id, token_hash, type[access/refresh], device_label, platform, expires_at, revoked_at, created_at, last_used_at) | Mobile bearer/refresh auth, per-device revocation | New table; no web impact |
| `device_tokens` (id, user_id, platform, token, created_at, last_seen_at, unique(token)) | FCM/APNs push targeting | New table; web push (`push_subscriptions`) untouched |
| `idempotency_keys` (key_hash, user_id, endpoint, response_hash, created_at, unique) | Duplicate-submission protection for unsafe POSTs | New table; GC'd by existing housekeeping |
| (optional) `bookings` index review for `/api` list filters | Pagination performance | Index-only, additive |

Existing tables (`users`, `bookings`, `booking_payments`, `wallet_transactions`,
`withdrawal_requests`, `rider_profiles`, `messages`, `ratings`, `complaints`, `push_notifications`,
`rate_limit_attempts`, `route_cache`, …) are the shared source of truth and are **not** duplicated
or modified destructively. Mobile reads/writes them **only** through `/api/**`.

## 4. Security checklist for the API layer
Bearer tokens (hashed at rest, short-lived access + revocable refresh) · per-request role +
ownership checks (IDOR protection: every `{id}` verified against the caller) · server-side
validation of every field · idempotency on unsafe writes · rate limiting · HTTPS only · no secrets
on device · secure multipart validation for KYC uploads · audit logging of mobile-origin actions ·
token revocation on logout/suspension/role-change · never trust client-sent role/price/status.
