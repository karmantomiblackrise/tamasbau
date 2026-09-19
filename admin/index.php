<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (is_admin_user()) {
    redirect('admin/dashboard.php');
}

redirect('admin/login.php');
