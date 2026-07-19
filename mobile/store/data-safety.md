# Data collection disclosure (Play Data Safety + App Store App Privacy)

One source of truth for both stores' forms. It reflects what the app *actually* does (verified
against the codebase). Keep it in sync when data flows change.

**Global facts**
- All API traffic is over **HTTPS** (encrypted in transit).
- **No card details** are collected or stored by the app — payments go to **Paystack**.
- **No advertising / no third-party tracking SDKs.** Nothing is used to track users across apps →
  Play "data shared": none for advertising; App Store "Data used to track you": **None**.
- Users can request **account and data deletion** (see privacy policy); provide an in-app or web
  route and the URL on the Play "data deletion" question.

## What is collected, why, and whether it leaves our backend

| Data type | Collected? | Purpose | Shared with third parties | Linked to user |
|---|---|---|---|---|
| Name | Yes | Account, delivery hand-off, KYC | No* | Yes |
| Email address | Yes | Account, login, receipts | No | Yes |
| Phone number | Yes | Account, sender↔rider contact (device dialler) | Shown to the other party on a delivery | Yes |
| Precise location | Yes (rider: background while online/active; sender: foreground for pickup/track) | Matching, live tracking | No (our backend only) | Yes |
| Payment info | No card data in app | Fare payment | Processed by **Paystack** | Yes (reference only) |
| Bank account (riders) | Yes | Payouts/withdrawals | **Paystack** (name resolution + transfer) | Yes |
| Government ID / KYC documents | Yes (riders) | Identity verification (regulatory) | No* (stored on our backend, admin-reviewed) | Yes |
| Photos (KYC docs, avatar) | Yes (riders) | Verification / profile | No | Yes |
| Messages (in-app chat) | Yes | Sender↔rider coordination on a delivery | No | Yes |
| Device push token | Yes | Delivery/booking notifications | **Expo / FCM / APNs** (delivery transport) | Yes |
| App diagnostics / crash logs | Yes (if Sentry enabled) | Stability | **Sentry** (processor) | Pseudonymous |

\* "No" = not sold or shared for others' independent use. Vendors listed (Paystack, Expo/FCM/APNs,
Sentry) are **processors** acting on our behalf; disclose them as such where the form asks.

## Play Data Safety — answers cheat-sheet
- Collects data: **Yes**. Shares data: **Yes** (with processors above; not for advertising).
- Encrypted in transit: **Yes**. Data deletion request path: **Yes** — ⟨URL⟩.
- Location → Precise: Yes; purpose App functionality; background use justified (rider online only).
- Financial info → Payment (via Paystack), Bank account (payouts).
- Personal → Name, Email, Phone, Government ID.
- Photos/Videos → for KYC/profile. Messages → In-app.
- App activity / Device IDs → push token, diagnostics.

## App Store App Privacy — answers cheat-sheet
- **Data Used to Track You:** None.
- **Data Linked to You:** Contact Info (name, email, phone), Location (precise), Financial Info
  (payment, bank), User Content (photos/KYC, messages), Identifiers (push token), Diagnostics.
- **Data Not Linked to You:** none material (crash diagnostics may be pseudonymous).

> Before submission: confirm the live vendor list (Paystack, Expo push, Mapbox for geocoding — note
> Mapbox receives typed address queries + coarse coordinates, not identity — and Sentry if enabled),
> and that each has a signed DPA where required.
