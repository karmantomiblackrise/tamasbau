<?php

declare(strict_types=1);

function fetch_recent_rows(PDO $pdo, string $table, array $fields): array
{
    if (!table_exists($pdo, $table)) {
        return [];
    }

    $safeFields = array_map(static fn ($field) => '`' . str_replace('`', '', $field) . '`', $fields);
    $sql = sprintf('SELECT %s FROM `%s` ORDER BY id DESC LIMIT 10', implode(', ', $safeFields), str_replace('`', '', $table));
    return $pdo->query($sql)->fetchAll() ?: [];
}

function render_module_table(array $headers, array $rows): void
{
    if (!$rows) {
        echo '<p class="helper">Nincs megjeleníthető adat.</p>';
        return;
    }

    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . h($header) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . h((string) $value) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
