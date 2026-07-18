# Aike — Feature-Parity Matrix (mandatory gate)

Every web feature is listed with its mobile plan. **No feature is dropped**; any not shipped on
mobile is explicitly "web-only (retained)" with a reason, never silently removed. Columns:

- **Status** = current web state.
- **Mobile** = how it appears on mobile (Build / Reuse-via-API / Web-only-retained / Deferred-phase).
- **API** = the `/api/**` endpoint(s) required (● new, ○ wraps existing logic). Specs in doc 04.
- **Offline** = mobile offline behaviour (Cache-read / Queue-safe / Online-only).
- **Test** = acceptance check.

Legend for Status: ✅ functional · ◐ partial · ⚠ web-specific.

## A. Authentication & account

| Web Feature | Role | Status | Mobile | API | Offline | Test |
|---|---|---|---|---|---|---|
| Register (email/password) | Sender/Rider | ✅ | Build | ● `POST /api/auth/register` | Online-only | New account created, token returned, no dup on retry |
| Login | All | ✅ | Build | ● `POST /api/auth/login` | Online-only | Valid creds → tokens; invalid → 401; rate-limited |
| Google OAuth sign-in | Sender/Rider | ✅ | Build (native PKCE / backend code-exchange) | ● `POST /api/auth/google` | Online-only | Existing Google user signs in, **no duplicate account** |
| Forgot / reset password | All | ✅ | Build | ● `POST /api/auth/forgot`, `POST /api/auth/reset` | Online-only | Email sent; token resets; expired token rejected |
| Complete profile (post-OAuth) | Sender/Rider | ✅ | Build | ● `POST /api/profile/complete` | Online-only | Gate enforced until completed |
| Logout / token revoke | All | ✅ | Build | ● `POST /api/auth/logout` | Queue-safe | Token invalidated server-side |
| Token refresh | All | (new) | Build | ● `POST /api/auth/refresh` | Online-only | Expired access token refreshes; revoked refresh fails |
| Locale (en/ha) | All | ✅ | Build | ○ `GET /api/i18n/{locale}` or bundle keys | Cache-read | UI switches; persists |

## B. Sender

