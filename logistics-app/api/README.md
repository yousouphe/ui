# Aike Mobile API (`/api/v1`) — Phase 3

Stateless JSON API for the mobile app. Bearer tokens (no cookies, no CSRF). Every endpoint
delegates trusted decisions to the same `config/*.php` helpers the web app uses — **no business
logic is duplicated and no secrets are exposed**. See `mobile/docs/04-api-inventory-and-specs.md`
for the full plan; this documents what is **implemented so far**.

## Conventions
- Base path: `/api/v1`. All responses use the envelope `{ ok, data, error, meta }`.
- Auth: `Authorization: Bearer <accessToken>`. Access token TTL 15 min; refresh 30 days.
- Errors carry a stable `error.code` (e.g. `UNAUTHENTICATED`, `FORBIDDEN`, `VALIDATION`,
  `INVALID_CREDENTIALS`, `RATE_LIMITED`, `NO_ROUTE`, `NOT_FOUND`).
- Routing: `api/.htaccess` sends everything to `api/index.php`.

## Implemented endpoints

| Method & path | Auth | Purpose |
|---|---|---|
| `GET /health` | none | 204 connectivity probe (mobile `useConnectivity`) |
| `POST /auth/login` | none | `{email,password,platform?,deviceLabel?}` → `{accessToken,refreshToken,expiresInSeconds,user}`. Rate-limited by IP + email (same as web login). |
| `POST /auth/refresh` | none | `{refreshToken}` → `{accessToken,expiresInSeconds}`. Rotates the access token within the device token family. |
| `POST /auth/logout` | bearer | Revokes the whole device token family → 204. |
| `GET /profile` | bearer | Current user profile. |
| `POST /pricing/estimate` | bearer (sender) | `{pickup,dropoff,vehicleType}` → backend-computed price (reuses `cached_route_metrics` + `calculate_delivery_price`; **never a client formula**). |
| `GET /bookings?filter=active\|unpaid\|history&before=` | bearer (sender) | The caller's own bookings, paginated (cursor in `meta.cursor`). |

## Security
- Tokens stored **hashed** (`api_tokens`); suspended/inactive accounts are rejected even with a
  live token; role + ownership checked server-side on every request.
- Idempotency helpers (`api_idempotency_*`, `idempotency_keys` table) are in place for the unsafe
  writes added from Phase 5 (create booking, request, pay, withdraw).
- Migration: `sql/module17_mobile_api_migration.sql` (`api_tokens`, `device_tokens`,
  `idempotency_keys`) — additive and idempotent.

## Next (Phase 3 continued / Phase 5–7)
Register, Google OAuth, forgot/reset, geo search/reverse/route (Mapbox proxy — secret stays
server-side), rider discovery, create booking, transitions, payments (init/verify), rider
availability/location/offers/wallet/withdrawals, notifications device tokens + list. Each ships
with validation, IDOR/role checks, rate limits, idempotency where needed, and tests.
