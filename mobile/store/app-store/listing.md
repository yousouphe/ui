# App Store — Store Listing (Aike)

Draft copy for App Store Connect. Replace `⟨…⟩` placeholders before submission. No secrets here.

- **App name:** Aike
- **Bundle identifier:** `ng.aike.app`
- **Primary language:** English (Nigeria)
- **Primary category:** Navigation (secondary: Business)
- **Support URL:** ⟨https://entrepoints.ng/support⟩
- **Marketing URL:** ⟨https://entrepoints.ng⟩
- **Privacy policy URL:** ⟨https://entrepoints.ng/privacy⟩

## Subtitle (≤ 30 chars)
> Deliveries, tracked live

## Promotional text (≤ 170 chars)
> Request a rider in a few taps, watch your delivery on the map, chat and pay securely — across
> Nigeria, in English and Hausa.

## Description (≤ 4000 chars)
> Aike connects senders with nearby riders for fast, trackable deliveries across Nigeria.
>
> SENDERS
> • Enter pickup and drop-off, choose a vehicle (bike, car or van) and see the price up front.
> • Compare available riders by distance, rating and ETA, then send your request.
> • Track your rider live from pickup to hand-off.
> • Chat in the app and call your rider when needed.
> • Pay securely with Paystack and keep every receipt.
> • Rate your delivery and report a problem if anything goes wrong.
>
> RIDERS
> • Go online to receive nearby delivery requests with the suggested fee and route.
> • Accept, navigate, update the delivery status and confirm payment.
> • Track earnings, add your bank account and request withdrawals.
>
> Your location is only shared while you are online or on an active delivery. Card details are never
> stored in the app — payments are processed by Paystack.

## Keywords (≤ 100 chars, comma-separated)
> delivery,dispatch,rider,logistics,courier,send package,track,Nigeria,errand,Aike

## Screenshots (produce to spec — see ../assets-checklist.md)
- 6.7" iPhone (1290×2796) and 6.5" iPhone (1242×2688) — required sizes.
- Suggested set mirrors Play: rider compare, live tracking, chat, payment/receipt, rider offers,
  rider wallet.
- 12.9" iPad Pro shots if iPad support is kept (`supportsTablet: true`).

## App Privacy (nutrition labels)
See `../data-safety.md` — the same data map drives the App Privacy answers (Data Used to Track You:
none; Data Linked to You: contact info, location, financial info, identifiers, user content).

## Background location justification
Provide the review note: riders enable location sharing via an explicit in-app online toggle; it runs
only while online or on an active delivery so senders can track the trip. Include a demo account
(rider + sender) and steps for the reviewer.

## Review notes / demo
- Demo sender: ⟨email + password⟩. Demo rider (KYC pre-approved): ⟨email + password⟩.
- Google Sign-In: provide a test Google account or note it is optional (email/password covers review).
- Paystack is in test mode for review; provide a test card if the reviewer reaches payment.

## Release
- **TestFlight** internal → external beta, then a **phased release** on the App Store.
