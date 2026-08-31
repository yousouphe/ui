<?php
// Public privacy policy page (Google Play requires a privacy policy URL for apps that collect
// personal data). Deliberately standalone - no session/locale/DB dependency - so it stays
// reachable even if the rest of the app is degraded, and needs no maintenance coupling to
// config/functions.php or the lang files.
$lastUpdated = 'August 30, 2026';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Privacy Policy - Aike</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
.cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.text-soft{color:#5c7a91}
h2{margin-top:2rem;font-size:1.25rem;font-weight:700}
h3{margin-top:1.25rem;font-size:1.05rem;font-weight:700}
ul{padding-left:1.25rem}
li{margin-bottom:.35rem}
a{color:#0b4f6c}
</style>
</head><body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="cardx p-4 p-lg-5">
        <h1 class="h2 fw-bold">Privacy Policy</h1>
        <p class="text-soft">Last updated: <?= htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8') ?></p>

        <p>Aike ("Aike", "we", "us", or "our") operates the Aike mobile application and the
        aike.ng website (together, the "Service"), a delivery and logistics platform connecting
        senders with delivery riders in Nigeria. This Privacy Policy explains what information we
        collect, how we use it, who we share it with, and the choices you have.</p>

        <p>By creating an account or using the Service, you agree to the collection and use of
        information as described in this policy.</p>

        <h2>1. Information We Collect</h2>

        <h3>1.1 Account &amp; Profile Information</h3>
        <ul>
          <li>Full name, email address, phone number, and password (stored as a salted hash, never in plain text).</li>
          <li>Your account role (sender, rider, or admin).</li>
          <li>Your full name cannot be changed after your account is first created; if you need a correction, contact support.</li>
        </ul>

        <h3>1.2 Rider Verification (KYC) Documents</h3>
        <p>To become a delivery rider, we collect a government-issued ID document, proof of
        address, vehicle registration document, and driving license. These are used solely to
        verify your identity and eligibility to deliver on the platform and are reviewed by our
        admin team.</p>

        <h3>1.3 Location Data</h3>
        <p>With your permission, we collect precise (GPS) and approximate location data from
        riders' devices while a delivery is active, in order to:</p>
        <ul>
          <li>Match nearby riders to delivery requests;</li>
          <li>Calculate routes, distance, and delivery pricing;</li>
          <li>Let senders track their delivery in real time.</li>
        </ul>
        <p>Pickup and drop-off addresses entered by senders are also collected to plan and price
        each delivery.</p>

        <h3>1.4 Delivery &amp; Booking Information</h3>
        <p>Item descriptions, delivery instructions, booking status and history, delivery photos
        (where applicable), ratings, and complaint/feedback details you submit.</p>

        <h3>1.5 Payment &amp; Payout Information</h3>
        <ul>
          <li>Payments for deliveries are processed by our payment partner, Paystack. We do not
          collect or store your card number, PIN, or bank login details - these are handled
          directly by Paystack.</li>
          <li>For riders, we collect the bank account number and bank name used for payouts. The
          resolved account holder name must match your Aike profile name before a payout account
          can be saved.</li>
          <li>Any change to a rider's payout bank account, and any withdrawal request, requires a
          one-time verification code sent by SMS and email before it takes effect.</li>
        </ul>

        <h3>1.6 Communications</h3>
        <ul>
          <li>Messages sent through the in-app chat between senders and riders for a booking.</li>
          <li>Order and delivery notifications sent by push notification, SMS, WhatsApp, and email
          (for example, delivery status updates or a rider's contact details for the delivery
          recipient).</li>
        </ul>

        <h3>1.7 Technical &amp; Device Information</h3>
        <ul>
          <li>Push notification device tokens, used to deliver order updates to your device.</li>
          <li>IP address, used for basic security and rate-limiting (for example, to prevent abuse
          of login and account-recovery forms).</li>
          <li>App version and basic device information for diagnosing crashes and compatibility issues.</li>
        </ul>

        <h2>2. How We Use Your Information</h2>
        <ul>
          <li>To create and manage your account and provide the Service;</li>
          <li>To match riders with delivery requests and calculate accurate routes and pricing;</li>
          <li>To process payments and rider payouts, and to verify rider identity and bank details;</li>
          <li>To send booking, payment, and delivery notifications;</li>
          <li>To provide customer support and investigate complaints;</li>
          <li>To detect, prevent, and respond to fraud, abuse, and security incidents;</li>
          <li>To comply with legal, tax, and accounting obligations.</li>
        </ul>

        <h2>3. Data Encryption &amp; Security</h2>
        <p>All data transmitted between the Aike app and our servers is encrypted in transit using
        HTTPS/TLS; the app does not permit unencrypted (cleartext) network connections. Passwords
        are stored as salted hashes. Access to rider KYC documents and financial data is
        restricted to authorized personnel. No method of transmission or storage is 100% secure,
        but we work to protect your information using industry-standard practices.</p>

        <h2>4. Sharing Your Information</h2>
        <p>We do not sell your personal information. We share information only as needed to
        operate the Service, with the following categories of service providers:</p>
        <ul>
          <li><strong>Paystack</strong> - payment processing and rider payouts;</li>
          <li><strong>Google Places API</strong> - address search and autocomplete;</li>
          <li><strong>Mapbox</strong> - maps, routing, and distance/ETA calculation;</li>
          <li><strong>EbulkSMS</strong> - SMS and WhatsApp delivery notifications and verification codes;</li>
          <li><strong>Firebase Cloud Messaging</strong> - push notifications;</li>
          <li>Delivery counterparties - a sender and the assigned rider are shown each other's name,
          phone number, and delivery-relevant location so the delivery can be completed;</li>
          <li>Law enforcement or regulators, where required by law or to protect the rights, safety,
          or property of Aike, our users, or the public.</li>
        </ul>

        <h2>5. Data Retention</h2>
        <p>We retain your information for as long as your account is active and as needed to
        provide the Service. Payment and transaction records are retained for the period required
        by applicable tax and accounting laws, even after an account is closed. When you request
        account deletion, your account is deactivated immediately and remaining data is deleted or
        anonymized once any legally required retention period has passed.</p>

        <h2>6. Your Rights &amp; Choices</h2>
        <ul>
          <li><strong>Access &amp; correction:</strong> you can view and update most of your profile
          information in the app. Your full name is locked after registration; contact support to
          correct it.</li>
          <li><strong>Account deletion:</strong> you may request deletion of your account and data
          at any time at
          <a href="https://aike.ng/aike/remove_my_account">aike.ng/aike/remove_my_account</a>,
          or from the "Request Account Deletion" option in the app's profile screen.</li>
          <li><strong>Location permission:</strong> you can disable location access at any time in
          your device settings, though this will prevent riders from receiving delivery requests
          and senders from tracking a delivery in real time.</li>
          <li><strong>Notifications:</strong> you can disable push notifications in your device settings.</li>
        </ul>

        <h2>7. Children's Privacy</h2>
        <p>The Service is not directed to children under 18. We do not knowingly collect personal
        information from children. If you believe a child has provided us with personal
        information, please contact us so we can remove it.</p>

        <h2>8. Changes to This Policy</h2>
        <p>We may update this Privacy Policy from time to time. Material changes will be reflected
        by updating the "Last updated" date above. Continued use of the Service after a change
        constitutes acceptance of the revised policy.</p>

        <h2>9. Contact Us</h2>
        <p>If you have questions about this Privacy Policy or how your data is handled, contact us
        at <a href="mailto:support@aike.ng">support@aike.ng</a>.</p>

        <hr class="my-4">
        <a class="btn btn-outline-secondary" href="/">Back to Home</a>
      </div>
    </div>
  </div>
</div>
</body></html>
