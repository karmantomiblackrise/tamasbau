<?php
declare(strict_types=1);

/*
 * Mellékhatás-mentes platform könyvtár. Csak függvényeket és osztályokat definiál;
 * bármely API végpontból (operations.php, orders.php, quotes.php, platform.php, cron.php)
 * biztonságosan betölthető.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/workflow.php';
require_once __DIR__ . '/lib/service.php';
require_once __DIR__ . '/lib/invoicing.php';
require_once __DIR__ . '/lib/profit.php';
require_once __DIR__ . '/lib/reviews.php';
require_once __DIR__ . '/lib/signatures.php';
require_once __DIR__ . '/lib/mobile.php';
require_once __DIR__ . '/lib/search.php';
require_once __DIR__ . '/lib/health.php';

/**
 * Kivétel → HTTP státusz megfeleltetés az API rétegnek.
 */
function tb_exception_http_status(Throwable $e): int
{
    if ($e instanceof InvalidArgumentException) {
        return 422;
    }
    if ($e instanceof DomainException) {
        return 409;
    }
    if ($e instanceof OutOfBoundsException) {
        return 404;
    }
    if ($e instanceof UnexpectedValueException) {
        return 403;
    }
    return 500;
}

/**
 * Hook segéd: a fő üzleti folyamatot soha nem törheti el egy workflow hiba.
 */
function tb_safe_event(string $event, array $data): void
{
    try {
        tb_workflow_event($event, $data);
    } catch (Throwable $e) {
        app_log_error('workflow_hook_failed', $e, ['event' => $event]);
    }
}
