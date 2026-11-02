<?php

declare(strict_types=1);

// Добавляет в уже созданную БД поле chat_id и статус 'cancelled'. Можно запускать повторно.

require __DIR__ . '/../src/bootstrap.php';

$pdo = db();

$hasChatId = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'chat_id'"
)->fetchColumn();

if (!$hasChatId) {
    $pdo->exec(
        'ALTER TABLE tickets
         ADD COLUMN chat_id BIGINT NULL AFTER window_no,
         ADD KEY idx_chat (chat_id, ticket_date, status)'
    );
}

$pdo->exec(
    "ALTER TABLE tickets MODIFY status
     ENUM('waiting','called','done','skipped','cancelled') NOT NULL DEFAULT 'waiting'"
);

echo "Миграция бота выполнена\n";
