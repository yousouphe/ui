# Store & app asset checklist

Design tasks to produce before the first store submission. These are **binary image assets**, so they
are not committed as source here — track them in the design store and reference the final files at
build/submit time. The brand palette is sky-blue `#0b6ec9` on `#eaf5ff` (see `src/theme/theme.ts`).

## In-app (referenced by app.json / app.config.js)
- [ ] `assets/icon.png` — 1024×1024, no alpha needed for iOS (Expo generates sizes). Add `"icon"`
      to app.json once produced.
- [ ] `assets/adaptive-icon.png` — 1024×1024 foreground (safe zone ~66%); background is `#0b6ec9`.
      Add as `android.adaptiveIcon.foregroundImage`.
- [ ] `assets/splash.png` — ~1284×2778, centered wordmark on `#eaf5ff` (`splash.resizeMode: contain`).
      Add as `splash.image`. (Today the splash is a wordmark rendered in `App.tsx`; the native splash
      image is still needed for the pre-JS frame.)
- [ ] `assets/notification-icon.png` — 96×96 white-on-transparent (Android status bar). Wire into the
      `expo-notifications` plugin `icon` option.

> Until the PNGs exist, Expo falls back to defaults so `expo start` / EAS builds still succeed; the
> config intentionally does **not** hard-reference missing files.

## Google Play
- [ ] App icon 512×512 (32-bit PNG with alpha).
- [ ] Feature graphic 1024×500.
- [ ] Phone screenshots ×2–8 (recommend 6): rider compare, live tracking, chat, payment/receipt,
      rider offers, rider wallet.
- [ ] (Optional) 7"/10" tablet screenshots.

## App Store
- [ ] 6.7" iPhone screenshots (1290×2796) ×3–10.
- [ ] 6.5" iPhone screenshots (1242×2688).
- [ ] (If iPad kept) 12.9" iPad Pro screenshots.
- [ ] App icon is generated from `assets/icon.png` — no separate upload.

## Capture tip
Screens can be captured from an EAS **preview** build against the staging API with seeded demo data,
in both en and ha, light theme. Keep the frame content-first (avoid a screen dominated by the nav).
