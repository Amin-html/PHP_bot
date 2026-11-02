<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db();

$sql = file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

if ((int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
    $insert = $pdo->prepare('INSERT INTO services (name, prefix) VALUES (?, ?)');
    foreach ([
        ['Консультация', 'A'],
        ['Оформление документов', 'B'],
        ['Оплата', 'C'],
        ['Получение результата', 'D'],
    ] as [$name, $prefix]) {
        $insert->execute([$name, $prefix]);
    }
}

echo "Миграция выполнена\n";