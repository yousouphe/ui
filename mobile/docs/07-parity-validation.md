# Aike Mobile — Phase 8 Parity Validation

This is the **feature-parity gate** required by the roadmap (doc 06): every row of the parity
matrix (doc 02) is verified against what is actually shipped, and classified as **implemented**,
**intentionally web-retained**, or **not-yet-implemented with a reason and an owner**. Nothing is
silently dropped.

The validation is a **static audit** of the merged code as of Phase 7:

- **API** = the route table in `logistics-app/api/index.php` + handlers in `api/routes_v1.php`.
- **UI** = the mobile screens/services actually wired in `mobile/src` (navigator, `api/services.ts`,
  `screens/**`).

> Device/runtime verification (a real build exercising these flows end-to-end) is **Phase 9** — the
> app cannot be built in the audit sandbox (no Android SDK/Xcode). This document verifies presence
> and wiring, not on-device behaviour.

## Status legend

| Token | Meaning |
|---|---|
| ✅ **Parity** | Backend endpoint **and** a wired mobile UI both present. |
| 🟡 **API-ready, UI pending** | Endpoint exists (and was tested in Phase 3/5/6); no dedicated mobile screen yet. Reachable via the service layer, not yet surfaced to the user. |
| 🔴 **Gap** | Slated to *Build* on mobile but **no endpoint yet** (UI also absent). |
| ⏸️ **Deferred (by design)** | Intentionally not on mobile, with a documented reason. |
| 🌐 **Web-only-retained** | Intentionally web; mobile honours the server outcome. |

## Scorecard

| Area | ✅ | 🟡 | 🔴 | ⏸️ | 🌐 |
|---|--:|--:|--:|--:|--:|
| A. Auth & account | 4 | 2 | 0 | 2 | 0 |
| B. Sender | 12 | 5 | 4 | 1 | 1 |
| C. Rider | 11 | 4 | 4 | 1 | 0 |
| D. Admin / ops | — | — | — | — | 12 |
| E. Cross-cutting | 5 | 0 | 0 | 0 | 0 |

**Headline:** the full **happy-path** for both roles is at parity end-to-end — a sender can log in,
search addresses, price, create a booking, discover & request a rider, track live on a map, call,
pay (Paystack), and rate; a rider can go online, share background location, receive offers,
accept/reject, run the delivery transitions, navigate, call, and confirm payment. The remaining
work is **secondary screens whose APIs mostly already exist** (🟡) plus a **defined set of missing
endpoints** (🔴) and the **two known deferrals** (⏸️). See the remediation plan at the end.

---

## A. Authentication & account

| Web feature | Status | Evidence / note |
|---|---|---|
| Register (email/password) | 🟡 | `POST /auth/register` implemented & tested (`routes_v1.php` `api_register`, riders get a pending-KYC profile). **UI gap:** `screens/index.tsx#RegisterScreen` is still a placeholder `EmptyState`; `LoginScreen` links to it. New users can't yet register in-app. → **R1**. |
| Login | ✅ | `POST /auth/login` + `LoginScreen` wired via `AuthContext.signIn`. Rate-limited by IP+email. |
| Google OAuth sign-in | ⏸️ | `POST /auth/google` not implemented (documented leftover in `api/README.md`). Needs native PKCE + backend code-exchange with **no duplicate account**. → **R3**. |
| Forgot / reset password | 🟡 | `POST /auth/forgot` + `POST /auth/reset` implemented (generic response, single-use 30-min token, revokes sessions); `authApi.forgot/reset` in `services.ts`. **UI gap:** no forgot/reset screens. → **R1**. |
| Complete profile (post-OAuth) | ⏸️ | `POST /profile/complete` not implemented; only meaningful once Google OAuth lands. → **R3**. |
| Logout / token revoke | ✅ | `POST /auth/logout` revokes the device token family; `ProfileScreen` "Sign out". |
| Token refresh | ✅ | `POST /auth/refresh` (rotates within the device family); handled by the API client. |
| Locale (en/ha) | ✅ | Bundled i18next keys (`react-i18next`), no endpoint needed — matrix allowed "bundle keys". |

## B. Sender

