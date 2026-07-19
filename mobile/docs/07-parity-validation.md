# Aike Mobile — Parity Validation (Phase 8 gate + R1/R2/R3 remediation)

This is the **feature-parity gate** required by the roadmap (doc 06): every row of the parity
matrix (doc 02) is verified against what is actually shipped, and classified as **implemented**,
**intentionally web-retained**, or **not-yet-implemented with a reason and an owner**. Nothing is
silently dropped.

The original Phase 8 audit was a **static audit** of the merged code and produced a three-pass
remediation plan (R1/R2/R3). **All three passes have since landed** — this document has been
updated to reflect the shipped state, so the matrix below is the *current* state, not the
Phase-8-snapshot state. The remediation plan at the end is kept as a **changelog** of what closed
each gap.

- **API** = the route table in `logistics-app/api/index.php` + handlers in `api/routes_v1.php`.
- **UI** = the mobile screens/services actually wired in `mobile/src` (navigator, `api/services.ts`,
  `screens/**`).

> Device/runtime verification (a real build exercising these flows end-to-end) is **Phase 9** — the
> app cannot be built in the audit sandbox (no Android SDK/Xcode). This document verifies presence
> and wiring, not on-device behaviour. Two flows in particular are validated on-device in Phase 9
> because they need real third-party credentials: the full Google OAuth round-trip and KYC image
> upload from a device camera/gallery.

## Status legend

| Token | Meaning |
|---|---|
| ✅ **Parity** | Backend endpoint **and** a wired mobile UI both present. |
| 🟡 **API-ready, UI pending** | Endpoint exists (and was tested); no dedicated mobile screen yet. |
| 🔵 **Enhancement (optional)** | Non-blocking nice-to-have; the core parity of the surrounding feature is already met. |
| ⏸️ **Deferred (by design)** | Intentionally not on mobile, with a documented reason. |
| 🌐 **Web-only-retained** | Intentionally web; mobile honours the server outcome. |

## Scorecard (post-R1/R2/R3)

| Area | ✅ | 🟡 | 🔵 | ⏸️ | 🌐 |
|---|--:|--:|--:|--:|--:|
| A. Auth & account | 8 | 0 | 0 | 0 | 0 |
| B. Sender | 20 | 1 | 2 | 1 | 1 |
| C. Rider | 20 | 0 | 0 | 0 | 1 |
| D. Admin / ops | — | — | — | — | 12 |
| E. Cross-cutting | 5 | 0 | 0 | 0 | 0 |

**Headline:** both roles are now at **full functional parity** end-to-end. Every core web feature has
a wired mobile counterpart; the only non-✅ rows are **two optional enhancements** (current-location
button, in-map pin picker), **one item under product review** (a separate sender handover-confirm
step), and the **intentional deferrals/web-retentions** (in-app calling stays web; admin stays web).
No parity-critical gap remains.

---

## A. Authentication & account

| Web feature | Status | Evidence / note |
|---|---|---|
| Register (email/password) | ✅ | `POST /auth/register` + `screens/auth/RegisterScreen` (sender + rider incl. vehicle); riders get a pending-KYC profile. **(R1)** |
| Login | ✅ | `POST /auth/login` + `LoginScreen` via `AuthContext.signIn`. Rate-limited by IP+email. |
| Google OAuth sign-in | ✅ | `POST /auth/google` verifies the Google **ID token server-side** (tokeninfo), links by `google_id`→email→create sender, no duplicate account; `components/GoogleSignInButton` (expo-auth-session) on Login + Register. Full valid-token round-trip validated on-device in Phase 9. **(R3)** |
| Forgot / reset password | ✅ | `POST /auth/forgot` + `POST /auth/reset` (single-use 30-min token, revokes sessions) + `ForgotPasswordScreen` / `ResetPasswordScreen`. **(R1)** |
| Complete profile (post-OAuth) | ✅ | `POST /profile/complete` (phone required, optional become-rider → pending-KYC) + `CompleteProfileScreen`, gated in `RootNavigator` when `profileCompleted=false`. **(R3)** |
| Logout / token revoke | ✅ | `POST /auth/logout` revokes the device token family; `ProfileScreen` "Sign out". |
| Token refresh | ✅ | `POST /auth/refresh` (rotates within the device family); handled by the API client. |
| Locale (en/ha) | ✅ | Bundled i18next keys (`react-i18next`), no endpoint needed — matrix allowed "bundle keys". |

