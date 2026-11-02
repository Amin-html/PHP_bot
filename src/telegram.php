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

function tg_send(int $chatId, string $text, ?array $keyboard = null): void
{
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard !== null) {
        $params['reply_markup'] = $keyboard;
    }
    tg_call('sendMessage', $params);
}

/** Сообщение клиенту, когда оператор вызвал его талон. Ошибки не ломают панель оператора. */
function notify_ticket_called(array $ticket): void
{
    if (empty($ticket['chat_id'])) {
        return;
    }
    try {
        tg_send(
            (int) $ticket['chat_id'],
            '🔔 <b>Ваш талон ' . htmlspecialchars((string) $ticket['code'], ENT_QUOTES, 'UTF-8') . '</b>' . "\n"
            . 'Пройдите к окну № <b>' . (int) $ticket['window_no'] . '</b>'
        );
    } catch (Throwable $e) {
        error_log('notify_ticket_called: ' . $e->getMessage());
    }
}
