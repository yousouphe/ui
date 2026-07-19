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

### Auth & profile
| Method & path | Auth | Purpose |
|---|---|---|
| `GET /health` | none | 204 connectivity probe (mobile `useConnectivity`) |
| `POST /auth/login` | none | `{email,password,platform?,deviceLabel?}` → tokens + user. Rate-limited by IP + email. |
| `POST /auth/register` | none | `{fullName,email,phone,password,role,vehicleType?}` → tokens + user (201). 409 if email taken; riders get a `rider_profiles` row pending KYC. |
| `POST /auth/refresh` | none | `{refreshToken}` → new access token (rotated within the device family). |
| `POST /auth/logout` | bearer | Revokes the whole device token family → 204. |
| `GET /profile` | bearer | Current user profile. |

### Sender
| Method & path | Auth | Purpose |
|---|---|---|
| `POST /pricing/estimate` | sender | Backend-computed price (`cached_route_metrics` + `calculate_delivery_price`; **never a client formula**). |
| `POST /geo/route` | any | Route distance/duration via the backend Mapbox (secret token stays server-side). |
| `GET /bookings?filter=active\|unpaid\|history&before=` | sender | Own bookings, paginated (`meta.cursor`). |
| `POST /bookings` | sender | Create a booking. **Idempotency-Key** header → interrupted retries never double-create. Price computed server-side; unroutable pair 422, transient failure creates unpriced + notifies admins. |
| `GET /bookings/{id}` | sender | Own booking (IDOR-guarded: other users get 404). |
| `POST /bookings/{id}/cancel` | sender | Cancel with reason. Same rules as web: not after payment or handover, only `matched`/`accepted`/`arrived_at_pickup`. |
| `GET /bookings/{id}/track` | sender | Status + rider position with `lastSeenSecondsAgo` (never presents a stale fix as live). |

### Rider
| Method & path | Auth | Purpose |
|---|---|---|
| `GET /rider/profile` | rider | Profile + vehicle + availability + KYC status. |
| `POST /rider/status` | rider | Set `available\|busy\|offline`. Going **available** is gated on KYC approval. |
| `POST /rider/location` | rider | Push a fix (Nigeria-bounds validated; deduped ≥55 m / ≥15 s / status-change) → 204. |
| `GET /rider/offers` | rider | Pending delivery requests for this rider. |
| `POST /rider/bookings/{id}/transition` | rider | `{to}` — canonical map `arrived_at_pickup←matched\|accepted`, `package_received←arrived_at_pickup`, `delivered←package_received\|in_transit`; invalid jumps 422; notifies sender. |
| `GET /rider/bookings?filter=active\|pending\|completed\|cancelled` | rider | The rider's jobs. |
| `GET /rider/wallet` | rider | Balance, available balance, and ledger. |

### Notifications
| Method & path | Auth | Purpose |
|---|---|---|
| `POST /notifications/device` | bearer | Register/refresh an FCM/APNs device token → 204. |
| `GET /notifications?before=` | bearer | Notification history, paginated. |
| `POST /notifications/{id}/read` | bearer | Mark read (ownership enforced) → 204. |

### Account, post-delivery, discovery, wallet & payments
| Method & path | Auth | Purpose |
|---|---|---|
| `PATCH /profile` | bearer | Update name / phone. |
| `POST /auth/forgot` | none | Rate-limited; always a generic response (no email enumeration). |
| `POST /auth/reset` | none | Reset via emailed token; single-use, 30-min expiry; revokes existing mobile sessions. |
| `POST /bookings/{id}/rating` | sender | Rate a **delivered** booking 1-5 (one per booking; updates rider average). |
| `POST /complaints` | sender | Report a problem on a delivered booking (category-validated; notifies admins). |
| `GET /bookings/{id}/riders` | sender | Ranked rider discovery (mirrors `ajax_fetch_riders`: top-10, per-rider vehicle pricing, `lastSeenSecondsAgo`, `pricingPending`). |
| `GET /rider/banks` | rider | Paystack bank list. |
| `POST /rider/withdrawals` | rider | Request a payout. **Transactional** (row-locked available balance → no double-spend) + `Idempotency-Key`. |
| `POST /payments/init` | sender | Initialise a Paystack charge for a booking (secret key stays server-side; returns reference + access code). `Idempotency-Key`. |
| `POST /payments/verify` | sender | Verify a payment server-side and reconcile (webhook remains authoritative). |

Geocoding (address search / reverse) is done **on the device** with the **public** Mapbox token
(safe by design, same as the web), so it needs no backend endpoint. Only the secret-token
Directions/pricing is server-side (`/pricing/estimate`, `/geo/route`).

## Not yet implemented (dedicated follow-up)
Google OAuth (`/auth/google` — native PKCE / backend code-exchange) and rider KYC document
**upload** (multipart). Both wrap existing backend logic and are the last API items before the
sender/rider apps consume everything.

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
