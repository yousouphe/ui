# Aike — Existing System Audit & Feature Inventory (Phase 1)

**Scope:** the existing web application in `logistics-app/`. This document is the mandatory
audit that gates mobile implementation. It records what exists, its status, and the technical
debt that affects mobile integration. The feature-parity matrix is in `02-feature-parity-matrix.md`.

> **Repository note.** The spec's illustrative layout uses `web/` and `backend/` directories.
> The real repository keeps the web app **and** its backend together in `logistics-app/`
> (server-rendered PHP). Per the spec ("adapt the exact structure… rather than moving files"),
> we do **not** rename or relocate `logistics-app/`. The mobile app is added under `mobile/`
> and shared contracts under `shared/`.

---

## 1. Technology stack (as built)

| Layer | Technology |
|---|---|
| Backend | Vanilla PHP 8 (no framework, no Composer), server-rendered pages + AJAX endpoints |
| Data | MariaDB/MySQL via PDO, prepared statements; idempotent SQL migrations in `logistics-app/sql/moduleN_*.sql` |
| Auth | **PHP session cookie + CSRF token** (`$_SESSION['user']`, `require_role()`, `require_csrf()`). Google OAuth sign-in. No token/JWT API. |
| Frontend | Server-rendered PHP + Bootstrap 5 (CDN) + vanilla JS; AJAX polling for realtime |
| Maps | Mapbox (public token for geocoding/maps client-side; **secret token server-side** for Directions/pricing in `config/mapbox.php`) |
| Payments | Paystack (charge + transfers/withdrawals), HMAC-SHA512 webhook verification |
| Notifications | Web Push (VAPID) via `config/push.php` + service worker `sw.js`; `push_subscriptions`/`push_notifications` tables |
| Realtime calls/chat | PeerJS (WebRTC) for calls; file-based presence/calls/voice-notes under `assets/realtime_*`; chat messages in DB |
| i18n | `t()` helper + `lang/en.php`, `lang/ha.php` (English + Hausa) |
| PWA | `sw.js` (offline shell + push), `offline.html`, `config/pwa.php` splash (added recently) |

**Consequence for mobile:** a native app cannot ride the PHP **session cookie**. A
**token-based JSON API** (`/api/**`) must be added to the backend before mobile feature work —
this is the primary blocker (see `04-api-inventory-and-specs.md`).

---

## 2. Canonical business rules (single source of truth = backend)

Extracted directly from the code so mobile mirrors them **for display only** (never re-derives):

- **Roles:** `sender`, `rider`, `admin`, `super_admin` (`users.role`).
- **Booking statuses:** `draft`, `submitted`, `matched`, `accepted`, `arrived_at_pickup`,
  `package_received`, `in_transit`, `delivered`, `cancelled` (`bookings.booking_status`).
- **Payment statuses:** `unpaid`, `pending`, `paid`, `failed`, `refunded` (`bookings.payment_status`).
- **Rider "active" statuses:** `matched, accepted, arrived_at_pickup, package_received, in_transit`
  (`RIDER_ACTIVE_BOOKING_STATUSES`).
- **Rider concurrency cap:** `RIDER_MAX_CONCURRENT_ORDERS = 3`.
- **Rider payout share:** `RIDER_PAYOUT_SHARE = 0.85` (platform commission 15%).
- **Vehicle types:** `bike`, `car`, `van` (pricing multipliers admin-configurable; ETA speeds
  `bike 22 / car 28 / van 24 km/h`).
- **Rider discovery:** top **10** eligible riders (active + KYC-approved + under the 3-order cap),
  **not** filtered by the sender's chosen vehicle type; suggested fee computed per rider's **own**
  vehicle type; online-first phase then full list; last-seen freshness shown; route metrics cached
  (`route_cache`) so the poll loop doesn't hammer Mapbox.
