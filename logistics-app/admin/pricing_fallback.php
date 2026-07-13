<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';

$flagPath = __DIR__ . '/../assets/pricing_fallback.json';
$exists = file_exists($flagPath);
$data = null;
if ($exists) {
    try {
        $data = json_decode(file_get_contents($flagPath), true);
    } catch (Throwable $e) {
        $data = null;
    }
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pricing Fallback | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-5">
    <h1 class="h4 mb-4">Pricing Fallback Status</h1>

    <?php if (!$exists): ?>
        <div class="alert alert-success">No haversine fallback flag present. Mapbox pricing appears healthy.</div>
    <?php else: ?>
        <div class="alert alert-warning">The haversine pricing fallback is active. See details below.</div>
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h6">Last Fallback Event</h2>
                <?php if (is_array($data)): ?>
                    <dl class="row">
                        <dt class="col-sm-3">Timestamp</dt>
                        <dd class="col-sm-9"><?= e(($data['timestamp'] ?? '')) ?></dd>
                        <dt class="col-sm-3">Reason</dt>
                        <dd class="col-sm-9"><?= e(($data['reason'] ?? 'Unknown')) ?></dd>
                        <dt class="col-sm-3">Speed (km/h)</dt>
                        <dd class="col-sm-9"><?= e(($data['haversine_speed_kmh'] ?? '')) ?></dd>
                        <dt class="col-sm-3">Sample Booking</dt>
                        <dd class="col-sm-9"><?= e(json_encode($data['sample'] ?? [])) ?></dd>
                    </dl>
                <?php else: ?>
                    <div class="small text-muted">Unable to parse flag file.</div>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" onsubmit="return confirm('Clear fallback flag?');">
            <input type="hidden" name="action" value="clear">
            <button class="btn btn-danger" type="submit">Clear Flag</button>
            <a class="btn btn-secondary ms-2" href="<?= e(url_path('admin/')) ?>">Back</a>
        </form>
    <?php endif; ?>
</div>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    require_csrf();
    if ($exists) @unlink($flagPath);
    flash('success', 'Pricing fallback flag cleared.');
    redirect_to('admin/pricing_fallback.php');
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