| Web Feature | Role | Status | Mobile | API | Offline | Test |
|---|---|---|---|---|---|---|
| Manage profile (name, phone, avatar) | Sender | ✅ | Build | ● `GET/PATCH /api/profile` | Cache-read | Edits persist, reflected on web |
| Address search (Nigeria, as-you-type) | Sender | ✅ | Build | ● `GET /api/geo/search?q=` (proxies Mapbox) | Online-only | NG-restricted suggestions; streets/estates/landmarks |
| Use current location | Sender | ✅ | Build (native GPS) | ○ reverse via `GET /api/geo/reverse` | Online-only | Permission denied handled; coarse fallback |
| Pick location on map / adjust pin | Sender | ✅ | Build | ○ `GET /api/geo/reverse` | Online-only | Pin → address; invalid coords rejected |
| Route preview + distance/ETA | Sender | ✅ | Build | ● `POST /api/geo/route` (server Mapbox Directions) | Online-only | Route drawn; distance/ETA per vehicle |
| Select vehicle type + fee preview | Sender | ✅ | Build | ● `POST /api/pricing/estimate` | Online-only | Backend price shown; no client formula |
| Create delivery / booking | Sender | ✅ | Build | ● `POST /api/bookings` | Queue-safe (idempotency key) | Booking created once; retry-safe |
| Rider discovery (online-first, top-10) | Sender | ✅ | Reuse-via-API | ● `GET /api/bookings/{id}/riders` | Online-only | ≤10 riders; per-rider fee; stale-location flagged |
| Rider details for decision | Sender | ✅ | Build | (same) | Online-only | Distance, vehicle, rating, ETA, last-seen shown |
| Send request to rider / negotiate | Sender | ✅ | Reuse-via-API | ● `POST /api/bookings/{id}/request` | Queue-safe | Request sent; cap enforced (409 at limit) |
| Edit order details / change delivery addr + reprice | Sender | ✅ | Build | ● `PATCH /api/bookings/{id}` | Online-only | Allowed pre-handover; reprice correct |
| Cancel booking (rules) | Sender | ✅ | Build | ● `POST /api/bookings/{id}/cancel` | Queue-safe | Blocked after handover |
| Track rider + delivery (live) | Sender | ✅ | Build | ● `GET /api/bookings/{id}/track` | Online-only | Live position; not stale-as-live |
| Public recipient tracking link | Recipient | ✅ | Web-only-retained (share link) | ○ existing `ajax_public_track` | n/a | Link opens web tracker |
| Chat with rider | Sender | ✅ | Build | ● `GET/POST /api/bookings/{id}/messages` | Cache-read history | Send/receive; tick states |
| Call rider (in-app) | Sender | ◐ | Deferred-phase 7 (gate on infra) | ● `POST /api/bookings/{id}/call` (masking) | Online-only | Only if infra exists; else dialler |
| Call rider (device dialler) | Sender | ✅ | Build | ○ number via booking payload (masked if configured) | Online-only | Dialler opens; number masked per rule |
| Notifications (booking/delivery) | Sender | ✅ | Build (FCM/APNs) | ● device-token + `GET /api/notifications` | Cache-read | Deep-links to screen; no dupes |
| Active / unpaid / history orders | Sender | ✅ | Build | ● `GET /api/bookings?filter=` | Cache-read | Correct buckets; paginated |
| Make payment (Paystack) | Sender | ✅ | Build | ● `POST /api/payments/init` + verify | Online-only | Charge → verify server-side; **no dup on interruption** |
| Confirm delivery completion | Sender | ✅ | Build | ● transition endpoint | Queue-safe | Only valid transition |
| Rate / review rider | Sender | ✅ | Build | ● `POST /api/bookings/{id}/rating` | Queue-safe | One rating; reflected on web |
| Report issue / support | Sender | ✅ | Build | ● `POST /api/complaints` | Queue-safe | Complaint recorded |
| Receipts / transaction records | Sender | ✅ | Build | ● `GET /api/payments?...` | Cache-read | History matches web |
| Rebook a past order | Sender | ✅ | Build | ● `POST /api/bookings/{id}/rebook` | Online-only | Prefilled new booking |

## C. Rider

| Web Feature | Role | Status | Mobile | API | Offline | Test |
|---|---|---|---|---|---|---|
| Register + onboarding | Rider | ✅ | Build | ● auth + `POST /api/rider/kyc` | Online-only | Onboarding gate enforced |
| Submit identity/vehicle/verification (KYC) | Rider | ✅ | Build (secure upload) | ● `POST /api/rider/kyc` (multipart) | Online-only | Docs uploaded; validated; not cached locally |
| Manage rider profile / vehicle type | Rider | ✅ | Build | ● `GET/PATCH /api/rider/profile` | Cache-read | Vehicle change persists |
| Set availability (online/offline) | Rider | ✅ | Build | ● `POST /api/rider/status` | Queue-safe | Toggle reflected; gated on KYC approval |
| Share location while online/active | Rider | ✅ | Build (background loc) | ● `POST /api/rider/location` | Queue-safe (last-wins) | Only when online/active; battery-efficient |
| Receive nearby requests | Rider | ✅ | Build | ● `GET /api/rider/offers` + push | Cache-read | New offers appear; push wakes app |
| View pickup/delivery details | Rider | ✅ | Build | ○ booking payload | Cache-read | Full trip details |
| View suggested fee | Rider | ✅ | Reuse-via-API | ○ from offer payload | Cache-read | Backend fee for rider's vehicle |
| Accept / reject / negotiate | Rider | ✅ | Build | ● `POST /api/rider/offers/{id}/{accept\|reject}` | Queue-safe | Cap enforced; invalid transition blocked |
| Navigate to pickup/delivery | Rider | ✅ | Build (deep-link to maps) | ○ coords from booking | Cache-read | Opens native nav |
| Contact sender (in-app / dialler) | Rider | ◐/✅ | Build (dialler; in-app deferred) | as sender-side | Online-only | Dialler works; masking honoured |
| Update delivery status (arrive/pickup/deliver) | Rider | ✅ | Build | ● `POST /api/rider/bookings/{id}/transition` | Queue-safe | Server validates each step |
| Confirm payment received | Rider | ✅ | Build | ● `POST /api/rider/bookings/{id}/confirm-payment` | Queue-safe | Keeps order active until confirmed |
| Active / pending / completed / cancelled jobs | Rider | ✅ | Build | ● `GET /api/rider/bookings?filter=` | Cache-read | Correct buckets |
| View earnings / wallet ledger | Rider | ✅ | Build | ● `GET /api/rider/wallet` | Cache-read | Balance + ledger match web |
| Add/verify bank account | Rider | ✅ | Build | ● `POST /api/rider/bank` + `POST /api/rider/bank/verify` | Online-only | Paystack name resolve; saved |
| Request withdrawal | Rider | ✅ | Build | ● `POST /api/rider/withdrawals` | Queue-safe (idempotency) | Transactional; no double-spend |
| Track withdrawal status | Rider | ✅ | Build | ● `GET /api/rider/withdrawals` | Cache-read | Status updates (webhook-driven) |
| Notifications | Rider | ✅ | Build (FCM/APNs) | as sender-side | Cache-read | Role-correct; deep-links |
| Report problems / support | Rider | ✅ | Build | ● `POST /api/complaints` | Queue-safe | Recorded |
| Training gate | Rider | ✅ | Build | ● `GET/POST /api/rider/training` | Cache-read | Gate enforced |

