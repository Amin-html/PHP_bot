<?php

declare(strict_types=1);

/**
 * Вызов метода Telegram Bot API. Бросает RuntimeException при ошибке.
 * Если BOT_TOKEN пуст, возвращает null (уведомления просто отключены).
 */
function tg_call(string $method, array $params = [], int $timeout = 10): ?array
{
    $token = getenv('BOT_TOKEN') ?: '';
    if ($token === '') {
        return null;
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $proxy = getenv('TG_PROXY') ?: '';
    if ($proxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Telegram недоступен: ' . $error);
    }
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        throw new RuntimeException('Telegram API: ' . ($data['description'] ?? 'неверный ответ'));
    }
    return $data;
}

function tg_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tg_send(int $chatId, string $text, ?array $keyboard = null, int $timeout = 10): void
{
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard !== null) {
        $params['reply_markup'] = $keyboard;
    }
    tg_call('sendMessage', $params, $timeout);
}

/** Меняет текст уже отправленного сообщения (и кнопки). Без кнопок — убирает их. */
function tg_edit(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
{
    try {
        tg_call('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard ?? ['inline_keyboard' => []],
        ]);
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'not modified')) {
            throw $e;
        }
    }
}

/**
 * Уведомление из панели оператора: ошибки не ломают панель.
 * Если Telegram недоступен, остальные уведомления в этом запросе пропускаются,
 * чтобы оператор не ждал по несколько секунд на каждое.
 */
function tg_notify(int $chatId, string $text, ?array $keyboard = null): bool
{
    static $down = false;
    if ($down) {
        return false;
    }
    try {
        tg_send($chatId, $text, $keyboard, 4);
        return true;
    } catch (Throwable $e) {
        error_log('tg_notify: ' . $e->getMessage());
        if (str_starts_with($e->getMessage(), 'Telegram недоступен')) {
            $down = true;
        }
        return false;
    }
}

/** Клиенту: его талон вызвали (или вызвали повторно). */
function notify_ticket_called(array $ticket, bool $repeat = false): bool
{
    if (empty($ticket['chat_id'])) {
        return false;
    }
    $code = tg_esc((string) $ticket['code']);
    $head = $repeat
        ? '🔁 <b>Повторный вызов: талон ' . $code . '</b>'
        : '🔔 <b>Ваш талон ' . $code . '</b>';

    return tg_notify(
        (int) $ticket['chat_id'],
        $head . "\n" . 'Пройдите к окну № <b>' . (int) $ticket['window_no'] . '</b>'
    );
}

/** Клиенту: талон обслужен (просим оценку) или пропущен. */
function notify_ticket_finished(array $ticket, string $status): void
{
    if (empty($ticket['chat_id'])) {
        return;
    }
    $chatId = (int) $ticket['chat_id'];
    $code = tg_esc((string) $ticket['code']);

    if ($status === 'done') {
        $stars = array_map(
            fn (int $n) => ['text' => '⭐' . $n, 'callback_data' => 'rate:' . (int) $ticket['id'] . ':' . $n],
            [1, 2, 3, 4, 5]
        );
        tg_notify(
            $chatId,
            '✅ <b>Талон ' . $code . ' обслужен.</b>' . "\n" . 'Спасибо! Оцените обслуживание:',
            ['inline_keyboard' => [$stars]]
        );
        return;
    }

    tg_notify(
        $chatId,
        '⏭ <b>Талон ' . $code . ' пропущен</b> — вы не подошли к окну.' . "\n"
        . 'Чтобы встать в очередь снова, отправьте /start.'
    );
}