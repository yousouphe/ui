# Aike Mobile — Phase 9: Security & Performance

Roadmap Phase 9 (doc 06) is **"Token/IDOR/rate-limit review; battery/network/device perf; low-end
Android."** It has two halves with different execution contexts:

1. **Security review** — a *static* audit of the mobile API surface (auth, IDOR, tokens, rate limits,
   secrets). This can be, and has been, done in the repo. Results + one fix are recorded below.
2. **Device / runtime performance** — battery, network, permissions, low-end Android, real-device
   E2E. This **cannot** run in the audit sandbox (no Android SDK / Xcode / physical device), so it is
   specified here as an executable **on-device test plan** for the Phase 10 build pipeline.

---

## Part 1 — Security review (done in-repo)

Scope: the API surface the mobile app depends on, with emphasis on the newest handlers added across
R1/R2/R3 (`logistics-app/api/index.php` route table + `api/routes_v1.php`). The web app's own
endpoints were reviewed in earlier security passes (tasks SEC#1–8).

### 1.1 Authorisation & role enforcement — ✅ pass
Every handler begins with `api_require($pdo, [roles])`; there are **no unauthenticated
data-returning routes** except `auth/*` (register/login/forgot/reset/google) and `geo/route` (which
requires *any* authenticated user). Role scoping matches the web app:

- Sender-only: booking create/get/cancel/update/rebook, payments, rating, complaint, riders discover,
  payment init/verify.
- Rider-only: profile/status/location/offers/transition/confirm-payment/bookings/wallet, bank
  get/verify/save, withdrawals, KYC.
- Either party: `bookings/{id}/contact`, `bookings/{id}/messages` (chat).

### 1.2 IDOR / object ownership — ✅ pass
Every endpoint that takes a resource id re-derives ownership from the authenticated user rather than
trusting the id:

| Endpoint | Ownership guard |
|---|---|
| `GET/PATCH/POST bookings/{id}` (get, cancel, update, rebook, rating) | `WHERE id = ? AND sender_user_id = ?` |
| `GET bookings/{id}/contact` | explicit membership check → 403 if neither sender nor selected rider |
| `GET/POST bookings/{id}/messages` | `api_chat_authorize`: `WHERE id = ? AND (sender_user_id = ? OR selected_rider_user_id = ?)`; **receiver derived server-side** on send |
| `POST rider/bookings/{id}/transition`, `.../confirm-payment` | booking must belong to the acting rider |
| `GET payments`, `rider/wallet`, `rider/withdrawals`, `rider/bank`, `rider/kyc` | filtered by `sender_user_id` / `rider_user_id = ?` |

No endpoint returns another user's row by id alone.

### 1.3 Secrets — ✅ pass
No secret ships in the app. Server-side only: secret Mapbox token (Directions/pricing), Paystack
secret (init/verify, bank resolve, transfers), Google verification, SMTP, VAPID/webhook secrets.
The app carries only **public** values (public Mapbox token for on-device geocoding, non-secret
Google client IDs, Expo push project id). `config/env.php` is gitignored and was verified redacted
before commit.

### 1.4 Sensitive-data handling — ✅ pass
Bank account numbers are **masked to the last 4 digits** on every read (`rider/bank`,
`rider/withdrawals`). The account holder name is always the Paystack-resolved value, never
client-supplied. KYC documents are stored under `uploads/kyc` (directory-hardened by the SEC#4
`.htaccess`) and never returned as public URLs; uploads reuse `save_uploaded_image` (JPG/PNG/WEBP,
≤5 MB, content-type checked).

### 1.5 Idempotency & double-spend — ✅ pass
`POST bookings` and `POST rider/withdrawals` use the shared `idempotency_keys` replay guard; the
withdrawal additionally runs inside a transaction with a **row-locked available-balance check**
(`rider_available_balance_locked`), the same double-spend protection as the web path. Payment
verify is idempotent against the Paystack reference.

### 1.6 Rate limiting — ✅ pass (with a noted enhancement)
Auth endpoints reuse the web `rate_limit_attempts` table (login by IP+email, forgot-password). Token
refresh rotates within a device token family so a leaked refresh token is single-use.
**Enhancement (not blocking):** `auth/google` and `profile/complete` are not themselves IP-rate-limited;
`auth/google` is naturally throttled by Google's own verification and the audience pin below, but
adding them to the same limiter is a cheap future hardening.

### 1.7 Finding fixed this pass — OAuth audience confusion (fail-closed)
**Before:** `api_auth_google` skipped the audience (`aud`) check when no client IDs were configured
server-side (`if (!empty($allowedAud) && …)`). A Google ID token is only bound to the app that
requested it via its `aud` claim; without pinning `aud`, a validly-signed token minted for **any
other** Google OAuth app could be replayed to sign in as that user (audience-confusion).

**After:** the check now **fails closed** — an empty allow-list returns
`503 GOOGLE_NOT_CONFIGURED` instead of accepting any audience; a configured allow-list still returns
`401 GOOGLE_AUD` on mismatch. The token itself is independently verified against Google's `tokeninfo`
(signature/issuer/expiry) and `email_verified` is required.

**Verification:** `php -l` clean; against the local test DB, `auth/google` with an empty body → `400`,
with a fake token → `401 GOOGLE_INVALID` (the request really round-trips to Google's tokeninfo and is
rejected — no 500 from the new branch). The new `503` branch requires a *validly-signed* Google token
with an unconfigured allow-list, which needs real Google credentials → exercised on-device in Part 2.

### 1.8 Admin-driven state honoured mid-session — ✅ pass
`config/api.php` re-checks `status = 'active'` on **every** authenticated request, so an admin
suspension invalidates a live mobile token immediately (not just at next login). Mobile never edits
admin-owned config (pricing, KYC approval, withdrawal processing) — it only reads the shared source
of truth. Mobile-origin mutations are `log_event`-audited and tagged "(mobile)".

**Security verdict:** no IDOR, auth-bypass, secret-leak, or double-spend gaps found in the mobile API
surface; one audience-confusion hardening applied and verified. Ready for the on-device pass.

---

## Part 2 — Device / runtime test plan (execute in Phase 10 pipeline)

Runs on real builds (EAS) + devices. Two items **must** run here because they need real third-party
credentials and hardware and could not be exercised in-repo:

- **Full Google OAuth round-trip** — a real device obtains a Google ID token; verify a first-time
  Google user lands on Complete-Profile, and an existing-email user is *linked* (no duplicate row).
  Also verify the fail-closed `503` when client IDs are misconfigured.
- **KYC document upload** — pick/capture from camera + gallery; verify JPG/PNG/WEBP accepted, >5 MB
  and other types rejected, and the profile flips to `kyc_status = pending`.

### 2.1 E2E flow matrix (Detox or Maestro)
Auth: register (sender/rider), login, logout, forgot→reset, **Google sign-in**, complete-profile,
expired-session refresh, suspended-account rejection mid-session.
Sender: address search, create booking (idempotent double-tap = one booking), rider discovery +
request, edit booking + reprice, cancel (rule-blocked after handover), live track, call (dialler),
chat (send/receive + ✓/✓✓ ticks), pay (Paystack) incl. **failed payment** and **duplicate attempt**,
receipts, rebook, rate, complaint, notifications list + deep-link.
Rider: **KYC upload**, go online (KYC-gated), background location while online only, receive offer
(push wakes app), accept/reject, status transitions, navigate, confirm payment, job buckets, wallet
ledger, add/verify bank, withdraw (insufficient-funds blocked), withdrawal status.
Resilience: offline operation + reconnect, permission denial (location/notifications/camera),
**app restart during an active delivery**, server 5xx/timeout handling.

### 2.2 Device / OS matrix
Android 8–14 (incl. one low-memory / low-end device for the manual perf pass) and iOS 15–latest;
small and large screens; light/dark; en and ha locales.

### 2.3 Manual performance & battery checklist
- **Battery:** background location while online for 1 h — measure drain; confirm the task **stops** on
  go-offline and on app kill (no zombie location). Compare online-idle vs actively-delivering.
- **Network:** slow-3G and lie-fi (flaky) — screens degrade gracefully, no infinite spinners; polling
  (chat, tracking, offers) backs off and resumes; no request storms on reconnect.
- **Memory/jank:** long chat scroll, map with live rider marker, and the rider offers list stay at
  60 fps on the low-end device; no leak across 30 min of navigation.
- **Cold start & bundle:** measure cold-start on the low-end device; confirm maps/image-picker load
  lazily (not at startup).
- **Push latency:** offer→device notification latency on Android (FCM) and iOS (APNs), foreground and
  background.

### 2.4 Consistency
Spot-check that a mobile-originated change (booking create, cancel, transition, payment) appears on
web against the same DB, and vice-versa — the web/mobile drift guard from the roadmap.

### Exit criteria (Phase 9 → Phase 10)
Security review pass (Part 1) ✅ done. Device pass complete when: every E2E flow green on the device
matrix; battery drain acceptable with the location task provably stopping on offline/kill; graceful
offline/reconnect; no leaks/jank on the low-end device; OAuth links with no duplicate account; KYC
upload validates correctly; web↔mobile stay in sync. Then proceed to Phase 10 (signed builds + store
listings).