## B. Sender

| Web feature | Status | Evidence / note |
|---|---|---|
| Manage profile (name, phone, avatar) | ✅ | `GET /profile` + `PATCH /profile` + editable `ProfileScreen` (name/phone). **(R1)** *Avatar upload remains an optional enhancement — see 🔵 note below; core profile parity is met.* |
| Address search (Nigeria, as-you-type) | ✅ | `AddressSearch` → on-device `geo.geocode` (public Mapbox token, `country=ng`). Intentional design deviation (on-device public token, secret stays server-side); documented in `api/README.md`. |
| Use current location | 🔵 | Optional enhancement — `AddressSearch` is search-only. Needs `expo-location` one-shot + on-device reverse-geocode. Not parity-blocking (web address entry is also typed). |
| Pick location on map / adjust pin | 🔵 | Optional enhancement — `MapPreview` is display-only. A draggable-pin picker is a convenience over typed/searched entry. |
| Route preview + distance/ETA | ✅ | `POST /geo/route` + `POST /pricing/estimate` return distance/duration; `CreateBookingScreen` shows "km · ~min". |
| Select vehicle type + fee preview | ✅ | `POST /pricing/estimate` (backend price, no client formula) + vehicle buttons in `CreateBookingScreen`. |
| Create delivery / booking | ✅ | `POST /bookings` with `Idempotency-Key` (retry-safe); `CreateBookingScreen`. |
| Rider discovery (online-first, top-10) | ✅ | `GET /bookings/{id}/riders` (mirrors `ajax_fetch_riders` ranking) + `RidersScreen`. |
| Rider details for decision | ✅ | Distance, vehicle, rating, ETA, `lastSeenSecondsAgo` all rendered on the rider card. |
| Send request to rider / negotiate | ✅ | `POST /bookings/{id}/request` (capacity cap, supersede pending) + `RidersScreen.choose`. |
| Edit order / change delivery addr + reprice | ✅ | `PATCH /bookings/{id}` (edit pre-handover; delivery-address change reprices only if a rider is selected AND the new destination is farther, mirroring `ajax_update_delivery.php`) + `EditBookingScreen`. **(R2)** |
| Cancel booking (rules) | ✅ | `POST /bookings/{id}/cancel` (blocked after payment/handover) + cancel action surfaced on the sender detail. **(R1)** |
| Track rider + delivery (live) | ✅ | `GET /bookings/{id}/track` (freshness-aware) + `TrackScreen` with `MapPreview` (stale fix hidden > 120 s). |
| Public recipient tracking link | ✅ | Web tracker retained (🌐); a "share link" affordance is now surfaced on the sender booking detail. **(R2)** |
| Chat with rider | ✅ | `GET/POST /bookings/{id}/messages` (server derives receiver; incoming marked read → drives ticks) + `ChatScreen` (WhatsApp-style bubbles, ✓/✓✓, polling). **(R2)** |
| Call rider (in-app) | ⏸️ | Deliberately **not** offered on mobile — no WebRTC infra there; the web PeerJS path does not port. `contact` returns `canCallInApp:false`. |
| Call rider (device dialler) | ✅ | `GET /bookings/{id}/contact` (only the two parties) + `CallButton` → `tel:`. |
| Notifications (booking/delivery) | ✅ | Push end-to-end (`send_expo_push` + `device_tokens`, deep-link on tap) + `GET /notifications` + `NotificationsScreen` (in-app history, mark-read). **(R1)** |
| Active / unpaid / history orders | ✅ | `GET /bookings?filter=active\|unpaid\|history` + bucket tabs (`FilterTabs`) on the sender orders screen. **(R1)** |
| Make payment (Paystack) | ✅ | `POST /payments/init` + `/payments/verify` (secret stays server-side, idempotent) + `PayScreen`. |
| Confirm delivery completion | 🟡 | Delivery completion is rider-driven (`transition → delivered`) and reflected on the sender's `TrackScreen`. **Under product review:** whether mobile senders need the web's separate handover-confirm step; no mobile endpoint intentionally, pending that decision. |
| Rate / review rider | ✅ | `POST /bookings/{id}/rating` (one per booking) + `RateScreen`. |
| Report issue / support | ✅ | `POST /complaints` (category-validated, post-delivery, notifies admins) + `screens/sender/ComplaintScreen`. **(R1)** |
| Receipts / transaction records | ✅ | `GET /payments` + `screens/sender/ReceiptsScreen`. **(R2)** |
| Rebook a past order | ✅ | `POST /bookings/{id}/rebook` (cancelled → submitted, prefills a new booking) + rebook action. **(R2)** |

