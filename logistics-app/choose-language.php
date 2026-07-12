<?php
require_once __DIR__ . '/config/functions.php';

$redirect = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'login');
if (!preg_match('#^[a-zA-Z0-9_\-/]*$#', $redirect) || $redirect === '') {
    $redirect = 'login';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_locale((string) ($_POST['locale'] ?? 'en'));
    redirect_to($redirect);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Aike</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44;display:flex;align-items:center;justify-content:center}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22);max-width:420px;width:100%}
        .lang-btn{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-radius:1rem;border:1px solid rgba(15,42,68,.12);background:#ffffff;color:#0f2c44;text-decoration:none;margin-bottom:.75rem;transition:.15s ease}
        .lang-btn:hover{border-color:#38bdf8;box-shadow:0 6px 16px rgba(56,189,248,.18);color:#0f2c44}
    </style>
</head>
<body>
<div class="cardx p-4 p-md-5 mx-3">
    <h1 class="h4 fw-bold mb-1 text-center">Aike</h1>
    <p class="text-center text-soft mb-4">Choose your language / Zaɓi harshenku</p>
    <form method="post">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <button type="submit" name="locale" value="en" class="lang-btn w-100 border-0">
            <span>English</span>
            <i class="fa-solid fa-chevron-right"></i>
        </button>
        <button type="submit" name="locale" value="ha" class="lang-btn w-100 border-0">
            <span>Hausa</span>
            <i class="fa-solid fa-chevron-right"></i>
        </button>
    </form>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</body>
</html>
