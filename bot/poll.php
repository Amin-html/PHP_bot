<?php

declare(strict_types=1);

// Telegram-бот электронной очереди (long polling). Запускается в контейнере bot.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../src/queue.php';

if ((getenv('BOT_TOKEN') ?: '') === '') {
    fwrite(STDERR, "BOT_TOKEN пуст. Впишите токен в .env и выполните: docker compose up -d bot\n");
    exit(1);
}

function esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function menu_keyboard(): array
{
    $rows = [];
    $services = db()->query('SELECT id, name, prefix FROM services WHERE is_active = 1 ORDER BY id')->fetchAll();
    foreach ($services as $s) {
        $rows[] = [['text' => $s['prefix'] . ' · ' . $s['name'], 'callback_data' => 'svc:' . $s['id']]];
    }
    $rows[] = [
        ['text' => '🎫 Мой талон', 'callback_data' => 'status'],
        ['text' => '❌ Отменить', 'callback_data' => 'cancel'],
    ];
    return ['inline_keyboard' => $rows];
}

function ticket_text(array $t): string
{
    $text = '🎫 <b>Талон ' . esc($t['code']) . '</b>' . "\n" . esc($t['service_name']) . "\n";
    if ($t['status'] === 'waiting') {
        $text .= 'Перед вами в очереди: <b>' . people_ahead($t) . '</b>';
    } elseif ($t['status'] === 'called') {
        $text .= '🔔 Вас вызывают! Окно № <b>' . (int) $t['window_no'] . '</b>';
    }
    return $text;
}

function handle_action(int $chatId, string $action): void
{
    if (str_starts_with($action, 'svc:')) {
        $existing = active_ticket_for_chat($chatId);
        if ($existing) {
            tg_send($chatId, 'У вас уже есть талон:' . "\n\n" . ticket_text($existing));
            return;
        }
        $ticket = get_ticket(create_ticket((int) substr($action, 4), $chatId));
        tg_send($chatId, '✅ Талон выдан' . "\n\n" . ticket_text($ticket)
            . "\n\nКогда подойдёт ваша очередь, я напишу сюда.");
        return;
    }

    switch ($action) {
        case 'status':
            $ticket = active_ticket_for_chat($chatId);
            if ($ticket) {
                tg_send($chatId, ticket_text($ticket));
            } else {
                tg_send($chatId, 'У вас нет активного талона. Выберите услугу:', menu_keyboard());
            }
            return;

        case 'cancel':
            $ticket = active_ticket_for_chat($chatId);
            if (!$ticket) {
                tg_send($chatId, 'Нечего отменять: активного талона нет.');
            } elseif ($ticket['status'] === 'called') {
                tg_send($chatId, 'Вас уже вызвали к окну № ' . (int) $ticket['window_no'] . ', отменить нельзя.');
            } elseif (cancel_ticket((int) $ticket['id'])) {
                tg_send($chatId, 'Талон ' . esc($ticket['code']) . ' отменён.', menu_keyboard());
            } else {
                tg_send($chatId, 'Не удалось отменить: статус талона уже изменился.');
            }
            return;

        default:
            tg_send($chatId, 'Добро пожаловать в электронную очередь! Выберите услугу:', menu_keyboard());
    }
}

function handle_update(array $update): void
{
    if (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        try {
            tg_call('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        } catch (Throwable $e) {
            // не критично: просто пропадёт «часики» на кнопке
        }
        handle_action((int) $cb['message']['chat']['id'], (string) ($cb['data'] ?? ''));
        return;
    }

    $message = $update['message'] ?? null;
    if (!$message || !isset($message['text'])) {
        return;
    }

    $chatId = (int) $message['chat']['id'];
    $command = strtolower(explode('@', explode(' ', trim($message['text']))[0])[0]);

    $action = match ($command) {
        '/ticket', '/status' => 'status',
        '/cancel'            => 'cancel',
        default              => 'menu',
    };
    handle_action($chatId, $action);
}

// Long polling и webhook взаимоисключающие
tg_call('deleteWebhook');
fwrite(STDOUT, "Бот запущен (long polling)\n");

$offset = 0;
while (true) {
    try {
        $response = tg_call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => 25,
            'allowed_updates' => ['message', 'callback_query'],
        ], 40);

        foreach ($response['result'] ?? [] as $update) {
            $offset = (int) $update['update_id'] + 1;
            try {
                handle_update($update);
            } catch (PDOException $e) {
                error_log('DB: ' . $e->getMessage());
                if (str_contains($e->getMessage(), 'gone away')) {
                    exit(1); // docker перезапустит контейнер с новым подключением
                }
            } catch (Throwable $e) {
                error_log('update ' . $update['update_id'] . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('polling: ' . $e->getMessage());
        sleep(3);
    }
}
