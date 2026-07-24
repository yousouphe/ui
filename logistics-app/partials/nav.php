<?php
// Single source of truth for the top navigation bar across every authenticated web page.
//
// Before this, all ~22 pages hardcoded their own <nav>, so the link sets drifted (Transactions
// missing on some pages, admin menu incomplete on others, profile.php nearly empty, locale
// switcher missing on some rider pages). render_app_nav() renders one consistent, role-gated nav
// so a change is made in exactly one place and can never drift again.
//
// Roles: sender (default), rider, admin/super_admin. Pricing is super_admin-only. Public pages
// (login/register/track) keep their own minimal nav and do not call this.

if (!function_exists('render_app_nav')) {
    /**
     * @param array|null $user           The authenticated user (defaults to current_user()).
     * @param string     $active         Key of the current page's nav item (bolds it).
     * @param string     $localeRedirect Path the EN/HA switch returns to (defaults to the role home).
     */
    function render_app_nav(?array $user = null, string $active = '', string $localeRedirect = ''): string {
        $user = $user ?? (function_exists('current_user') ? current_user() : null) ?? [];
        $role = (string) ($user['role'] ?? 'sender');
        $isSuper = $role === 'super_admin';
        $isAdmin = in_array($role, ['admin', 'super_admin'], true);

        // Each item: [key, url_path, i18n label key, Font Awesome icon].
        if ($isAdmin) {
            $home = 'admin/';
            $brandSuffix = ' ' . t('admin.brand_suffix');
            $items = [
                ['withdrawals',  'admin/',                 'admin.nav_withdrawals', 'fa-money-bill-transfer'],
                ['transactions', 'admin/transactions.php', 'admin.nav_transactions', 'fa-receipt'],
                ['bookings',     'admin/bookings.php',     'admin.nav_bookings',     'fa-box'],
                ['riders',       'admin/riders.php',       'admin.nav_riders',       'fa-motorcycle'],
                ['complaints',   'admin/complaints.php',   'admin.nav_complaints',   'fa-triangle-exclamation'],
                ['users',        'admin/users.php',        'admin.nav_users',        'fa-users'],
                ['logs',         'admin/logs.php',         'admin.nav_logs',         'fa-clock-rotate-left'],
            ];
            if ($isSuper) {
                $items[] = ['pricing', 'admin/pricing.php', 'admin.nav_pricing', 'fa-sliders'];
            }
            $items[] = ['profile', 'admin/profile.php', 'profile.nav_label', 'fa-user'];
        } elseif ($role === 'rider') {
            $home = 'rider/';
            $brandSuffix = '';
            $items = [
                ['home',         'rider/',           'nav.dashboard',      'fa-house'],
                ['deliveries',   'rider/dashboard',  'nav.my_deliveries',  'fa-list-ul'],
                ['wallet',       'rider/wallet',     'wallet.nav_label',   'fa-wallet'],
                ['transactions', 'transactions.php', 'nav.transactions',   'fa-receipt'],
                ['kyc',          'rider/kyc.php',    'kyc.nav_label',      'fa-id-card'],
                ['training',     'rider/training.php', 'training.nav_label', 'fa-graduation-cap'],
                ['profile',      'profile',          'profile.nav_label',  'fa-user'],
            ];
        } else { // sender (default)
            $home = 'bookings/';
            $brandSuffix = '';
            $items = [
                ['home',         'bookings/',                'dashboard.sender_hub', 'fa-house'],
                ['orders',       'dashboard',                'nav.my_orders',        'fa-list-ul'],
                ['new',          'bookings/?new=1',          'nav.new_order',        'fa-plus'],
                ['transactions', 'transactions.php',         'nav.transactions',     'fa-receipt'],
                ['complaints',   'bookings/complaints.php',  'complaint.nav_label',  'fa-triangle-exclamation'],
                ['profile',      'profile',                  'profile.nav_label',    'fa-user'],
            ];
        }

        $lr = $localeRedirect !== '' ? $localeRedirect : $home;

        ob_start();
        ?>
        <nav class="navbar navbar-expand-lg navbar-light navx no-print">
            <div class="container">
                <a class="navbar-brand fw-bold" href="<?= e(url_path($home)) ?>"><?= e(t('common.brand') . $brandSuffix) ?></a>
                <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
                    <?php foreach ($items as [$key, $url, $label, $icon]): ?>
                        <a class="nav-link<?= $active === $key ? ' fw-bold' : '' ?>" href="<?= e(url_path($url)) ?>"><i class="fa-solid <?= e($icon) ?> me-1"></i><?= e(t($label)) ?></a>
                    <?php endforeach; ?>
                    <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
                    <div class="small">
                        <a href="<?= e(url_path('set_locale?locale=en&redirect=' . $lr)) ?>" class="<?= current_locale() === 'en' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">EN</a>
                        &middot;
                        <a href="<?= e(url_path('set_locale?locale=ha&redirect=' . $lr)) ?>" class="<?= current_locale() === 'ha' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">HA</a>
                    </div>
                </div>
            </div>
        </nav>
        <?php
        return (string) ob_get_clean();
    }
}
