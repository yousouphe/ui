# Aike Mobile — Phase 10: Deployment, Monitoring & Rollback

Roadmap Phase 10: **signed builds (EAS), store assets, privacy disclosures, monitoring, rollback.**
This is the operational runbook. It never touches the web deploy config — `mobile/` builds on its own
pipeline. **No secrets live in the repo:** everything sensitive is an EAS secret or a local key path.

## 0. What Phase 10 scaffolding added
- `eas.json` — build profiles (development / preview / production) + submit config.
- `app.config.js` — dynamic release layer over `app.json`: injects non-secret env into `extra`,
  wires EAS Update (OTA), adds notification + image-picker plugins and Android/iOS permission strings.
- `.env.example` — the full non-secret env surface (API base, public Mapbox token, EAS project id,
  Google client ids).
- `store/` — Play + App Store listing copy, `data-safety.md`, `assets-checklist.md`.
- `legal/privacy-policy.md` — privacy policy draft for the store URL.

Still required from humans before shipping (tracked in `store/assets-checklist.md` and the listings):
final icon/splash/screenshot assets, real bundle signing credentials, the hosted privacy-policy URL,
store accounts, and the values for every `⟨…⟩` / `REPLACE_ME` placeholder.

## 1. One-time setup
1. `npm i -g eas-cli` and `eas login` (Expo account that owns the project).
2. `eas init` in `mobile/` — creates the EAS project and its **project id**; put it in
   `AIKE_EAS_PROJECT_ID` (drives both push and the OTA update URL).
3. Store **secrets** (never commit): `eas secret:create` for anything you don't want in `eas.json`
   env — e.g. a non-public API base, or push credentials. Keep the Google Play service-account JSON
   and the App Store Connect API key **outside the repo** and point `eas.json` submit at those paths.
4. Credentials: let EAS manage the Android keystore and iOS signing (`eas credentials`), or supply
   your own. Record where the Android keystore lives — losing it blocks future Play updates.

## 2. Environments & config flow
`eas.json` `build.<profile>.env` → `app.config.js` reads `process.env` → `extra` → `src/config.ts`
reads `extra`/`process.env` on device. Only **non-secret** values ride this path.

| Profile | Channel | API base | Artifact | Use |
|---|---|---|---|---|
| development | development | `10.0.2.2` (emulator → host) | apk / simulator | Dev Client, day-to-day |
| preview | preview | staging | apk (internal) | QA, screenshots, UAT |
| production | production | prod | **aab** / ipa | store release |

`appVersionSource: "remote"` + `autoIncrement` means EAS owns the Android `versionCode` / iOS
`buildNumber`; bump the human-facing `version` in `app.config.js` (`APP_VERSION`) per release.

## 3. Build & submit
```bash
# from mobile/
eas build --profile preview   --platform all      # QA build
eas build --profile production --platform android  # .aab
eas build --profile production --platform ios      # .ipa

# submit (uses eas.json submit.production; keys supplied out-of-repo / via secrets)
eas submit --profile production --platform android  # → Play internal track, draft
eas submit --profile production --platform ios      # → App Store Connect / TestFlight
```
Android first release goes to the **internal** track as a **draft** (per `eas.json`), then promote:
internal → closed → production **staged rollout** (10% → 50% → 100%). iOS: TestFlight → phased release.

## 4. OTA updates (EAS Update) vs new store build
- **JS/asset-only fix** (no new native module, permission, or config): ship instantly without a
  store review:
  ```bash
  eas update --branch production --message "fix: <what>"
  ```
  `runtimeVersion` is the **appVersion policy**, so an OTA update only reaches builds with the same
  `version` — it can never land JS that expects native code the installed binary doesn't have.
- **Native change** (new dependency with native code, permission, or app config): cut a **new store
  build** and bump `APP_VERSION`.

## 5. Rollback
- **OTA regression:** republish the previous good update to the branch, or roll back the channel
  pointer:
  ```bash
  eas update --branch production --message "rollback to <prev>"   # re-publish known-good JS
  # or use `eas channel:edit` / the dashboard to point production at the previous update group
  ```
  Because the API is versioned (`/api/v1`), older JS keeps working during the switch.
- **Bad store build:** halt the staged rollout in the Play Console; on iOS remove from sale / expedite
  a fixed build. Keep the previous build available to promote back.
- **Backend contract change:** never break `/api/v1` for shipped apps — add fields/versions
  additively so old app versions keep working through a rollout (see the maintenance note in doc 06).

## 6. Monitoring
- **Crash/error reporting (Sentry) — recommended, optional to wire now.** Add `sentry-expo` +
  `@sentry/react-native`, put a **public DSN** in the non-secret env (`AIKE_SENTRY_DSN`), init once in
  `App.tsx`, and add the `sentry-expo` plugin + EAS build hook for source maps. Guard init on the DSN
  being present so dev/local runs stay quiet. (Deferred here to avoid shipping an unconfigured,
  untested dependency — it is a drop-in when the DSN exists.)
- **Backend:** API errors already go to `error_log`; keep health checks on `/api/v1/health` and
  `ping.php`. Watch push-delivery and payment/withdrawal reconciliation on the admin/web side.
- **Release health:** after each rollout, watch crash-free-session rate and the API error rate before
  advancing the staged rollout percentage.

## 7. Pre-submission checklist
- [ ] All `⟨…⟩` / `REPLACE_ME` placeholders filled (env, listings, privacy policy, demo accounts).
- [ ] Icons/splash/screenshots produced (`store/assets-checklist.md`) and added to config/listing.
- [ ] Privacy policy hosted; URL in both listings; `store/data-safety.md` mirrored into both forms.
- [ ] Background-location disclosure video (Play) + reviewer note (App Store) prepared.
- [ ] Phase 9 device pass green (doc 08), including the on-device Google OAuth + KYC upload checks.
- [ ] Paystack in test mode for review with a demo card; demo sender + KYC-approved rider provided.
- [ ] Production build smoke-tested against the prod API before promoting past the first rollout step.
