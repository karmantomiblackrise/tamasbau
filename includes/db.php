<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function db_connection_error_message(Throwable $exception): string
{
    $message = $exception->getMessage();
    if (stripos($message, 'Access denied') !== false) {
        return 'Adatbázis-kapcsolat nem elérhető: hibás jogosultság vagy jelszó.';
    }

    return 'Adatbázis-kapcsolat nem elérhető.';
}
