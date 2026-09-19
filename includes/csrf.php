<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool
{
    if (!isset($_SESSION['csrf_token']) || $token === null) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
