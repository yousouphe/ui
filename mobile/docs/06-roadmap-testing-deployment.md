# Aike Mobile — Phased Roadmap, Testing, Deployment & Maintenance

This program is delivered as a **sequence of reviewable PRs**, not one giant drop. Each phase is
independently testable and does not disturb the running web app.

## Roadmap (maps to the spec's phases)

| Phase | Deliverable | PR scope | Status |
|---|---|---|---|
| **1. Discovery & Audit** | Audit, feature inventory, **parity matrix**, architecture, API specs, DB impact, nav/screens/design, roadmap; `mobile/` scaffold; `shared/` contracts; gitignore | **this PR** | ✅ done here |
| **2. Stabilisation** | Fix backend/API/auth/payment/booking-state issues blocking mobile (JSON consistency, orphan `rider/sw.js`, transition guards audit) | backend-only | next |
| **3. API layer** | `logistics-app/api/v1/**` (bearer auth, `api_tokens`/`device_tokens`/`idempotency_keys` migration), all parity endpoints, validation, rate limits, docs, backend tests | backend-only | — |
| **4. Design system** | RN components, theme, navigation shell, splash/offline, i18n wiring | mobile-only | — |
| **5. Sender app** | Full sender workflow against the API + tests | mobile-only | — |
| **6. Rider app** | Full rider workflow incl. availability, jobs, earnings, background location + tests | mobile-only | — |
| **7. Integrations** | Maps, FCM/APNs push, calling (dialler + in-app if infra), Paystack, support | mobile + minimal backend | — |
| **8. Parity validation** | Every matrix row verified implemented/retained/deprecated-with-reason | cross-cutting | — |
| **9. Security & performance** | Token/IDOR/rate-limit review; battery/network/device perf; low-end Android | cross-cutting | — |
| **10. Deployment** | Signed builds (EAS), store assets, privacy disclosures, monitoring, rollback | release | — |

**Gate:** Phases 5–7 cannot start a given feature until its API (Phase 3) exists and is tested
independently.

## Testing plan

- **Backend API (Phase 3):** PHP endpoint tests for each `/api` route — auth required, role/IDOR
  enforced, validation, idempotency (no duplicate booking/payment/withdrawal), rate limits, correct
  status codes, envelope shape. Run against the local MariaDB test DB (as used for the web tests).
- **Shared contracts:** unit tests that `shared/constants` match the backend enums (guard against
  drift).
- **Mobile unit/component:** Jest + React Native Testing Library for components, hooks, reducers,
  the API client (retry/refresh/timeout/idempotency), offline queue, connectivity recovery.
- **Mobile E2E:** Detox (or Maestro) flows for: registration, login, password recovery, role
  restriction, address search, current location, map-pin, booking creation, rider discovery, fee
  calc, accept/reject, status transitions, live tracking, calls, notifications, payments,
  **failed payment**, **duplicate payment attempt**, withdrawals, cancellation, offline operation,
  connectivity restoration, expired session, permission denial, background location, low-battery,
  slow network, API errors, server downtime, **app restart during an active delivery**.
- **Devices:** matrix across Android 8–14 and iOS 15–latest, small/large screens, one low-memory
  device. Manual pass on a real low-end Android for perf/battery.
- **Consistency:** automated check that a change on mobile appears on web and vice-versa (same DB).

## Deployment

- **Builds:** EAS Build (cloud) for Android (`.aab`) and iOS (`.ipa`) — no local Xcode/Android SDK
  needed. Separate `mobile` build pipeline; **web deploy config untouched**.
- **Environments:** `mobile/.env` (git-ignored) with `API_BASE_URL`, public Mapbox token, push
  project ids. **No secrets** (secret Mapbox/Paystack/webhook keys stay backend-only).
- **Google Play:** package id, adaptive icon, screenshots, data-safety form (location background
  use justified for riders only), privacy policy URL, staged rollout.
- **App Store:** bundle id, icon, screenshots, App Privacy nutrition labels, background-location
  justification, TestFlight beta, phased release.
- **Rollback:** EAS channels + OTA updates for JS-only fixes; store rollback via previous build;
  API is versioned (`/api/v1`) so old app versions keep working during a rollout.

## Maintenance & monitoring
- Crash/error reporting (Sentry) in the app; API error logging via existing `error_log`.
- Uptime/health checks on `/api/v1/health` and `ping.php`.
- Push delivery + payment/withdrawal reconciliation dashboards (web/admin).
- API versioning policy; contract tests in CI to prevent web/mobile drift.
- Dependency and OS-target review each release; keep `/api/v1` backward-compatible.

## Acceptance (feature-parity rule)
Complete only when: every matrix row is implemented / intentionally web-retained /
deprecated-with-reason; all sender + rider workflows pass; payment & withdrawal reconciliation
pass; web↔mobile data stays in sync; offline/reconnect pass; background tracking is secure +
battery-efficient; **no secrets in the app**; existing web users sign in with **no duplicate
account**; existing bookings/payments/history/profiles remain accessible; **web app still works**.
