<?php
declare(strict_types=1);

/*
 * Globális kereső jogosultság alapú találatszűréssel.
 */

function tb_search_sources(array $user): array
{
    $uid = (int) $user['id'];
    $role = (string) ($user['role'] ?? 'user');
    $backoffice = tb_is_backoffice($user);
    $isWorker = $role === 'field_worker';
    $sources = [];

    if ($backoffice) {
        $sources['customer'] = ['label' => 'Ügyfél', 'sql' => "SELECT id, name AS title, email AS subtitle, NULL AS status, created_at FROM users WHERE role = 'user'", 'cols' => ['name', 'email', 'phone'], 'scope' => '', 'args' => []];
        $sources['lead'] = ['label' => 'Lead', 'sql' => 'SELECT id, name AS title, email AS subtitle, status, created_at FROM leads WHERE 1=1', 'cols' => ['name', 'email', 'phone', 'company'], 'scope' => '', 'args' => []];
        $sources['quote_request'] = ['label' => 'Ajánlatkérés', 'sql' => 'SELECT id, name AS title, work_type AS subtitle, status, created_at FROM quotes WHERE 1=1', 'cols' => ['name', 'email', 'work_type', 'phone'], 'scope' => '', 'args' => []];
        $sources['quote'] = ['label' => 'Ajánlat', 'sql' => 'SELECT id, title, currency AS subtitle, status, created_at FROM crm_quotes WHERE 1=1', 'cols' => ['title'], 'scope' => '', 'args' => []];
        $sources['project'] = ['label' => 'Projekt', 'sql' => 'SELECT id, title, address AS subtitle, status, created_at FROM projects WHERE 1=1', 'cols' => ['title', 'address', 'project_type'], 'scope' => '', 'args' => []];
        $sources['work_order'] = ['label' => 'Munkalap', 'sql' => 'SELECT id, title, location AS subtitle, status, created_at FROM work_orders WHERE 1=1', 'cols' => ['title', 'location', 'work_type'], 'scope' => '', 'args' => []];
        $sources['ticket'] = ['label' => 'Szerviz ticket', 'sql' => 'SELECT id, subject AS title, location AS subtitle, status, created_at FROM service_tickets WHERE 1=1', 'cols' => ['subject', 'location', 'installation_reference'], 'scope' => '', 'args' => []];
        $sources['document'] = ['label' => 'Dokumentum', 'sql' => 'SELECT id, COALESCE(title, original_name) AS title, category AS subtitle, NULL AS status, created_at FROM project_files WHERE 1=1', 'cols' => ['title', 'original_name'], 'scope' => '', 'args' => []];
        if (in_array($role, ['admin', 'superadmin'], true)) {
            $sources['order'] = ['label' => 'Rendelés', 'sql' => 'SELECT id, COALESCE(billing_name, shipping_name) AS title, CONCAT(total, \' Ft\') AS subtitle, status, created_at FROM orders WHERE 1=1', 'cols' => ['billing_name', 'shipping_name', 'tracking_number', 'shipping_city'], 'scope' => '', 'args' => [], 'id_search' => true];
        }
    } elseif ($isWorker) {
        $sources['work_order'] = ['label' => 'Munkalap', 'sql' => 'SELECT id, title, location AS subtitle, status, created_at FROM work_orders WHERE assigned_to_user_id = ?', 'cols' => ['title', 'location', 'work_type'], 'scope' => '', 'args' => [$uid]];
        $sources['ticket'] = ['label' => 'Szerviz ticket', 'sql' => 'SELECT id, subject AS title, location AS subtitle, status, created_at FROM service_tickets WHERE assigned_to_user_id = ?', 'cols' => ['subject', 'location'], 'scope' => '', 'args' => [$uid]];
    } else {
        $sources['order'] = ['label' => 'Rendelés', 'sql' => 'SELECT id, CONCAT(\'Rendelés #\', id) AS title, CONCAT(total, \' Ft\') AS subtitle, status, created_at FROM orders WHERE user_id = ?', 'cols' => ['tracking_number'], 'scope' => '', 'args' => [$uid], 'id_search' => true];
        $sources['project'] = ['label' => 'Projekt', 'sql' => 'SELECT id, title, address AS subtitle, status, created_at FROM projects WHERE user_id = ?', 'cols' => ['title', 'address'], 'scope' => '', 'args' => [$uid]];
        $sources['ticket'] = ['label' => 'Szerviz ticket', 'sql' => 'SELECT id, subject AS title, location AS subtitle, status, created_at FROM service_tickets WHERE user_id = ?', 'cols' => ['subject', 'location'], 'scope' => '', 'args' => [$uid]];
    }
    $sources['product'] = ['label' => 'Termék', 'sql' => 'SELECT id, name AS title, NULL AS subtitle, NULL AS status, created_at FROM products WHERE 1=1', 'cols' => ['name', 'description'], 'scope' => '', 'args' => []];
    return $sources;
}

function tb_search_query_parts(array $source, string $q): array
{
    $like = tb_like($q);
    $conds = [];
    $args = $source['args'];
    foreach ($source['cols'] as $col) {
        $conds[] = $col . ' LIKE ?';
        $args[] = $like;
    }
    $digits = ltrim($q, '#');
    if (ctype_digit($digits) && strlen($digits) <= 10) {
        $conds[] = 'id = ?';
        $args[] = (int) $digits;
    }
    return [' AND (' . implode(' OR ', $conds) . ')', $args];
}

/**
 * Ha $type üres: típusonként az első $limit találat + darabszám. Ha $type meg van adva: lapozható lista.
 */
function tb_global_search(array $user, string $q, string $type = '', int $limit = 5, int $offset = 0): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return ['query' => $q, 'groups' => [], 'total' => 0];
    }
    $sources = tb_search_sources($user);
    if ($type !== '') {
        if (!isset($sources[$type])) {
            throw new InvalidArgumentException('Ismeretlen vagy nem engedélyezett találattípus.');
        }
        $sources = [$type => $sources[$type]];
    }
    $groups = [];
    $total = 0;
    foreach ($sources as $key => $source) {
        [$where, $args] = tb_search_query_parts($source, $q);
        $count = db()->prepare('SELECT COUNT(*) AS c FROM (' . $source['sql'] . $where . ') x');
        $count->execute($args);
        $n = (int) ($count->fetch()['c'] ?? 0);
        if ($n === 0) {
            continue;
        }
        $stmt = db()->prepare($source['sql'] . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($args);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = ['type' => $key, 'id' => (int) $row['id'], 'title' => (string) ($row['title'] ?? ''), 'subtitle' => $row['subtitle'], 'status' => $row['status'], 'created_at' => $row['created_at']];
        }
        $groups[] = ['type' => $key, 'label' => $source['label'], 'total' => $n, 'items' => $items, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + count($items) < $n];
        $total += $n;
    }
    return ['query' => $q, 'groups' => $groups, 'total' => $total];
}