## C. Rider

| Web feature | Status | Evidence / note |
|---|---|---|
| Register + onboarding | ✅ | Shares `POST /auth/register` (role=rider, vehicle, pending-KYC row) via `RegisterScreen`. **(R1)** |
| Submit identity/vehicle/verification (KYC) | ✅ | `GET/POST /rider/kyc` (multipart, reuses `save_uploaded_image` → JPG/PNG/WEBP ≤5 MB, stored `uploads/kyc`, sets `kyc_status='pending'`) + `screens/rider/KycScreen` (expo-image-picker). On-device camera/gallery upload validated in Phase 9. **(R3)** |
| Manage rider profile / vehicle type | ✅ | `GET /rider/profile` + `PATCH /rider/profile` (change vehicle type) + `screens/rider/VehicleScreen`. **(R2)** |
| Set availability (online/offline) | ✅ | `POST /rider/status` (KYC-gated for `available`) + `RiderHomeScreen` switch. |
| Share location while online/active | ✅ | `POST /rider/location` (Nigeria-bounds, deduped) + `services/location.ts` background task (runs only while online). |
| Receive nearby requests | ✅ | `GET /rider/offers` + push + `RiderOffersScreen` (polls, push wakes app). |
| View pickup/delivery details | ✅ | Offer/booking payload rendered in `RiderOffersScreen` / `RiderActiveJobsScreen`. |
| View suggested fee | ✅ | `proposedCost` on the offer, shown via `MoneyText`. |
| Accept / reject / negotiate | ✅ | `POST /rider/offers/{id}/accept\|reject` (capacity cap, supersede) + `RiderOffersScreen`. |
| Navigate to pickup/delivery | ✅ | Deep-link to Google Maps directions in `RiderActiveJobsScreen.navigateTo`. |
| Contact sender (dialler) | ✅ | `GET /bookings/{id}/contact` + `CallButton` "Call sender". |
| Update delivery status (arrive/pickup/deliver) | ✅ | `POST /rider/bookings/{id}/transition` (canonical map, server-validated) + `RiderActiveJobsScreen`. |
| Confirm payment received | ✅ | `POST /rider/bookings/{id}/confirm-payment` (85% payout, guarded) + button. |
| Active / pending / completed / cancelled jobs | ✅ | `GET /rider/bookings?filter=...` + bucket tabs on the rider jobs screen. **(R1)** |
| View earnings / wallet ledger | ✅ | `GET /rider/wallet` (balance + available + ledger) + ledger list on the wallet screen. **(R1)** |
| Add / verify bank account | ✅ | `GET /rider/bank`, `POST /rider/bank`, `POST /rider/bank/verify` (Paystack `paystack_resolve_account`; name is always Paystack-resolved) + `screens/rider/BankAccountScreen`. **(R2)** |
| Request withdrawal | ✅ | `POST /rider/withdrawals` (transactional, idempotent) + `screens/rider/WithdrawScreen`. **(R1/R2)** |
| Track withdrawal status | ✅ | `GET /rider/withdrawals` + `screens/rider/WithdrawalsScreen`. **(R2)** |
| Notifications | ✅ | Same push infra as sender + `NotificationsScreen`. **(R1)** |
| Report problems / support | 🌐 | **Corrected (was flagged a defect):** filing complaints is a **sender-only** feature in the web app — the web app has no rider-complaint flow — so `POST /complaints` staying `sender`-scoped is *parity*, not a gap. Rider support is handled via the same channels as web. |
| Training gate | ✅ | **Corrected (was flagged a missing gate):** the web app's "training" is an **informational page**, not an enforced approval gate (KYC is the actual gate). Mobile honours it as an info screen: `screens/rider/GuidelinesScreen`. No `/rider/training` endpoint is needed. |

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
| Web Push / VAPID → FCM/APNs | ✅ | `send_web_push` records + fans out to web (VAPID) **and** mobile (`send_expo_push`, `device_tokens`). |
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