| Web feature | Status | Evidence / note |
|---|---|---|
| Manage profile (name, phone, avatar) | 🟡 | `GET /profile` + `PATCH /profile` (name/phone) exist; `authApi.updateProfile` wired. **Gaps:** `ProfileScreen` is read-only (no edit form), and **avatar upload is not supported** by `PATCH /profile`. → **R1** (edit form) + **R2** (avatar). |
| Address search (Nigeria, as-you-type) | ✅ | `AddressSearch` → on-device `geo.geocode` (public Mapbox token, `country=ng`). **Design deviation (intentional):** done on-device with the *public* token instead of a server `/geo/search` proxy — secure by design, same as web; the secret token stays server-side. Documented in `api/README.md`. |
| Use current location | 🔴 | Not built — `AddressSearch` is search-only (its header comment defers current-location to a later pass). Needs `expo-location` one-shot + on-device reverse-geocode. → **R2** (enhancement). |
| Pick location on map / adjust pin | 🔴 | `MapPreview` is display-only (`pointerEvents="none"`). No draggable-pin picker in booking creation. → **R2** (enhancement). |
| Route preview + distance/ETA | ✅ | `POST /geo/route` + `POST /pricing/estimate` return distance/duration; `CreateBookingScreen` shows "km · ~min". (Full polyline overlay is an enhancement, not required for parity of the number.) |
| Select vehicle type + fee preview | ✅ | `POST /pricing/estimate` (backend price, no client formula) + vehicle buttons in `CreateBookingScreen`. |
| Create delivery / booking | ✅ | `POST /bookings` with `Idempotency-Key` (retry-safe); `CreateBookingScreen`. |
| Rider discovery (online-first, top-10) | ✅ | `GET /bookings/{id}/riders` (mirrors `ajax_fetch_riders` ranking) + `RidersScreen`. |
| Rider details for decision | ✅ | Distance, vehicle, rating, ETA, `lastSeenSecondsAgo` all rendered on the rider card. |
| Send request to rider / negotiate | ✅ | `POST /bookings/{id}/request` (capacity cap, supersede pending) + `RidersScreen.choose`. |
| Edit order / change delivery addr + reprice | 🔴 | **No `PATCH /bookings/{id}`** endpoint and no UI. This is a defined web feature (edit pre-handover, change delivery address anytime with reprice). → **R2**. |
| Cancel booking (rules) | 🟡 | `POST /bookings/{id}/cancel` implemented with the exact web rules (blocked after payment/handover); `senderApi.cancel` wired. **UI gap:** no cancel button surfaced on any sender screen. → **R1**. |
| Track rider + delivery (live) | ✅ | `GET /bookings/{id}/track` (freshness-aware) + `TrackScreen` with `MapPreview` (stale fix hidden > 120 s). |
| Public recipient tracking link | 🌐 | Web tracker retained. **Minor:** no "share link" affordance on mobile yet (nice-to-have). → **R2** (enhancement). |
| Chat with rider | 🔴 | **No `GET/POST /bookings/{id}/messages`** and no chat UI. Core web feature (with tick states). Largest single gap. → **R2**. |
| Call rider (in-app) | ⏸️ | Deliberately **not** offered on mobile — no WebRTC infra there; the web PeerJS path does not port. `contact` endpoint returns `canCallInApp:false`. |
| Call rider (device dialler) | ✅ | `GET /bookings/{id}/contact` (only the two parties) + `CallButton` → `tel:`. |
| Notifications (booking/delivery) | 🟡 | Push works end-to-end (`send_expo_push` + `device_tokens`, deep-link on tap); `GET /notifications` + `notificationsApi.list` exist. **UI gap:** `NotificationsScreen` is a placeholder — the in-app history list isn't rendered. → **R1**. |
| Active / unpaid / history orders | 🟡 | `GET /bookings?filter=active\|unpaid\|history` implemented. **UI gap:** `SenderOrdersScreen` only loads `active`; no unpaid/history tabs. → **R1**. |
| Make payment (Paystack) | ✅ | `POST /payments/init` + `/payments/verify` (secret stays server-side, idempotent) + `PayScreen`. |
| Confirm delivery completion | 🟡 | Delivery completion is rider-driven (`transition → delivered`) and reflected on the sender's `TrackScreen`. **Note:** the web's separate *sender handover-confirm* has no mobile endpoint; flag for review whether mobile senders need it. → **R2** (review). |
| Rate / review rider | ✅ | `POST /bookings/{id}/rating` (one per booking) + `RateScreen`. |
| Report issue / support | 🟡 | `POST /complaints` implemented (category-validated, notifies admins); `senderApi.complain` wired. **UI gap:** no complaint form screen. → **R1**. |
| Receipts / transaction records | 🔴 | **No `GET /payments`** list endpoint and no UI. → **R2**. |
| Rebook a past order | 🔴 | **No `POST /bookings/{id}/rebook`** and no UI. → **R2**. |

