<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? null)) {
    flash_set('error', 'Érvénytelen kijelentkezési kérés.');
    redirect('admin/dashboard.php');
}

logout_user();
redirect('admin/login.php?logged_out=1');