## Two Phase-8 corrections (matrix misreads, now fixed above)

1. **Rider "report problems" is not a web feature.** The Phase 8 audit flagged `POST /complaints`
   being `sender`-only as a parity defect. On re-checking the web app, riders have no complaint
   flow — so keeping it sender-scoped is correct parity. No endpoint or screen was added; the row
   is reclassified 🌐.
2. **The rider "training gate" is an info page, not an enforced gate.** KYC approval is the real
   gate for going online (`POST /rider/status` enforces it). The web "training" is informational,
   so mobile satisfies it with `GuidelinesScreen` rather than a `/rider/training` approval endpoint.

## Remediation changelog (R1/R2/R3 — all landed)

**R1 — Mobile UI-wiring pass (APIs already existed and were tested).** ✅ **Done.**
Registration + forgot/reset screens; notifications list; sender order buckets (unpaid/history) +
rider job buckets (pending/completed/cancelled); wallet ledger list; profile edit form;
cancel-booking action; complaint form; withdrawal form.

**R2 — Missing backend endpoints (+ their screens).** ✅ **Done.**
`PATCH /bookings/{id}` (edit + conditional reprice); `GET/POST /bookings/{id}/messages` (chat with
ticks); `GET /payments` (receipts); `POST /bookings/{id}/rebook`; `POST /rider/bank` +
`/rider/bank/verify` (Paystack name-resolve); `GET /rider/withdrawals`; `PATCH /rider/profile`
(vehicle); rider Guidelines info screen. The two 🔵 enhancements (current-location button, in-map
pin picker) and the handover-confirm review item are deliberately **not** in scope here — they are
non-blocking. The two corrections above removed the rider-complaint and training-gate items.

**R3 — Known deferrals (now implemented).** ✅ **Done.**
`POST /auth/google` (server-side ID-token verification, no duplicate account) + `POST /profile/complete`;
`POST /rider/kyc` (secure multipart document upload). Full on-device round-trips for both are
validated in Phase 9 (need real Google credentials / device camera).

## Acceptance status against the roadmap gate

- Every matrix row is **classified** (implemented / web-retained / deferred-with-reason) — the
  Phase 8 gate's core requirement. ✅
- **No secrets in the app**, **no duplicated business logic**, **web app untouched** — all still
  hold across the shipped mobile surface. ✅
- **Full functional parity reached.** The remaining non-✅ rows are two optional enhancements, one
  handover-confirm review item, and the intentional deferrals/web-retentions — none parity-critical.
  Remaining before store submission (Phase 10): **Phase 9** device/runtime verification of the
  shipped flows (including the two on-device-only round-trips called out above).