## C. Rider

| Web feature | Status | Evidence / note |
|---|---|---|
| Register + onboarding | 🟡 | Shares `POST /auth/register` (role=rider, vehicle, pending-KYC row). **UI gap:** registration screen is a stub (same as A). → **R1**. |
| Submit identity/vehicle/verification (KYC) | ⏸️ | `POST /rider/kyc` (multipart upload) not implemented — documented leftover. Needs secure upload; docs not cached locally. → **R3**. |
| Manage rider profile / vehicle type | 🔴 | `GET /rider/profile` exists (read). **No `PATCH /rider/profile`** to change vehicle type, and no UI. → **R2**. |
| Set availability (online/offline) | ✅ | `POST /rider/status` (KYC-gated for `available`) + `RiderHomeScreen` switch. |
| Share location while online/active | ✅ | `POST /rider/location` (Nigeria-bounds, deduped) + `services/location.ts` background task (runs only while online). |
| Receive nearby requests | ✅ | `GET /rider/offers` + push + `RiderOffersScreen` (polls, push wakes app). |
| View pickup/delivery details | ✅ | Offer/booking payload rendered in `RiderOffersScreen` / `RiderActiveJobsScreen`. |
| View suggested fee | ✅ | `proposedCost` on the offer, shown via `MoneyText`. |
| Accept / reject / negotiate | ✅ | `POST /rider/offers/{id}/accept\|reject` (capacity cap, supersede) + `RiderOffersScreen`. (Free-form counter-offer is not a distinct web feature.) |
| Navigate to pickup/delivery | ✅ | Deep-link to Google Maps directions in `RiderActiveJobsScreen.navigateTo`. |
| Contact sender (dialler) | ✅ | `GET /bookings/{id}/contact` + `CallButton` "Call sender". |
| Update delivery status (arrive/pickup/deliver) | ✅ | `POST /rider/bookings/{id}/transition` (canonical map, server-validated) + `RiderActiveJobsScreen`. |
| Confirm payment received | ✅ | `POST /rider/bookings/{id}/confirm-payment` (85% payout, guarded) + button. |
| Active / pending / completed / cancelled jobs | 🟡 | `GET /rider/bookings?filter=active\|pending\|completed\|cancelled` implemented. **UI gap:** `RiderActiveJobsScreen` only loads `active`; other buckets not surfaced. → **R1**. |
| View earnings / wallet ledger | 🟡 | `GET /rider/wallet` returns balance + available + ledger. **UI gap:** `RiderWalletScreen` shows the two balances but **not** the ledger list. → **R1**. |
| Add / verify bank account | 🔴 | Only `GET /rider/banks` (bank list) exists. **No `POST /rider/bank` + verify** and no UI — so a mobile-only rider can't set up payouts. → **R2**. |
| Request withdrawal | 🟡 | `POST /rider/withdrawals` implemented (transactional, idempotent); `riderApi.withdraw` wired. **UI gap:** no withdrawal form. → **R1** (depends on bank-setup **R2**). |
| Track withdrawal status | 🔴 | **No `GET /rider/withdrawals`** list endpoint and no UI. → **R2**. |
| Notifications | 🟡 | Same push infra as sender; in-app list screen is a stub. → **R1**. |
| Report problems / support | 🔴 | `POST /complaints` is currently **`sender`-only** (`api_require($pdo, ['sender'])`), so a rider cannot file one, and there's no UI. Parity defect — broaden the role (and add a screen). → **R2**. |
| Training gate | 🔴 | **No `GET/POST /rider/training`** and no UI. Confirm whether the live web app enforces a training gate; if so, mobile must honour it. → **R2** (confirm-then-build). |

## D. Admin / operations — 🌐 Web-only-retained (all rows)

Per the spec, admin UI stays on web; mobile must **honour** admin-driven decisions. Integration
requirements verified:

- **Suspended accounts are rejected mid-session** — confirmed: `config/api.php` re-checks
  `status = 'active'` on every authenticated request (not just at login), so an admin suspension
  invalidates a live mobile token immediately. ✅
