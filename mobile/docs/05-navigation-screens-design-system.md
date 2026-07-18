# Aike Mobile — Navigation, User Flows, Screen Inventory & Design System

## Navigation structure

```
Root (auth-gated)
├─ Auth stack (logged-out)
│   Splash → Onboarding/Language → Login → Register(role) → ForgotPassword → CompleteProfile(OAuth)
│
├─ Sender tabs (role = sender)
│   ├─ Home (create delivery)      ├─ Orders (Active / Unpaid / History)
│   ├─ Notifications               └─ Profile/Support
│   └─ (modal) Booking flow: Address → Vehicle+Price → Riders → Track → Pay → Rate
│
└─ Rider tabs (role = rider)
    ├─ Home (online toggle + offers)  ├─ Jobs (Active / Pending / Completed)
    ├─ Wallet (balance/ledger/withdraw)├─ Notifications
    └─ Profile/KYC/Training/Support
```
- **Role-based root:** after login the token's server-verified role selects the sender or rider
  tab tree. Admin/ops stays on web.
- **Android back button:** hardware back respected per stack; destructive/steps confirm before pop.
- **Deep links:** push notifications open the exact screen (booking id, offer, wallet, complaint).

## Sender user flow (happy path)
```
Home → search pickup → search dropoff (NG-restricted, as-you-type; map-pin optional)
     → route preview (distance/ETA) → pick vehicle → backend fee preview
     → Create booking (idempotent) → Riders (online-first, ≤10, per-rider fee)
     → send request → rider accepts → Track (live) → chat/call
     → Delivered → Pay (Paystack, verified server-side) → rider confirms payment → Rate
```
Edge flows: change delivery address (reprice), cancel (blocked after handover), rebook, complaint,
payment interruption (idempotency → no duplicate), rider declines (re-pick from list).

## Rider user flow (happy path)
```
Onboarding → KYC submit → (admin approves) → Home
Go online (KYC-gated) → background location starts → receive offer (push + list)
  → review trip + fee → accept (cap ≤3) → navigate to pickup
  → arrived_at_pickup → package_received → in_transit → delivered
  → confirm payment received → earnings credited (85%) → Wallet → add bank → withdraw
Go offline → background location stops
```

## Screen inventory (build targets)

**Auth (7):** Splash/Offline, Language, Login, Register(role picker + rider KYC fields),
ForgotPassword, ResetPassword, CompleteProfile.

**Sender (12):** Home/Create, AddressSearch, MapPinAdjust, VehicleAndPrice, Riders,
BookingTrack, Chat, Pay, Orders(Active/Unpaid/History tabs), OrderDetail, Rate, Complaint/Support.

**Rider (13):** Home(online+offers), OfferDetail, ActiveJob(workflow actions), Navigate,
Jobs(Active/Pending/Completed), JobDetail, Chat, Wallet, BankAccount, Withdraw, KYC, Training,
Profile.

**Shared (5):** Profile, Notifications, Settings(locale/dark-mode/permissions), Support/Contact,
Error/Empty states.

Total ~37 screens. Each has: **skeleton loading**, **empty state**, **error state with retry**,
and confirmation on destructive actions.

## Design system

- **Brand:** Aike sky-blue (`#0b6ec9` primary, `#16a34a` success, `#b45309` warning) on light
  gradient (`#eaf5ff→#eef8ff`) — mirrors the web/PWA so the two clients feel like one product.
  Wordmark uses a distinctive weight-800 letter-spaced treatment (same as the splash).
- **Typography:** system font stack (San Francisco / Roboto) for zero-download performance; scalable
  with OS font-size (accessibility).
- **Spacing/`radius`:** 4-pt spacing scale; 12–20px radii; large 44px+ touch targets.
- **Components:** Button (primary/secondary/danger), StatusBadge (booking/payment status colours
  from `shared/constants`), RiderCard, BookingCard, MoneyText (₦), Skeleton, EmptyState,
  ErrorState, ConnectivityBanner, Splash/Offline overlay.
- **Status colours** map 1:1 to backend statuses (single source in `shared/constants/statuses`).
- **Dark mode:** implemented only if it can be kept fully consistent (theme tokens ready; ship when
  audited across all screens — otherwise light-only, per spec).
- **Motion:** restrained; honours `reduce motion`; no heavy animations on low-end devices.
- **Accessibility:** WCAG AA contrast, screen-reader labels, keyboard/focus order, dynamic type.
