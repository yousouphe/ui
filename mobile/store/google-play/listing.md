# Google Play — Store Listing (Aike)

Draft copy for the Play Console listing. Replace the `⟨…⟩` placeholders with the final values from
the business before submission. No secrets here.

- **App name:** Aike
- **Package name:** `ng.aike.app`
- **Default language:** English (Nigeria) — en-NG. Also provide Hausa (ha) where the console allows.
- **Category:** Maps & Navigation (alt: Business)
- **Contact email:** ⟨support@entrepoints.ng⟩
- **Website:** ⟨https://entrepoints.ng⟩
- **Privacy policy URL:** ⟨https://entrepoints.ng/privacy⟩ (host the text in `../legal/privacy-policy.md`)

## Short description (≤ 80 chars)
> On-demand delivery in Nigeria — request a rider, track live, pay securely.

## Full description (≤ 4000 chars)
> Aike connects senders with nearby riders for fast, trackable deliveries across Nigeria.
>
> For senders
> • Enter pickup and drop-off, pick a vehicle (bike, car or van) and see the price up front — no
>   surprises.
> • Compare available riders by distance, rating and ETA, then send your request.
> • Track your rider live on the map from pickup to hand-off.
> • Chat with your rider in the app and call them when you need to.
> • Pay securely with Paystack and keep every receipt.
> • Rate your delivery and report a problem if anything goes wrong.
>
> For riders
> • Go online when you want work and receive nearby delivery requests.
> • See the suggested fee, pickup and drop-off before you accept.
> • Navigate with your favourite maps app, update the delivery status, and confirm payment.
> • Track your earnings, add your bank account and request withdrawals.
>
> Built for Nigeria, in English and Hausa. Your location is only shared while you are online or on
> an active delivery. We never store card details in the app — payments are handled by Paystack.

## Graphic assets (produce to spec — see ../assets-checklist.md)
- App icon 512×512 PNG (32-bit, alpha).
- Feature graphic 1024×500 PNG/JPG.
- Phone screenshots: 2–8, 16:9 or 9:16, min 320px. Suggested set: rider discovery/compare, live
  tracking map, in-app chat, payment/receipt, rider offers, rider wallet/withdrawal.
- (Optional) 7" and 10" tablet screenshots.

## Content rating
Complete the IARC questionnaire. Expect **Everyone / PEGI 3** — no violence, no user-to-user
mature content; in-app chat is limited to the two parties on a delivery.

## Data safety
See `../data-safety.md` for the exact Data Safety form answers (what is collected, purpose, sharing,
encryption in transit, deletion).

## Background location declaration
Riders use background location **only while online or on an active delivery**, to let senders track
the trip and to match nearby jobs. Record the in-app demo video showing the online-toggle → prominent
disclosure → location use, as required by the Play background-location policy.

## Release
- First release via the **internal testing** track (matches `eas.json` submit `track: internal`,
  `releaseStatus: draft`), then closed testing, then a **staged production rollout** (e.g. 10% → 50%
  → 100%).