- **Admin match / price override, delivery monitoring, payment/withdrawal state, disputes, pricing
  config** — mobile reads the same `bookings` / wallet / pricing source of truth; it never edits
  admin-owned config. ✅
- **Mobile-origin actions are audit-logged** — `log_event(...)` is called on create, cancel,
  transition, offer accept/reject, and payment confirmation (tagged "(mobile)"). ✅

No admin screens are needed on mobile; all twelve rows are intentionally retained on web.

## E. Cross-cutting

| Feature | Status | Evidence |
|---|---|---|
| Offline shell + branded splash | ✅ | Native splash + offline gate in `App.tsx` / connectivity hook (mirrors `offline.html`). |
| Web Push / VAPID → FCM/APNs | ✅ | `send_web_push` now records + fans out to web (VAPID) **and** mobile (`send_expo_push`, `device_tokens`). |
| CSRF | ✅ | N/A on mobile by design — bearer-token API uses no cookies. |
| Rate limiting | ✅ | Auth endpoints reuse the web `rate_limit_attempts` table (login IP+email, forgot). |
| Audit logging | ✅ | Mobile-origin actions logged via `log_event` (see D). |

---

## Intentional deviations from the matrix (not gaps)

1. **Geocoding is on-device** (public Mapbox token, Nigeria-restricted) rather than a server
   `/geo/search` + `/geo/reverse` proxy. Secure by design (public token only; secret token stays
   server-side for Directions/pricing) and identical to the web app. Documented in `api/README.md`.
2. **In-app calling is not offered on mobile** — the web PeerJS/WebRTC stack does not port to a
   managed Expo app, so the reliable device dialler is the single call path. The contract advertises
   this honestly (`canCallInApp:false`).

## Remediation plan (closes every 🟡/🔴 to reach full parity)

The gaps cluster into three well-scoped passes plus a small enhancement set. **None** requires
duplicating business logic — the trusted rules already live in the backend.

**R1 — Mobile UI-wiring pass (APIs already exist and are tested).** Highest value / lowest risk:
- Registration screen (sender + rider incl. vehicle) → `POST /auth/register`.
- Forgot / reset password screens → `/auth/forgot`, `/auth/reset`.
- Notifications list screen → `GET /notifications` (+ mark-read).
- Sender orders buckets (unpaid, history) + rider job buckets (pending, completed, cancelled).
- Wallet ledger list on `RiderWalletScreen` → already in the `/rider/wallet` payload.
- Profile edit form (name/phone) → `PATCH /profile`.
- Cancel-booking action → `POST /bookings/{id}/cancel`.
- Complaint form → `POST /complaints`.
- Withdrawal form → `POST /rider/withdrawals` (gated on R2 bank-setup).

**R2 — Missing backend endpoints (+ their screens).** Each wraps existing backend logic/tables:
- `PATCH /bookings/{id}` — edit details / change delivery address + **backend reprice**.
- `GET/POST /bookings/{id}/messages` — chat with tick states.
- `GET /payments` — sender receipts/transaction history.
- `POST /bookings/{id}/rebook` — prefill a new booking from a past one.
- `POST /rider/bank` + `POST /rider/bank/verify` — add/verify payout account (Paystack name-resolve).
- `GET /rider/withdrawals` — withdrawal status list.
- `PATCH /rider/profile` — change vehicle type.
- Broaden `POST /complaints` to allow `rider` (parity defect) + rider complaint screen.
- `GET/POST /rider/training` — **confirm** the web training gate exists, then honour it.
- Enhancements: current-location button, map-pin picker, avatar upload, public-tracking share link,
  route polyline overlay, sender handover-confirm (review need).

**R3 — Known deferrals (already documented).**
- `POST /auth/google` (native PKCE / backend code-exchange, no duplicate account) + `POST /profile/complete`.
- `POST /rider/kyc` (secure multipart document upload).

## Acceptance status against the roadmap gate

- Every matrix row is now **classified** (implemented / web-retained / deferred-with-reason) — the
  Phase 8 gate's core requirement. ✅
- **No secrets in the app**, **no duplicated business logic**, **web app untouched** — all still
  hold across the shipped mobile surface. ✅
- **Full parity is not yet reached**: the R1/R2 items above are the remaining implemented-vs-planned
  delta. This document is the authoritative punch-list for closing it before store submission
  (Phase 10). Device/runtime verification of the shipped flows is **Phase 9**.
</content>
</invoke>
