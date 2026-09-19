<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

logout_user();
redirect('admin/login.php?logged_out=1');
