<?php
/** @var string $pageTitle */
/** @var string $activeNav */
$flash = flash_get();
$nav = [
    'Webhely' => [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
        'blog' => ['label' => 'Blog', 'href' => 'admin/modules/blog.php'],
        'price-list' => ['label' => 'Árlista', 'href' => 'admin/modules/price-list.php'],
        'services' => ['label' => 'Szolgáltatások', 'href' => 'admin/modules/services.php'],
        'menus' => ['label' => 'Menük', 'href' => 'admin/modules/menus.php'],
        'site-settings' => ['label' => 'Oldalbeállítások', 'href' => 'admin/modules/site-settings.php'],
    ],
    'Webshop' => [
        'products' => ['label' => 'Termékek', 'href' => 'admin/modules/products.php'],
        'categories' => ['label' => 'Kategóriák', 'href' => 'admin/modules/categories.php'],
        'brands' => ['label' => 'Márkák', 'href' => 'admin/modules/brands.php'],
        'orders' => ['label' => 'Rendelések', 'href' => 'admin/modules/orders.php'],
        'stock' => ['label' => 'Alacsony készlet', 'href' => 'admin/modules/stock.php'],
        'shipping-payment' => ['label' => 'Szállítás/Fizetés', 'href' => 'admin/modules/shipping-payment.php'],
        'shop-settings' => ['label' => 'Webshop beállítások', 'href' => 'admin/modules/shop-settings.php'],
    ],
    'Ügyfélkapcsolat' => [
        'quote-requests' => ['label' => 'Ajánlatkérések', 'href' => 'admin/modules/quote-requests.php'],
        'messages' => ['label' => 'Kapcsolati üzenetek', 'href' => 'admin/modules/messages.php'],
    ],
    'Rendszer/Fiók' => [
        'users' => ['label' => 'Felhasználók', 'href' => 'admin/modules/users.php'],
        'change-password' => ['label' => 'Jelszócsere', 'href' => 'admin/change-password.php'],
        'system-status' => ['label' => 'Rendszerállapot', 'href' => 'admin/system-status.php'],
        'logout' => ['label' => 'Kilépés', 'href' => 'admin/logout.php'],
    ],
];
?><!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · Admin</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/admin.css')) ?>">
</head>
<body>
<div class="admin-shell">
    <aside class="sidebar" id="adminSidebar">
        <div class="sidebar__brand">Tamasbau Admin</div>
        <?php foreach ($nav as $group => $links): ?>
            <div class="sidebar__group">
                <div class="sidebar__title"><?= h($group) ?></div>
                <?php foreach ($links as $key => $item): ?>
                    <a class="sidebar__link <?= $activeNav === $key ? 'is-active' : '' ?>" href="<?= h(app_url($item['href'])) ?>"><?= h($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </aside>
    <div class="content-wrap">
        <header class="topbar">
            <button class="menu-toggle" type="button" onclick="document.getElementById('adminSidebar').classList.toggle('is-open');">☰</button>
            <div class="topbar__title"><?= h($pageTitle) ?></div>
            <div class="topbar__user"><?= h($_SESSION['auth']['username'] ?? 'ismeretlen') ?></div>
        </header>
        <main class="content">
            <?php if ($flash): ?>
                <div class="flash flash--<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endif; ?>
