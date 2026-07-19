# Aike Mobile (React Native + Expo)

Native Android & iOS clients for the Aike logistics platform. This app is a **thin client** over
the existing backend in [`../logistics-app`](../logistics-app): it holds no business logic and no
secrets, and talks to the backend only through the versioned JSON API (`/api/v1`). The web app and
this app run independently on the **same** backend and database.

> **Status: Phase 4 (design system + navigation shell).** The backend `/api/v1` layer exists
> (see [`../logistics-app/api`](../logistics-app/api)). This app now has: secure token storage,
> the token-aware API client + typed service layer (`src/api`), an auth context that restores the
> session and selects the sender/rider tree by server-verified role, i18n (en/ha), core
> design-system components (`src/components`), the branded splash/offline gate, and a role-based
> navigation shell (`src/navigation`) with working Login + sender/rider tab screens wired to the
> real API. The full booking wizard, rider job workflow, tracking map, chat, payments and push
> integrations are Phases 5-7 — see [`docs/06-roadmap-testing-deployment.md`](docs/06-roadmap-testing-deployment.md).
> No mock data ships in the production app.

## Directory layout
```
mobile/
├─ docs/           # Phase-1 deliverables: audit, feature-parity matrix, architecture, API specs…
├─ src/
│  ├─ api/         # API client (bearer auth, refresh, timeout, idempotency)
│  ├─ config.ts    # non-secret runtime config
│  ├─ hooks/       # connectivity, etc.
│  ├─ navigation/  # (phase 4) role-based nav shell
│  ├─ screens/     # (phase 5/6) sender & rider screens
│  ├─ components/  # (phase 4) design-system components
│  ├─ storage/     # secure token store (Keychain/Keystore)
│  └─ theme/       # design tokens (mirror the web look)
├─ assets/         # icons, splash art
├─ tests/          # Jest + RNTL / Detox specs
├─ app.json        # Expo config (permissions, bundle ids, plugins)
├─ package.json    # mobile-only dependencies (separate from web/backend)
└─ .env.example    # NON-SECRET config only
```
Shared, non-sensitive contracts (status/vehicle/role identifiers, API types) live in
[`../shared`](../shared) and are imported via the `@shared/*` path alias.

## Prerequisites
- Node 18+ and npm
- Expo CLI (`npx expo`)
- For device builds: an Expo/EAS account (cloud builds — no local Xcode/Android SDK required)

## Install & run (does not affect the web app)
```bash
cd mobile
cp .env.example .env         # fill in NON-SECRET values (API base URL, public Mapbox token)
npm install
npm start                    # Expo dev server; press a for Android, i for iOS, or scan QR
```
The web app continues to run from `../logistics-app` unchanged; the mobile toolchain lives
entirely inside `mobile/` and never touches the web build/deploy.

## Scripts
- `npm run typecheck` — TypeScript
- `npm run lint` — ESLint
- `npm test` — Jest + React Native Testing Library
- `npm run android` / `npm run ios` — local native run (requires SDKs)

## Build & release (Phase 10)
- `eas build -p android` → `.aab`, `eas build -p ios` → `.ipa` (cloud).
- Store prep, privacy disclosures, and rollback are documented in
  [`docs/06-roadmap-testing-deployment.md`](docs/06-roadmap-testing-deployment.md).

## Security rules (non-negotiable)
- No secrets in the app or in `.env` committed to git. Secret Mapbox/Paystack tokens, webhook
  secrets and DB credentials stay in `logistics-app/config/env.php`.
- Tokens live in the OS keychain (`expo-secure-store`); no payment secrets or identity documents
  are cached on device.
- The client never computes pricing, rider eligibility, or state transitions — the backend does.
