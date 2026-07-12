<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

$stmt = $pdo->prepare('SELECT * FROM rider_profiles WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    flash('error', t('kyc.no_profile_found'));
    redirect_to('rider/');
}

$isApproved = $profile['kyc_status'] === 'approved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if ($isApproved) {
        flash('error', t('kyc.locked_no_edits'));
        redirect_to('rider/kyc.php');
    }

    $vehicleType = $_POST['vehicle_type'] ?? 'bike';
    $vehicleType = in_array($vehicleType, ['bike', 'car', 'van'], true) ? $vehicleType : 'bike';
    $vehiclePlate = trim((string) ($_POST['vehicle_plate'] ?? ''));
    $vehicleColor = trim((string) ($_POST['vehicle_color'] ?? ''));
    $age = trim((string) ($_POST['age'] ?? ''));
    $stateOfOrigin = trim((string) ($_POST['state_of_origin'] ?? ''));
    $lgaOfOrigin = trim((string) ($_POST['lga_of_origin'] ?? ''));
    $hometown = trim((string) ($_POST['hometown'] ?? ''));
    $nationalIdNumber = trim((string) ($_POST['national_id_number'] ?? ''));
    $riderAddress = trim((string) ($_POST['address'] ?? ''));
    $guarantorName = trim((string) ($_POST['guarantor_name'] ?? ''));
    $guarantorPhone = trim((string) ($_POST['guarantor_phone'] ?? ''));
    $guarantorAddress = trim((string) ($_POST['guarantor_address'] ?? ''));
    $guarantorRelationship = trim((string) ($_POST['guarantor_relationship'] ?? ''));

    $errors = [];
    if ($vehiclePlate === '') $errors['vehicle_plate'] = t('register.error.vehicle_plate_required');
    if ($age === '') $errors['age'] = t('register.error.age_required');
    elseif (!ctype_digit($age) || (int) $age < 18 || (int) $age > 100) $errors['age'] = t('register.error.age_invalid');
    if ($nationalIdNumber === '') $errors['national_id_number'] = t('register.error.national_id_required');
    if ($stateOfOrigin === '') $errors['state_of_origin'] = t('register.error.state_of_origin_required');
    if ($lgaOfOrigin === '') $errors['lga_of_origin'] = t('register.error.lga_of_origin_required');
    if ($hometown === '') $errors['hometown'] = t('register.error.hometown_required');
    if ($riderAddress === '') $errors['address'] = t('register.error.address_required');
    if ($guarantorName === '') $errors['guarantor_name'] = t('register.error.guarantor_name_required');
    if ($guarantorPhone === '') $errors['guarantor_phone'] = t('register.error.guarantor_phone_required');
    if ($guarantorAddress === '') $errors['guarantor_address'] = t('register.error.guarantor_address_required');
    if ($guarantorRelationship === '') $errors['guarantor_relationship'] = t('register.error.guarantor_relationship_required');

    $newPaths = [];
    if (!$errors) {
        try {
            if (!empty($_FILES['kyc_document']['name'])) {
                $newPaths['kyc_id_document_path'] = save_kyc_document($_FILES['kyc_document']);
            }
            if (!empty($_FILES['profile_photo']['name'])) {
                $newPaths['avatar_path'] = save_uploaded_image($_FILES['profile_photo'], 'avatars', 'avatar', t('profile.avatar_label'));
            }
            if (!empty($_FILES['proof_of_address']['name'])) {
                $newPaths['kyc_proof_of_address_path'] = save_uploaded_image($_FILES['proof_of_address'], 'kyc', 'proof_address', t('register.proof_of_address_label'));
            }
            if (!empty($_FILES['vehicle_document']['name'])) {
                $newPaths['kyc_vehicle_document_path'] = save_uploaded_image($_FILES['vehicle_document'], 'kyc', 'vehicle_doc', t('register.vehicle_document_label'));
            }
            if (!empty($_FILES['driving_license']['name'])) {
                $newPaths['kyc_driving_license_path'] = save_uploaded_image($_FILES['driving_license'], 'kyc', 'license', t('register.driving_license_label'));
            }
        } catch (Throwable $e) {
            $errors['form'] = $e->getMessage();
        }
    }

    if (!$errors) {
        $wasRejected = $profile['kyc_status'] === 'rejected';

        $stmt = $pdo->prepare('
            UPDATE rider_profiles SET
                vehicle_type = ?, kyc_vehicle_plate = ?, kyc_vehicle_color = ?,
                kyc_age = ?, kyc_state_of_origin = ?, kyc_lga_of_origin = ?, kyc_hometown = ?,
                kyc_national_id_number = ?, kyc_address = ?,
                kyc_guarantor_name = ?, kyc_guarantor_phone = ?, kyc_guarantor_address = ?, kyc_guarantor_relationship = ?,
                kyc_id_document_path = COALESCE(?, kyc_id_document_path),
                kyc_proof_of_address_path = COALESCE(?, kyc_proof_of_address_path),
                kyc_vehicle_document_path = COALESCE(?, kyc_vehicle_document_path),
                kyc_driving_license_path = COALESCE(?, kyc_driving_license_path),
                kyc_status = "pending", kyc_note = NULL, kyc_reviewed_by = NULL, kyc_reviewed_at = NULL
            WHERE user_id = ?
        ');
        $stmt->execute([
            $vehicleType, $vehiclePlate, $vehicleColor !== '' ? $vehicleColor : null,
            (int) $age, $stateOfOrigin, $lgaOfOrigin, $hometown,
            $nationalIdNumber, $riderAddress,
            $guarantorName, $guarantorPhone, $guarantorAddress, $guarantorRelationship,
            $newPaths['kyc_id_document_path'] ?? null,
            $newPaths['kyc_proof_of_address_path'] ?? null,
            $newPaths['kyc_vehicle_document_path'] ?? null,
            $newPaths['kyc_driving_license_path'] ?? null,
            $user['id'],
        ]);

        if (isset($newPaths['avatar_path'])) {
            $stmt = $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');
            $stmt->execute([$newPaths['avatar_path'], $user['id']]);
        }

        flash('success', t('kyc.resubmitted_success'));
        notify_admins($pdo, ($wasRejected ? 'Rider resubmitted KYC after rejection' : 'Rider updated pending KYC') . ' - ' . $user['full_name'], '<p><strong>' . e((string) $user['full_name']) . '</strong> (' . e((string) $user['email']) . ') updated their KYC details.</p><p>Review it from the admin portal.</p>');
        log_event($pdo, 'kyc_resubmitted', 'Rider ' . $user['full_name'] . ' resubmitted KYC', (int) $user['id'], (string) $user['role'], 'user', (int) $user['id']);
        redirect_to('rider/kyc.php');
    }
}

$stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$avatarPath = (string) ($stmt->fetchColumn() ?: '');
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('kyc.heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control,.form-select{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .form-control:focus,.form-select:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
        .kyc-doc-thumb{width:90px;height:90px;object-fit:cover;border-radius:.5rem;border:1px solid rgba(15,42,68,.12)}
        .readonly-row{padding:.5rem 0;border-bottom:1px solid rgba(15,42,68,.08)}
        .readonly-row:last-child{border-bottom:none}
        .readonly-label{font-size:.78rem;color:#5c7a91}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('rider/')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('rider/')) ?>"><i class="fa-solid fa-house me-1"></i><?= e(t('nav.dashboard')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/dashboard')) ?>"><i class="fa-solid fa-list-ul me-1"></i><?= e(t('nav.my_deliveries')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/wallet')) ?>"><i class="fa-solid fa-wallet me-1"></i><?= e(t('wallet.nav_label')) ?></a>
            <a class="nav-link active" href="<?= e(url_path('rider/kyc.php')) ?>"><i class="fa-solid fa-id-card me-1"></i><?= e(t('kyc.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/training.php')) ?>"><i class="fa-solid fa-graduation-cap me-1"></i><?= e(t('training.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('kyc.heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="cardx p-4 mb-4">
        <span class="badge <?= $profile['kyc_status'] === 'approved' ? 'bg-success' : ($profile['kyc_status'] === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= e(ucfirst((string) $profile['kyc_status'])) ?></span>
        <?php if ($isApproved): ?>
            <p class="text-soft mt-2 mb-0"><?= e(t('kyc.approved_hint')) ?></p>
        <?php elseif ($profile['kyc_status'] === 'rejected'): ?>
            <p class="text-danger mt-2 mb-0"><?= e(t('kyc.rejected_hint')) ?><?php if (!empty($profile['kyc_note'])): ?> <?= e((string) $profile['kyc_note']) ?><?php endif; ?></p>
        <?php else: ?>
            <p class="text-soft mt-2 mb-0"><?= e(t('kyc.pending_hint')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($isApproved): ?>
        <div class="cardx p-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <?php if ($avatarPath !== ''): ?><img src="<?= e(url_path($avatarPath)) ?>" class="kyc-doc-thumb" alt=""><?php endif; ?>
                <?php if (!empty($profile['kyc_id_document_path'])): ?><img src="<?= e(url_path($profile['kyc_id_document_path'])) ?>" class="kyc-doc-thumb" alt=""><?php endif; ?>
                <?php if (!empty($profile['kyc_proof_of_address_path'])): ?><img src="<?= e(url_path($profile['kyc_proof_of_address_path'])) ?>" class="kyc-doc-thumb" alt=""><?php endif; ?>
                <?php if (!empty($profile['kyc_vehicle_document_path'])): ?><img src="<?= e(url_path($profile['kyc_vehicle_document_path'])) ?>" class="kyc-doc-thumb" alt=""><?php endif; ?>
                <?php if (!empty($profile['kyc_driving_license_path'])): ?><img src="<?= e(url_path($profile['kyc_driving_license_path'])) ?>" class="kyc-doc-thumb" alt=""><?php endif; ?>
            </div>
            <?php
            $readonlyFields = [
                'register.vehicle_type_label' => t('vehicle.' . $profile['vehicle_type']),
                'register.vehicle_plate_label' => $profile['kyc_vehicle_plate'],
                'register.vehicle_color_label' => $profile['kyc_vehicle_color'],
                'register.age_label' => $profile['kyc_age'],
                'register.national_id_label' => $profile['kyc_national_id_number'],
                'register.state_of_origin_label' => $profile['kyc_state_of_origin'],
                'register.lga_of_origin_label' => $profile['kyc_lga_of_origin'],
                'register.hometown_label' => $profile['kyc_hometown'],
                'register.address_label' => $profile['kyc_address'],
                'register.guarantor_name_label' => $profile['kyc_guarantor_name'],
                'register.guarantor_phone_label' => $profile['kyc_guarantor_phone'],
                'register.guarantor_address_label' => $profile['kyc_guarantor_address'],
                'register.guarantor_relationship_label' => $profile['kyc_guarantor_relationship'],
            ];
            foreach ($readonlyFields as $labelKey => $value): ?>
                <div class="readonly-row">
                    <div class="readonly-label"><?= e(t($labelKey)) ?></div>
                    <div><?= e((string) ($value ?? '-')) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="cardx p-4">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <h2 class="h6 fw-bold text-uppercase text-soft mb-3"><?= e(t('register.vehicle_heading')) ?></h2>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.vehicle_type_label')) ?></label>
                    <select class="form-select" name="vehicle_type" id="kyc_vehicle_type_select">
                        <option value="bike" <?= $profile['vehicle_type'] === 'bike' ? 'selected' : '' ?>><?= e(t('vehicle.bike')) ?></option>
                        <option value="car" <?= $profile['vehicle_type'] === 'car' ? 'selected' : '' ?>><?= e(t('vehicle.car')) ?></option>
                        <option value="van" <?= $profile['vehicle_type'] === 'van' ? 'selected' : '' ?>><?= e(t('vehicle.van')) ?></option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.vehicle_plate_label')) ?></label>
                    <input class="form-control" name="vehicle_plate" value="<?= e((string) ($profile['kyc_vehicle_plate'] ?? '')) ?>">
                    <?php if (!empty($errors['vehicle_plate'])): ?><div class="small text-danger mt-1"><?= e($errors['vehicle_plate']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.vehicle_color_label')) ?></label>
                    <input class="form-control" name="vehicle_color" value="<?= e((string) ($profile['kyc_vehicle_color'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.vehicle_document_label')) ?></label>
                    <?php if (!empty($profile['kyc_vehicle_document_path'])): ?><div class="mb-2"><img src="<?= e(url_path($profile['kyc_vehicle_document_path'])) ?>" class="kyc-doc-thumb" alt=""></div><?php endif; ?>
                    <input class="form-control" type="file" name="vehicle_document" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text text-soft"><?= e(t('kyc.replace_only_if_changed')) ?></div>
                </div>
                <div class="mb-4" id="kyc-driving-license-field">
                    <label class="form-label"><?= e(t('register.driving_license_label')) ?></label>
                    <?php if (!empty($profile['kyc_driving_license_path'])): ?><div class="mb-2"><img src="<?= e(url_path($profile['kyc_driving_license_path'])) ?>" class="kyc-doc-thumb" alt=""></div><?php endif; ?>
                    <input class="form-control" type="file" name="driving_license" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text text-soft"><?= e(t('kyc.replace_only_if_changed')) ?></div>
                </div>

                <h2 class="h6 fw-bold text-uppercase text-soft mb-3"><?= e(t('register.biodata_heading')) ?></h2>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.age_label')) ?></label>
                    <input class="form-control" type="number" min="18" max="100" name="age" value="<?= e((string) ($profile['kyc_age'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.national_id_label')) ?></label>
                    <input class="form-control" name="national_id_number" value="<?= e((string) ($profile['kyc_national_id_number'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.state_of_origin_label')) ?></label>
                    <input class="form-control" name="state_of_origin" value="<?= e((string) ($profile['kyc_state_of_origin'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.lga_of_origin_label')) ?></label>
                    <input class="form-control" name="lga_of_origin" value="<?= e((string) ($profile['kyc_lga_of_origin'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.hometown_label')) ?></label>
                    <input class="form-control" name="hometown" value="<?= e((string) ($profile['kyc_hometown'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.address_label')) ?></label>
                    <textarea class="form-control" name="address" rows="2"><?= e((string) ($profile['kyc_address'] ?? '')) ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.proof_of_address_label')) ?></label>
                    <?php if (!empty($profile['kyc_proof_of_address_path'])): ?><div class="mb-2"><img src="<?= e(url_path($profile['kyc_proof_of_address_path'])) ?>" class="kyc-doc-thumb" alt=""></div><?php endif; ?>
                    <input class="form-control" type="file" name="proof_of_address" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text text-soft"><?= e(t('kyc.replace_only_if_changed')) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.kyc_document_label')) ?></label>
                    <?php if (!empty($profile['kyc_id_document_path'])): ?><div class="mb-2"><img src="<?= e(url_path($profile['kyc_id_document_path'])) ?>" class="kyc-doc-thumb" alt=""></div><?php endif; ?>
                    <input class="form-control" type="file" name="kyc_document" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text text-soft"><?= e(t('kyc.replace_only_if_changed')) ?></div>
                </div>
                <div class="mb-4">
                    <label class="form-label"><?= e(t('register.profile_photo_label')) ?></label>
                    <?php if ($avatarPath !== ''): ?><div class="mb-2"><img src="<?= e(url_path($avatarPath)) ?>" class="kyc-doc-thumb" alt=""></div><?php endif; ?>
                    <input class="form-control" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text text-soft"><?= e(t('kyc.replace_only_if_changed')) ?></div>
                </div>

                <h2 class="h6 fw-bold text-uppercase text-soft mb-3"><?= e(t('register.guarantor_heading')) ?></h2>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.guarantor_name_label')) ?></label>
                    <input class="form-control" name="guarantor_name" value="<?= e((string) ($profile['kyc_guarantor_name'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.guarantor_phone_label')) ?></label>
                    <input class="form-control" name="guarantor_phone" value="<?= e((string) ($profile['kyc_guarantor_phone'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= e(t('register.guarantor_address_label')) ?></label>
                    <textarea class="form-control" name="guarantor_address" rows="2"><?= e((string) ($profile['kyc_guarantor_address'] ?? '')) ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label"><?= e(t('register.guarantor_relationship_label')) ?></label>
                    <input class="form-control" name="guarantor_relationship" value="<?= e((string) ($profile['kyc_guarantor_relationship'] ?? '')) ?>">
                </div>

                <button class="btn btn-primary fw-bold" type="submit"><?= e(t('kyc.resubmit_button')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!$isApproved): ?>
<script>
(function () {
    var select = document.getElementById('kyc_vehicle_type_select');
    var field = document.getElementById('kyc-driving-license-field');
    function update() {
        if (field) field.classList.toggle('d-none', select.value === 'bike');
    }
    if (select) {
        select.addEventListener('change', update);
        update();
    }
})();
</script>
<?php endif; ?>
</body>
</html>
