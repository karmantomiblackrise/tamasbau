<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function slugify(string $name): string
{
    $name = mb_strtolower(trim($name));
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
    ];
    $name = strtr($name, $map);
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $name) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'kategoria';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = get_json_input();

if ($method === 'GET') {
    $stmt = db()->query('SELECT id, name, slug, parent_id FROM categories ORDER BY parent_id IS NULL DESC, parent_id ASC, name ASC');
    $rows = array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        return $row;
    }, $stmt->fetchAll());

    send_json(['ok' => true, 'categories' => $rows]);
}

require_admin();

if ($method === 'POST') {
    $action = clean_string($payload['action'] ?? '');

    if ($action === 'create') {
        $name = clean_string($payload['name'] ?? '', 120);
        $parentId = isset($payload['parent_id']) && $payload['parent_id'] !== '' ? (int) $payload['parent_id'] : null;
        if ($name === '') {
            send_json(['ok' => false, 'error' => 'A kategória neve kötelező.'], 422);
        }
        if ($parentId !== null) {
            $parentStmt = db()->prepare('SELECT id, parent_id FROM categories WHERE id = ? LIMIT 1');
            $parentStmt->execute([$parentId]);
            $parent = $parentStmt->fetch();
            if (!$parent || $parent['parent_id'] !== null) {
                send_json(['ok' => false, 'error' => 'Csak létező fő kategória lehet szülő.'], 422);
            }
        }

        $slug = slugify((string) ($payload['slug'] ?? $name));
        $exists = db()->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $exists->execute([$slug]);
        if ($exists->fetch()) {
            send_json(['ok' => false, 'error' => 'Ez a kategória slug már foglalt.'], 409);
        }

        $stmt = db()->prepare('INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $parentId]);
        send_json(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);
    }

    if ($action === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        $name = clean_string($payload['name'] ?? '', 120);
        $parentId = isset($payload['parent_id']) && $payload['parent_id'] !== '' ? (int) $payload['parent_id'] : null;
        if ($id <= 0 || $name === '') {
            send_json(['ok' => false, 'error' => 'Hiányzó kategória adatok.'], 422);
        }
        $targetExists = db()->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
        $targetExists->execute([$id]);
        if (!$targetExists->fetch()) {
            send_json(['ok' => false, 'error' => 'Kategória nem található.'], 404);
        }
        if ($parentId === $id) {
            send_json(['ok' => false, 'error' => 'A kategória nem lehet saját szülője.'], 422);
        }
        if ($parentId !== null) {
            $parentStmt = db()->prepare('SELECT id, parent_id FROM categories WHERE id = ? LIMIT 1');
            $parentStmt->execute([$parentId]);
            $parent = $parentStmt->fetch();
            if (!$parent || $parent['parent_id'] !== null) {
                send_json(['ok' => false, 'error' => 'Csak létező fő kategória lehet szülő.'], 422);
            }

            $treeRows = db()->query('SELECT id, parent_id FROM categories')->fetchAll();
            $childrenMap = [];
            foreach ($treeRows as $row) {
                $pid = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
                if ($pid === null) {
                    continue;
                }
                if (!isset($childrenMap[$pid])) {
                    $childrenMap[$pid] = [];
                }
                $childrenMap[$pid][] = (int) $row['id'];
            }

            $descendants = [];
            $stack = [$id];
            while ($stack) {
                $current = array_pop($stack);
                foreach ($childrenMap[$current] ?? [] as $childId) {
                    if (isset($descendants[$childId])) {
                        continue;
                    }
                    $descendants[$childId] = true;
                    $stack[] = $childId;
                }
            }

            if (isset($descendants[$parentId])) {
                send_json(['ok' => false, 'error' => 'Körkörös kategória-hierarchia nem engedélyezett.'], 422);
            }
        }

        $slug = slugify((string) ($payload['slug'] ?? $name));
        $exists = db()->prepare('SELECT id FROM categories WHERE slug = ? AND id <> ? LIMIT 1');
        $exists->execute([$slug, $id]);
        if ($exists->fetch()) {
            send_json(['ok' => false, 'error' => 'Ez a kategória slug már foglalt.'], 409);
        }

        $stmt = db()->prepare('UPDATE categories SET name = ?, slug = ?, parent_id = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $parentId, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                send_json(['ok' => false, 'error' => 'Kategória nem található.'], 404);
            }
        }
        send_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            send_json(['ok' => false, 'error' => 'Érvénytelen kategória.'], 422);
        }
        $targetStmt = db()->prepare('SELECT id, parent_id FROM categories WHERE id = ? LIMIT 1');
        $targetStmt->execute([$id]);
        $target = $targetStmt->fetch();
        if (!$target) {
            send_json(['ok' => false, 'error' => 'Kategória nem található.'], 404);
        }

        $childCheck = db()->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
        $childCheck->execute([$id]);
        $childCount = (int) $childCheck->fetchColumn();
        if ($childCount > 0) {
            send_json(['ok' => false, 'error' => 'A kategóriának vannak alkategóriái.'], 422);
        }

        if ($target['parent_id'] === null) {
            $prodCheck = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ? OR category_id IN (SELECT id FROM categories WHERE parent_id = ?)');
            $prodCheck->execute([$id, $id]);
        } else {
            $prodCheck = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
            $prodCheck->execute([$id]);
        }
        if ((int) $prodCheck->fetchColumn() > 0) {
            send_json(['ok' => false, 'error' => 'A kategóriához termék tartozik.'], 422);
        }

        $stmt = db()->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            send_json(['ok' => false, 'error' => 'Kategória nem található.'], 404);
        }
        send_json(['ok' => true]);
    }
}

send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