## D. Admin / operations (web-first)

Per spec, admin UI **remains web**; mobile must still integrate with all admin-driven status
changes. Each row is **Web-only-retained** unless a genuine mobile need appears.

| Web Feature | Role | Status | Mobile | Integration requirement |
|---|---|---|---|---|
| User management / role change / suspend | Admin | ✅ | Web-only-retained | Mobile respects suspended accounts (token invalidated) |
| Rider management / KYC review | Admin | ✅ | Web-only-retained | Mobile shows KYC status set by admin |
| Booking management / manual match / price override | Admin | ✅ | Web-only-retained | Mobile reflects admin assignment + override price |
| Delivery monitoring | Admin | ✅ | Web-only-retained | Same booking source of truth |
| Payment management / reconciliation | Admin | ✅ | Web-only-retained | Mobile payments reconcile via same records |
| Withdrawal approval / transfer status | Admin | ✅ | Web-only-retained | Rider mobile sees status changes |
| Dispute resolution | Admin | ✅ | Web-only-retained | Mobile complaint feeds same queue |
| Pricing / vehicle-type config | super_admin | ✅ | Web-only-retained | Mobile reads config, never edits |
| Event / audit log | super_admin | ✅ | Web-only-retained | Mobile actions logged server-side |
| Reports / analytics | Admin | ✅ | Web-only-retained | — |
| Notification management | Admin | ✅ | Web-only-retained | Mobile receives resulting notifications |
| Service-area / system config | Admin | (as exists) | Web-only-retained | Mobile respects configured limits |

> Service-area management, fraud/abuse controls, and reports/analytics: verify exact web
> coverage during Phase 2; where a control exists it stays web-side and mobile simply honours the
> resulting server decisions (suspension, area limits, price). Where a control is only implicit,
> it is flagged for review, not dropped.

## E. Cross-cutting

| Feature | Status | Mobile | Notes |
|---|---|---|---|
| Offline shell + branded splash | ✅ (web PWA) | Build (native) | Mirrors `offline.html`/`config/pwa.php`: Aike wordmark, loading, rotating sender/rider messages, auto-recovery, retry |
| Web Push / VAPID | ✅ | Replace with FCM/APNs | Keep `push_notifications` records; add `device_tokens` table |
| CSRF | ✅ (web) | N/A on mobile | Bearer-token API doesn't use cookie CSRF |
| Rate limiting | ✅ | Reuse + extend to `/api` | Same `rate_limit_attempts` table |
| Audit logging | ✅ | Reuse | Mobile-origin actions logged too |