- **Pricing:** `max(minimum_fee, per_km_rate × road_km) × vehicle_multiplier + tax`, fully
  backend-computed from Mapbox road distance; admin-tunable at `/admin/pricing.php`.

These become the shared constants in `shared/constants/` (identifiers only — no logic).

---

## 3. Complete feature inventory (by surface)

### Public / auth
| File | Purpose |
|---|---|
| `index.php` | redirects to `login` |
| `choose-language.php`, `set_locale.php` | locale gate + switch (en/ha) |
| `login.php` | email/password login (rate-limited by IP + email), session regenerate |
| `register.php` | sender/rider registration incl. rider KYC fields (photo, vehicle, colour) |
| `logout.php` | session teardown |
| `forgot-password.php`, `reset-password.php` | password reset via emailed token |
| `complete-profile.php` | post-OAuth profile completion gate |
| `auth/google_login.php`, `auth/google_callback.php` | Google OAuth sign-in/up |

### Sender
| File | Purpose |
|---|---|
| `bookings/index.php` | sender hub: booking wizard, rider discovery, active order, tracking, chat/call |
| `bookings/create.php`, `discover.php`, `list.php`, `track.php`, `complaints.php` | sub-flows / listings / public tracking |
| `dashboard.php` | "My Orders" (active / unpaid / history) |
| `bookings/ajax_estimate_pricing.php` | vehicle-type price preview (reuses client route) |
| `bookings/ajax_fetch_riders.php` | ranked rider discovery (online-first, top-10, cached route) |
| `bookings/send_request.php` | send job request to a chosen rider |
| `bookings/ajax_update_details.php`, `ajax_update_delivery.php` | edit order / change delivery address + reprice |
| `bookings/ajax_cancel_booking.php` | cancel (rules: no cancel after handover) |
| `bookings/ajax_track_status.php`, `ajax_request_status.php`, `ajax_public_track.php` | status polling / public recipient tracking |
| `bookings/ajax_submit_rating.php`, `ajax_submit_complaint.php` | post-delivery rating + complaint |
| `bookings/ajax_rebook.php` | re-book a past order |
| `bookings/callback.php`, `payments/*` | payment initialize/verify/callback/webhook |

### Rider
| File | Purpose |
|---|---|
| `rider/index.php` | rider hub: online toggle, new offers, active jobs, workflow actions, chat/call |
| `rider/dashboard.php` | rider order lists (active/pending/completed/cancelled) |
| `rider/kyc.php`, `training.php` | KYC submission + training gate |
| `rider/wallet.php` | balance, ledger, bank details (Paystack verify), withdrawal request (transactional) |
| `rider/navigation.php` | navigate to pickup/delivery |
| `rider/ajax_check_offers.php` | poll pending offers |
| `rider/ajax_update_status.php` | set availability (online/offline) |
| `rider/ajax_update_location.php`, `update_location.php` | push live rider location |
| `rider/ajax_workflow_action.php` | status transitions (accept/arrive/pickup/deliver) |
| `rider/ajax_confirm_payment.php` | rider confirms payment received (keeps order active until then) |
| `rider/ajax_verify_bank_account.php` | Paystack account-name resolution |

### Admin / operations
| File | Purpose |
|---|---|
| `admin/index.php` | admin dashboard (live polling) |
| `admin/users.php` | manage all accounts, role change, suspend |
| `admin/riders.php` | KYC review, rider activity, suspend, manual match |
| `admin/bookings.php` | booking management, manual rider assignment, manual price override |
| `admin/complaints.php` | dispute/complaint resolution |
| `admin/pricing.php`, `pricing_fallback.php` | pricing engine config (super_admin), haversine fallback speed |
| `admin/logs.php` | super-admin event/audit log |
| `admin/profile.php` | admin profile |

