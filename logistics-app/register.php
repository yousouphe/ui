<?php
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/emails.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=register');
}

require_guest();
require_once __DIR__ . '/config/db.php';

$errors = [];
$accountType = $_POST['account_type'] ?? 'sender';
$accountType = in_array($accountType, ['sender', 'rider'], true) ? $accountType : 'sender';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $errors = validate_required([
        'full_name' => 'Full name',
        'email' => 'Email',
        'phone' => 'Phone',
        'password' => 'Password',
        'password_confirmation' => 'Password confirmation'
    ], $_POST);

    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirmation'] ?? '');
    $vehicleType = $_POST['vehicle_type'] ?? 'bike';
    $vehicleType = in_array($vehicleType, ['bike', 'car', 'van'], true) ? $vehicleType : 'bike';
    $vehiclePlate = trim($_POST['vehicle_plate'] ?? '');
    $vehicleColor = trim($_POST['vehicle_color'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $stateOfOrigin = trim($_POST['state_of_origin'] ?? '');
    $lgaOfOrigin = trim($_POST['lga_of_origin'] ?? '');
    $hometown = trim($_POST['hometown'] ?? '');
    $nationalIdNumber = trim($_POST['national_id_number'] ?? '');
    $riderAddress = trim($_POST['address'] ?? '');
    $guarantorName = trim($_POST['guarantor_name'] ?? '');
    $guarantorPhone = trim($_POST['guarantor_phone'] ?? '');
    $guarantorAddress = trim($_POST['guarantor_address'] ?? '');
    $guarantorRelationship = trim($_POST['guarantor_relationship'] ?? '');

    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = t('register.error.invalid_email');
    if ($password && strlen($password) < 6) $errors['password'] = t('register.error.password_length');
    if ($password !== $confirm) $errors['password_confirmation'] = t('register.error.password_mismatch');

    if ($accountType === 'rider') {
        if ($vehiclePlate === '') $errors['vehicle_plate'] = t('register.error.vehicle_plate_required');
        if (empty($_FILES['kyc_document']['name'])) $errors['kyc_document'] = t('register.error.kyc_document_required');
        if (empty($_FILES['profile_photo']['name'])) $errors['profile_photo'] = t('register.error.profile_photo_required');

        if ($age === '' ) $errors['age'] = t('register.error.age_required');
        elseif (!ctype_digit($age) || (int)$age < 18 || (int)$age > 100) $errors['age'] = t('register.error.age_invalid');
        if ($nationalIdNumber === '') $errors['national_id_number'] = t('register.error.national_id_required');
        if ($stateOfOrigin === '') $errors['state_of_origin'] = t('register.error.state_of_origin_required');
        if ($lgaOfOrigin === '') $errors['lga_of_origin'] = t('register.error.lga_of_origin_required');
        if ($hometown === '') $errors['hometown'] = t('register.error.hometown_required');
        if ($riderAddress === '') $errors['address'] = t('register.error.address_required');
        if (empty($_FILES['proof_of_address']['name'])) $errors['proof_of_address'] = t('register.error.proof_of_address_required');

        if ($guarantorName === '') $errors['guarantor_name'] = t('register.error.guarantor_name_required');
        if ($guarantorPhone === '') $errors['guarantor_phone'] = t('register.error.guarantor_phone_required');
        if ($guarantorAddress === '') $errors['guarantor_address'] = t('register.error.guarantor_address_required');
        if ($guarantorRelationship === '') $errors['guarantor_relationship'] = t('register.error.guarantor_relationship_required');

        if (empty($_FILES['vehicle_document']['name'])) $errors['vehicle_document'] = t('register.error.vehicle_document_required');
        if (in_array($vehicleType, ['car', 'van'], true) && empty($_FILES['driving_license']['name'])) {
            $errors['driving_license'] = t('register.error.driving_license_required');
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors['email'] = t('register.error.email_exists');
    }

    $kycDocPath = null;
    $profilePhotoPath = null;
    $proofOfAddressPath = null;
    $vehicleDocumentPath = null;
    $drivingLicensePath = null;
    if (!$errors && $accountType === 'rider') {
        try {
            $kycDocPath = save_kyc_document($_FILES['kyc_document']);
        } catch (Throwable $e) {
            $errors['kyc_document'] = $e->getMessage();
        }
        try {
            $profilePhotoPath = save_uploaded_image($_FILES['profile_photo'], 'avatars', 'avatar', t('profile.avatar_label'));
        } catch (Throwable $e) {
            $errors['profile_photo'] = $e->getMessage();
        }
        try {
            $proofOfAddressPath = save_uploaded_image($_FILES['proof_of_address'], 'kyc', 'proof_address', t('register.proof_of_address_label'));
        } catch (Throwable $e) {
            $errors['proof_of_address'] = $e->getMessage();
        }
        try {
            $vehicleDocumentPath = save_uploaded_image($_FILES['vehicle_document'], 'kyc', 'vehicle_doc', t('register.vehicle_document_label'));
        } catch (Throwable $e) {
            $errors['vehicle_document'] = $e->getMessage();
        }
        if (in_array($vehicleType, ['car', 'van'], true)) {
            try {
                $drivingLicensePath = save_uploaded_image($_FILES['driving_license'], 'kyc', 'license', t('register.driving_license_label'));
            } catch (Throwable $e) {
                $errors['driving_license'] = $e->getMessage();
            }
        }
    }

    if (!$errors) {
        $newUserId = null;
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO users (full_name,email,phone,password_hash,role,status,avatar_path) VALUES (?,?,?,?,?,"active",?)');
            $stmt->execute([$fullName, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $accountType, $profilePhotoPath]);
            $newUserId = (int)$pdo->lastInsertId();

            if ($accountType === 'rider') {
                $stmt = $pdo->prepare('
                    INSERT INTO rider_profiles (
                        user_id, vehicle_type, availability_status, kyc_status,
                        kyc_id_document_path, kyc_vehicle_plate, kyc_vehicle_color,
                        kyc_age, kyc_state_of_origin, kyc_lga_of_origin, kyc_hometown,
                        kyc_national_id_number, kyc_address, kyc_proof_of_address_path,
                        kyc_guarantor_name, kyc_guarantor_phone, kyc_guarantor_address, kyc_guarantor_relationship,
                        kyc_vehicle_document_path, kyc_driving_license_path
                    )
                    VALUES (?, ?, "offline", "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $newUserId, $vehicleType, $kycDocPath, $vehiclePlate, $vehicleColor !== '' ? $vehicleColor : null,
                    (int) $age, $stateOfOrigin, $lgaOfOrigin, $hometown,
                    $nationalIdNumber, $riderAddress, $proofOfAddressPath,
                    $guarantorName, $guarantorPhone, $guarantorAddress, $guarantorRelationship,
                    $vehicleDocumentPath, $drivingLicensePath,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['form'] = t('register.error.fix_fields');
        }

        if (!$errors && $newUserId !== null) {
            // Same reasoning as login.php - a fresh account also needs a fresh session ID.
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => $newUserId,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'role' => $accountType,
                'profile_completed' => 1
            ];
            send_welcome_email($email, $fullName, $accountType);
            if ($accountType === 'rider') {
                notify_admins($pdo, 'New rider registration awaiting KYC review', '<p><strong>' . e($fullName) . '</strong> (' . e($email) . ') has registered as a rider and is awaiting KYC review.</p><p>Review it from the admin portal.</p>');
            }
            flash('success', $accountType === 'rider' ? t('register.success_rider') : t('register.success'));
            redirect_to($accountType === 'rider' ? 'rider/' : '/bookings');
        }
    }
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e(t('register.title')) ?></title>
  <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
    .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
    .form-control{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
    .form-control:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
    .text-soft{color:#5c7a91}
    .lang-switch{position:absolute;top:1rem;right:1rem;font-size:.85rem}
    .lang-switch a{color:#5c7a91;text-decoration:none}
    .lang-switch a.active{font-weight:700;color:#0f2c44}
  </style>
</head>
<body>
<div class="lang-switch">
  <a href="<?= e(url_path('set_locale?locale=en&redirect=register')) ?>" class="<?= current_locale() === 'en' ? 'active' : '' ?>">EN</a>
  &middot;
  <a href="<?= e(url_path('set_locale?locale=ha&redirect=register')) ?>" class="<?= current_locale() === 'ha' ? 'active' : '' ?>">HA</a>
</div>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-9">
      <div class="cardx p-4 p-lg-5">
        <div class="row g-4">
          <div class="col-lg-6">
            <h1 class="h2 fw-bold"><?= e(t('register.heading')) ?></h1>
            <p class="text-soft"><?= e(t('register.subheading')) ?></p>
            <?php if ($errors): ?><div class="alert alert-danger"><?= e(t('register.error.fix_fields')) ?></div><?php endif; ?>
            <a class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 mb-3" href="<?= e(url_path('auth/google_login.php')) ?>">
              <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 01-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 009 18z"/><path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 013.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 000 9c0 1.45.35 2.83.96 4.05l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 00.96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>
              <?= e(t('register.google_signup')) ?>
            </a>
            <div class="d-flex align-items-center gap-2 mb-3 text-soft small">
              <hr class="flex-grow-1"><span><?= e(t('common.or')) ?></span><hr class="flex-grow-1">
            </div>
            <ul class="nav nav-pills mb-3" role="tablist">
              <li class="nav-item">
                <button type="button" class="nav-link account-type-tab <?= $accountType === 'sender' ? 'active' : '' ?>" data-type="sender"><?= e(t('register.account_type_sender')) ?></button>
              </li>
              <li class="nav-item">
                <button type="button" class="nav-link account-type-tab <?= $accountType === 'rider' ? 'active' : '' ?>" data-type="rider"><?= e(t('register.account_type_rider')) ?></button>
              </li>
            </ul>
            <form method="post" novalidate enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="account_type" id="account_type" value="<?= e($accountType) ?>">
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.full_name_label')) ?></label>
                <input class="form-control" name="full_name" value="<?= e(old('full_name')) ?>">
                <?php if (!empty($errors['full_name'])): ?><div class="small text-danger mt-1"><?= e($errors['full_name']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.phone_label')) ?></label>
                <input class="form-control" name="phone" value="<?= e(old('phone')) ?>">
                <?php if (!empty($errors['phone'])): ?><div class="small text-danger mt-1"><?= e($errors['phone']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.email_label')) ?></label>
                <input class="form-control" type="email" name="email" value="<?= e(old('email')) ?>">
                <?php if (!empty($errors['email'])): ?><div class="small text-danger mt-1"><?= e($errors['email']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.password_label')) ?></label>
                <input class="form-control" type="password" name="password">
                <?php if (!empty($errors['password'])): ?><div class="small text-danger mt-1"><?= e($errors['password']) ?></div><?php endif; ?>
              </div>
              <div class="mb-4">
                <label class="form-label"><?= e(t('register.confirm_password_label')) ?></label>
                <input class="form-control" type="password" name="password_confirmation">
                <?php if (!empty($errors['password_confirmation'])): ?><div class="small text-danger mt-1"><?= e($errors['password_confirmation']) ?></div><?php endif; ?>
              </div>
              <div id="rider-fields" class="<?= $accountType === 'rider' ? '' : 'd-none' ?>">
                <h2 class="h6 fw-bold text-uppercase text-soft mt-2 mb-3"><?= e(t('register.vehicle_heading')) ?></h2>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.vehicle_type_label')) ?></label>
                  <select class="form-select" name="vehicle_type" id="vehicle_type_select">
                    <option value="bike"><?= e(t('vehicle.bike')) ?></option>
                    <option value="car"><?= e(t('vehicle.car')) ?></option>
                    <option value="van"><?= e(t('vehicle.van')) ?></option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.vehicle_plate_label')) ?></label>
                  <input class="form-control" name="vehicle_plate" value="<?= e(old('vehicle_plate')) ?>">
                  <?php if (!empty($errors['vehicle_plate'])): ?><div class="small text-danger mt-1"><?= e($errors['vehicle_plate']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.vehicle_color_label')) ?></label>
                  <input class="form-control" name="vehicle_color" value="<?= e(old('vehicle_color')) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.vehicle_document_label')) ?></label>
                  <input class="form-control" type="file" name="vehicle_document" accept="image/jpeg,image/png,image/webp">
                  <div class="form-text text-soft"><?= e(t('register.vehicle_document_hint')) ?></div>
                  <?php if (!empty($errors['vehicle_document'])): ?><div class="small text-danger mt-1"><?= e($errors['vehicle_document']) ?></div><?php endif; ?>
                </div>
                <div class="mb-4" id="driving-license-field">
                  <label class="form-label"><?= e(t('register.driving_license_label')) ?></label>
                  <input class="form-control" type="file" name="driving_license" accept="image/jpeg,image/png,image/webp">
                  <div class="form-text text-soft"><?= e(t('register.driving_license_hint')) ?></div>
                  <?php if (!empty($errors['driving_license'])): ?><div class="small text-danger mt-1"><?= e($errors['driving_license']) ?></div><?php endif; ?>
                </div>

                <h2 class="h6 fw-bold text-uppercase text-soft mt-3 mb-3"><?= e(t('register.biodata_heading')) ?></h2>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.age_label')) ?></label>
                  <input class="form-control" type="number" min="18" max="100" name="age" value="<?= e(old('age')) ?>">
                  <?php if (!empty($errors['age'])): ?><div class="small text-danger mt-1"><?= e($errors['age']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.national_id_label')) ?></label>
                  <input class="form-control" name="national_id_number" value="<?= e(old('national_id_number')) ?>">
                  <?php if (!empty($errors['national_id_number'])): ?><div class="small text-danger mt-1"><?= e($errors['national_id_number']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.state_of_origin_label')) ?></label>
                  <input class="form-control" name="state_of_origin" value="<?= e(old('state_of_origin')) ?>">
                  <?php if (!empty($errors['state_of_origin'])): ?><div class="small text-danger mt-1"><?= e($errors['state_of_origin']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.lga_of_origin_label')) ?></label>
                  <input class="form-control" name="lga_of_origin" value="<?= e(old('lga_of_origin')) ?>">
                  <?php if (!empty($errors['lga_of_origin'])): ?><div class="small text-danger mt-1"><?= e($errors['lga_of_origin']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.hometown_label')) ?></label>
                  <input class="form-control" name="hometown" value="<?= e(old('hometown')) ?>">
                  <?php if (!empty($errors['hometown'])): ?><div class="small text-danger mt-1"><?= e($errors['hometown']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.address_label')) ?></label>
                  <textarea class="form-control" name="address" rows="2"><?= e(old('address')) ?></textarea>
                  <?php if (!empty($errors['address'])): ?><div class="small text-danger mt-1"><?= e($errors['address']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.proof_of_address_label')) ?></label>
                  <input class="form-control" type="file" name="proof_of_address" accept="image/jpeg,image/png,image/webp">
                  <div class="form-text text-soft"><?= e(t('register.proof_of_address_hint')) ?></div>
                  <?php if (!empty($errors['proof_of_address'])): ?><div class="small text-danger mt-1"><?= e($errors['proof_of_address']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.kyc_document_label')) ?></label>
                  <input class="form-control" type="file" name="kyc_document" accept="image/jpeg,image/png,image/webp">
                  <div class="form-text text-soft"><?= e(t('register.kyc_document_hint')) ?></div>
                  <?php if (!empty($errors['kyc_document'])): ?><div class="small text-danger mt-1"><?= e($errors['kyc_document']) ?></div><?php endif; ?>
                </div>
                <div class="mb-4">
                  <label class="form-label"><?= e(t('register.profile_photo_label')) ?></label>
                  <input class="form-control" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                  <div class="form-text text-soft"><?= e(t('register.profile_photo_hint')) ?></div>
                  <?php if (!empty($errors['profile_photo'])): ?><div class="small text-danger mt-1"><?= e($errors['profile_photo']) ?></div><?php endif; ?>
                </div>

                <h2 class="h6 fw-bold text-uppercase text-soft mt-3 mb-3"><?= e(t('register.guarantor_heading')) ?></h2>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.guarantor_name_label')) ?></label>
                  <input class="form-control" name="guarantor_name" value="<?= e(old('guarantor_name')) ?>">
                  <?php if (!empty($errors['guarantor_name'])): ?><div class="small text-danger mt-1"><?= e($errors['guarantor_name']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.guarantor_phone_label')) ?></label>
                  <input class="form-control" name="guarantor_phone" value="<?= e(old('guarantor_phone')) ?>">
                  <?php if (!empty($errors['guarantor_phone'])): ?><div class="small text-danger mt-1"><?= e($errors['guarantor_phone']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                  <label class="form-label"><?= e(t('register.guarantor_address_label')) ?></label>
                  <textarea class="form-control" name="guarantor_address" rows="2"><?= e(old('guarantor_address')) ?></textarea>
                  <?php if (!empty($errors['guarantor_address'])): ?><div class="small text-danger mt-1"><?= e($errors['guarantor_address']) ?></div><?php endif; ?>
                </div>
                <div class="mb-4">
                  <label class="form-label"><?= e(t('register.guarantor_relationship_label')) ?></label>
                  <input class="form-control" name="guarantor_relationship" value="<?= e(old('guarantor_relationship')) ?>">
                  <?php if (!empty($errors['guarantor_relationship'])): ?><div class="small text-danger mt-1"><?= e($errors['guarantor_relationship']) ?></div><?php endif; ?>
                </div>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-primary" type="submit"><?= e(t('register.submit')) ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url_path('login')) ?>"><?= e(t('register.have_account')) ?></a>
              </div>
            </form>
            <script>
              function updateDrivingLicenseVisibility() {
                var vehicleType = document.getElementById('vehicle_type_select').value;
                var field = document.getElementById('driving-license-field');
                if (field) field.classList.toggle('d-none', vehicleType === 'bike');
              }
              document.querySelectorAll('.account-type-tab').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  document.querySelectorAll('.account-type-tab').forEach(function (b) { b.classList.remove('active'); });
                  btn.classList.add('active');
                  var type = btn.getAttribute('data-type');
                  document.getElementById('account_type').value = type;
                  document.getElementById('rider-fields').classList.toggle('d-none', type !== 'rider');
                  document.getElementById('sender-features').classList.toggle('d-none', type !== 'sender');
                  document.getElementById('rider-features').classList.toggle('d-none', type !== 'rider');
                });
              });
              document.getElementById('vehicle_type_select').addEventListener('change', updateDrivingLicenseVisibility);
              updateDrivingLicenseVisibility();
            </script>
          </div>
          <div class="col-lg-6">
            <div class="cardx p-4 h-100">
              <div id="sender-features" class="<?= $accountType === 'rider' ? 'd-none' : '' ?>">
                <h2 class="h4"><?= e(t('register.features_heading_sender')) ?></h2>
                <ul class="text-soft mb-0">
                  <li class="mb-2"><?= e(t('register.sender_feature.1')) ?></li>
                  <li class="mb-2"><?= e(t('register.sender_feature.2')) ?></li>
                  <li class="mb-2"><?= e(t('register.sender_feature.3')) ?></li>
                  <li class="mb-2"><?= e(t('register.sender_feature.4')) ?></li>
                </ul>
              </div>
              <div id="rider-features" class="<?= $accountType === 'rider' ? '' : 'd-none' ?>">
                <h2 class="h4"><?= e(t('register.features_heading_rider')) ?></h2>
                <ul class="text-soft mb-0">
                  <li class="mb-2"><?= e(t('register.rider_feature.1')) ?></li>
                  <li class="mb-2"><?= e(t('register.rider_feature.2')) ?></li>
                  <li class="mb-2"><?= e(t('register.rider_feature.3')) ?></li>
                  <li class="mb-2"><?= e(t('register.rider_feature.4')) ?></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="text-center mt-3"><a class="link-light text-decoration-none" href="<?= e(url_path('')) ?>"><?= e(t('register.back_home')) ?></a></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
