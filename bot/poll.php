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

const BOT_COMMANDS = [
    ['command' => 'start',  'description' => 'Выбрать услугу и взять талон'],
    ['command' => 'ticket', 'description' => 'Мой талон и ожидание'],
    ['command' => 'cancel', 'description' => 'Отменить талон'],
    ['command' => 'help',   'description' => 'Как это работает'],
];

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
        $ahead = people_ahead($t);
        $text .= 'Перед вами в очереди: <b>' . $ahead . '</b>' . "\n";
        $text .= $ahead === 0
            ? '⏱ Вы следующий!'
            : '⏱ Примерное ожидание: <b>≈ ' . estimate_wait_minutes($ahead) . ' мин</b>';
    } elseif ($t['status'] === 'called') {
        $text .= '🔔 Вас вызывают! Окно № <b>' . (int) $t['window_no'] . '</b>';
    }
    // Время нужно, чтобы по кнопке «Обновить» было видно, что данные свежие
    return $text . "\n\n<i>Обновлено в " . date('H:i:s') . '</i>';
}

function ticket_keyboard(array $t): array
{
    $row = [['text' => '🔄 Обновить', 'callback_data' => 'refresh']];
    if ($t['status'] === 'waiting') {
        $row[] = ['text' => '❌ Отменить', 'callback_data' => 'cancel'];
    }
    return ['inline_keyboard' => [$row]];
}

/** Если ответ на нажатие кнопки — правим то же сообщение, если на команду — шлём новое. */
function respond(int $chatId, ?int $messageId, string $text, ?array $keyboard = null): void
{
    if ($messageId !== null) {
        tg_edit($chatId, $messageId, $text, $keyboard);
    } else {
        tg_send($chatId, $text, $keyboard);
    }
}

function handle_action(int $chatId, string $action, ?int $messageId = null): void
{
    if (str_starts_with($action, 'svc:')) {
        $existing = active_ticket_for_chat($chatId);
        if ($existing) {
            tg_send($chatId, 'У вас уже есть талон:' . "\n\n" . ticket_text($existing), ticket_keyboard($existing));
            return;
        }
        try {
            $id = create_ticket((int) substr($action, 4), $chatId);
        } catch (RuntimeException $e) {
            tg_send($chatId, '⚠️ ' . $e->getMessage());
            return;
        }
        $ticket = get_ticket($id);
        // Если впереди и так мало людей, отдельное «скоро ваша очередь» не нужно
        if (people_ahead($ticket) <= SOON_THRESHOLD) {
            mark_soon_notified($id);
        }
        tg_send(
            $chatId,
            '✅ Талон выдан' . "\n\n" . ticket_text($ticket)
            . "\n\nЯ напишу, когда впереди останется " . SOON_THRESHOLD . ' человека и когда вас вызовут.',
            ticket_keyboard($ticket)
        );
        return;
    }

    if (str_starts_with($action, 'rate:')) {
        [, $ticketId, $stars] = array_pad(explode(':', $action), 3, '0');
        if (rate_ticket((int) $ticketId, $chatId, (int) $stars)) {
            respond($chatId, $messageId, '⭐ Спасибо за оценку: ' . (int) $stars . ' из 5!');
        } else {
            tg_send($chatId, 'Оценка уже сохранена или талон не найден.');
        }
        return;
    }

    switch ($action) {
        case 'status':
            $ticket = active_ticket_for_chat($chatId);
            if ($ticket) {
                tg_send($chatId, ticket_text($ticket), ticket_keyboard($ticket));
            } else {
                tg_send($chatId, 'У вас нет активного талона. Выберите услугу:', menu_keyboard());
            }
            return;

        case 'refresh':
            $ticket = active_ticket_for_chat($chatId);
            if ($ticket) {
                respond($chatId, $messageId, ticket_text($ticket), ticket_keyboard($ticket));
            } else {
                respond($chatId, $messageId, 'Активного талона нет. Выберите услугу:', menu_keyboard());
            }
            return;

        case 'cancel':
            $ticket = active_ticket_for_chat($chatId);
            if (!$ticket) {
                respond($chatId, $messageId, 'Нечего отменять: активного талона нет.', menu_keyboard());
            } elseif ($ticket['status'] === 'called') {
                tg_send($chatId, 'Вас уже вызвали к окну № ' . (int) $ticket['window_no'] . ', отменить нельзя.');
            } elseif (cancel_ticket((int) $ticket['id'])) {
                respond($chatId, $messageId, 'Талон ' . esc($ticket['code']) . ' отменён.', menu_keyboard());
                notify_queue_progress(); // очередь сдвинулась
            } else {
                tg_send($chatId, 'Не удалось отменить: статус талона уже изменился.');
            }
            return;

        case 'help':
            tg_send(
                $chatId,
                'ℹ️ <b>Как это работает</b>' . "\n\n"
                . '1. Выберите услугу — получите талон.' . "\n"
                . '2. Я сообщу, когда впереди останется ' . SOON_THRESHOLD . ' человека, и когда вас вызовут к окну.' . "\n"
                . '3. Кнопка «🔄 Обновить» показывает, сколько человек впереди и примерное ожидание.' . "\n"
                . '4. После обслуживания можно поставить оценку от 1 до 5.' . "\n\n"
                . '<b>Команды:</b>' . "\n"
                . '/start — выбрать услугу' . "\n"
                . '/ticket — мой талон' . "\n"
                . '/cancel — отменить талон' . "\n"
                . '/help — эта справка'
            );
            return;

        default:
            tg_send($chatId, 'Добро пожаловать в электронную очередь! Выберите услугу:', menu_keyboard());
    }
}

/** Чат, из которого пришло обновление (для ответа об ошибке). */
function update_chat_id(array $update): ?int
{
    $id = $update['callback_query']['message']['chat']['id']
        ?? $update['callback_query']['from']['id']
        ?? $update['message']['chat']['id']
        ?? null;
    return $id === null ? null : (int) $id;
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
        $chatId = (int) ($cb['message']['chat']['id'] ?? $cb['from']['id'] ?? 0);
        $messageId = isset($cb['message']['message_id']) ? (int) $cb['message']['message_id'] : null;
        if ($chatId !== 0) {
            handle_action($chatId, (string) ($cb['data'] ?? ''), $messageId);
        }
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
        '/help'              => 'help',
        default              => 'menu',
    };
    handle_action($chatId, $action);
}

// Long polling и webhook взаимоисключающие
while (true) {
    try {
        tg_call('deleteWebhook');
        break;
    } catch (Throwable $e) {
        error_log('Telegram недоступен, повтор через 5 с: ' . $e->getMessage());
        sleep(5);
    }
}

// Меню команд (кнопка «Меню» рядом с полем ввода)
try {
    tg_call('setMyCommands', ['commands' => BOT_COMMANDS]);
} catch (Throwable $e) {
    error_log('setMyCommands: ' . $e->getMessage());
}

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
                // Пользователь не должен оставаться без ответа
                $chatId = update_chat_id($update);
                if ($chatId !== null) {
                    try {
                        tg_send($chatId, '⚠️ Не получилось выполнить действие. Попробуйте ещё раз или отправьте /start.');
                    } catch (Throwable) {
                        // Telegram недоступен — ответить нечем
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('polling: ' . $e->getMessage());
        sleep(3);
    }
}