### Chat / notifications / shared
| File | Purpose |
|---|---|
| `chat/ajax_send_message.php`, `ajax_fetch_messages.php` | in-booking chat (DB-backed, tick states) |
| `notifications/ajax_save_subscription.php` | store web-push subscription |
| `notifications/ajax_fetch_pending.php` | SW pulls notification content (authenticated) |
| `profile.php` | self-service profile (sender/rider) |
| `ping.php` | connectivity probe (added with PWA work) |
| `sw.js`, `offline.html`, `config/pwa.php` | PWA offline shell + splash |

### Third-party integrations
Paystack (payments + transfers + webhook), Mapbox (geocoding/maps + Directions pricing),
Google OAuth, SMTP email (`config/mailer.php`), Web Push/VAPID, PeerJS/WebRTC (calls).

---

## 4. Feature status flags (functional / partial / broken / web-specific)

| Area | Status | Notes for mobile |
|---|---|---|
| Auth (email/password, reset, Google OAuth) | **Functional** | Needs token-API equivalent; Google OAuth needs native/PKCE flow |
| Booking wizard + pricing preview | **Functional** | Reuse backend pricing; rebuild UI natively |
| Rider discovery (online-first, top-10, cached route) | **Functional** | Reuse endpoint via API; do not re-implement ranking |
| Booking lifecycle transitions | **Functional** | Enforce server-side; mobile only calls transition endpoints |
| Payments (Paystack charge + verify + webhook) | **Functional** | Use Paystack mobile SDK/checkout; verify server-side; webhook unchanged |
| Wallet + withdrawals (transactional) | **Functional** | Reuse endpoints |
| Rating + complaints | **Functional** | Reuse |
| Chat (DB-backed, tick states) | **Functional** | Reuse; consider push instead of polling on mobile |
| In-app calls (PeerJS/WebRTC) | **Partial / web-specific** | Browser PeerJS won't port directly; mobile needs a WebRTC lib or **fallback to device dialler** (masking rules permitting). Do **not** claim in-app calling until infra confirmed. |
| Realtime presence/voice-notes (file-based under `assets/`) | **Partial / web-specific** | File-based signalling is web-oriented; mobile realtime should use push + polling; revisit voice notes |
| Web Push (VAPID) | **Web-specific** | Mobile needs FCM/APNs (Expo Notifications). Keep `push_notifications` records as source of truth; add device-token table |
| Admin portal | **Functional, remains web** | Mobile integrates with status changes but admin UI stays web (per spec: "only where required") |
| i18n (en/ha) | **Functional** | Expose translations via API or bundle keys in `shared/` |
| PWA offline shell/splash | **Functional (web)** | Mobile re-implements a native branded splash/offline screen (mirrors the web one) |

---

## 5. Bugs & technical debt affecting mobile integration

1. **No token API / mobile auth (blocker).** Everything is session-cookie + CSRF. Must add
   `/api/**` with bearer tokens, refresh, and per-role authorisation. (Spec Phase 2/3.)
2. **AJAX endpoints are coupled to the session and to HTML-ish responses.** Several mix redirects
   with JSON. The mobile API must return **consistent JSON envelopes** and never redirect.
3. **`rider/sw.js` is an orphan** (empty, unregistered) — dead code; not used by mobile.
4. **Realtime via file-based JSON + polling** (`assets/realtime_presence`, `realtime_calls`).
   Works on web but is not a clean mobile realtime channel; mobile should prefer push + short polls.
5. **In-app calling uses browser PeerJS** — no equivalent guaranteed on device; treat device
   dialler as the reliable path and gate in-app calling on real infra.
6. **CSRF model** assumes a cookie session; the token API will use bearer auth instead of CSRF
   (CSRF is a cookie-session concern), so this must be designed carefully to stay secure.
7. **Pricing/route depend on the Mapbox secret token** (server-side only) — mobile must call the
   backend for pricing/route, never hold the secret.
8. **Google OAuth is a web redirect flow** — mobile needs native OAuth (PKCE) or a
   backend-mediated code exchange.

None of these are "remove the feature"; each has a preserved business requirement and a mobile
plan in the parity matrix.
