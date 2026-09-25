<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function checkout_payment_provider(): string
{
    $provider = strtolower(trim((string) env_or_fallback(['TB_CHECKOUT_PAYMENT_PROVIDER'], 'offline')));
    $allowed = ['offline', 'barion', 'stripe'];
    return in_array($provider, $allowed, true) ? $provider : 'offline';
}

function resolve_checkout_payment(string $requestedMethod): array
{
    $provider = checkout_payment_provider();
    $method = strtolower(trim($requestedMethod));
    if ($method === '') {
        $method = $provider === 'offline' ? 'bank_transfer' : 'online_card';
    }

    if ($provider === 'offline' && !in_array($method, ['bank_transfer', 'cash_on_delivery'], true)) {
        $method = 'bank_transfer';
    }

    if ($provider !== 'offline' && !in_array($method, ['online_card', 'bank_transfer', 'cash_on_delivery'], true)) {
        $method = 'online_card';
    }

    $requiresRedirect = $provider !== 'offline' && $method === 'online_card';

    return [
        'provider' => $provider,
        'method' => $method,
        'requires_redirect' => $requiresRedirect,
        'status' => $requiresRedirect ? 'payment_pending' : 'accepted',
    ];
}
