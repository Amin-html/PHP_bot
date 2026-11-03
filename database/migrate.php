<?php

declare(strict_types=1);

// Добавляет поля notified_soon и rating. Можно запускать повторно.
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$columns = [
    'notified_soon' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER chat_id',
    'rating'        => 'TINYINT UNSIGNED NULL AFTER notified_soon',
];

$exists = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = ?"
);

foreach ($columns as $name => $definition) {
    $exists->execute([$name]);
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN {$name} {$definition}");
        echo "Добавлено поле {$name}\n";
    }
}
echo "Миграция выполнена\n";