<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_module_helpers.php';
require_admin();

$pageTitle = 'Szállítás/Fizetés';
$activeNav = 'shipping-payment';
$shippingRows = [];
$paymentRows = [];
$shippingCount = 0;
$paymentCount = 0;

try {
    $pdo = db();
    $shippingCount = count_if_table_exists($pdo, 'shipping_methods') ?? 0;
    $paymentCount = count_if_table_exists($pdo, 'payment_methods') ?? 0;
    $shippingRows = fetch_recent_rows($pdo, 'shipping_methods', ['id', 'name', 'price', 'is_active']);
    $paymentRows = fetch_recent_rows($pdo, 'payment_methods', ['id', 'name', 'is_active', 'updated_at']);
} catch (Throwable $exception) {
    flash_set('error', db_connection_error_message($exception));
}

require_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="card" style="margin-bottom:12px;">
    <h3><?= h('Szállítás/Fizetés') ?></h3>
    <div class="card__value"><?= h((string) ($shippingCount + $paymentCount)) ?></div>
    <p class="helper">Szállítási módok: <?= h((string) $shippingCount) ?> · Fizetési módok: <?= h((string) $paymentCount) ?></p>
</div>
<?php render_module_table(['ID', 'Név', 'Díj', 'Aktív'], $shippingRows); ?>
<div style="height:12px;"></div>
<?php render_module_table(['ID', 'Név', 'Aktív', 'Frissítve'], $paymentRows); ?>
<?php require_once dirname(__DIR__) . '/partials/footer.php';
