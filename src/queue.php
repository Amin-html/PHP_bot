<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/telegram.php';

function create_ticket(int $serviceId, ?int $chatId = null): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Блокировка строки услуги: два клиента одновременно не получат один номер
        $stmt = $pdo->prepare('SELECT id, prefix FROM services WHERE id = ? AND is_active = 1 FOR UPDATE');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();
        if (!$service) {
            throw new RuntimeException('Услуга не найдена');
        }

        $today = date('Y-m-d');
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM tickets WHERE service_id = ? AND ticket_date = ?');
        $stmt->execute([$serviceId, $today]);
        $number = (int) $stmt->fetchColumn();
        $code = $service['prefix'] . str_pad((string) $number, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('INSERT INTO tickets (service_id, ticket_date, number, code, chat_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$serviceId, $today, $number, $code, $chatId]);
        $id = (int) $pdo->lastInsertId();

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_ticket(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, s.name AS service_name FROM tickets t JOIN services s ON s.id = t.service_id WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function people_ahead(array $ticket): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM tickets WHERE ticket_date = ? AND status = 'waiting' AND id < ?"
    );
    $stmt->execute([$ticket['ticket_date'], $ticket['id']]);
    return (int) $stmt->fetchColumn();
}

function finish_current(int $window, string $status): ?array
{
    if (!in_array($status, ['done', 'skipped'], true)) {
        throw new InvalidArgumentException('Неверный статус');
    }
    $ticket = current_ticket($window);
    if (!$ticket) {
        return null;
    }
    $stmt = db()->prepare(
        "UPDATE tickets SET status = ?, finished_at = NOW() WHERE id = ? AND status = 'called'"
    );
    $stmt->execute([$status, $ticket['id']]);
    return $stmt->rowCount() > 0 ? $ticket : null;
}

function call_next(int $window): ?array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $today = date('Y-m-d');

        // Предыдущий талон этого окна считается обслуженным
        $stmt = $pdo->prepare(
            "UPDATE tickets SET status = 'done', finished_at = NOW()
             WHERE window_no = ? AND status = 'called' AND ticket_date = ?"
        );
        $stmt->execute([$window, $today]);

        $stmt = $pdo->prepare(
            "SELECT id FROM tickets WHERE status = 'waiting' AND ticket_date = ?
             ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $stmt->execute([$today]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $pdo->commit();
            return null;
        }

        $stmt = $pdo->prepare("UPDATE tickets SET status = 'called', window_no = ?, called_at = NOW() WHERE id = ?");
        $stmt->execute([$window, $id]);
        $pdo->commit();

        return get_ticket((int) $id);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function current_ticket(int $window): ?array
{
    $stmt = db()->prepare(
        "SELECT t.*, s.name AS service_name FROM tickets t JOIN services s ON s.id = t.service_id
         WHERE t.window_no = ? AND t.status = 'called' AND t.ticket_date = ?
         ORDER BY t.called_at DESC LIMIT 1"
    );
    $stmt->execute([$window, date('Y-m-d')]);
    return $stmt->fetch() ?: null;
}

function waiting_tickets(int $limit = 10): array
{
    $stmt = db()->prepare(
        "SELECT t.code, t.created_at, s.name AS service_name
         FROM tickets t JOIN services s ON s.id = t.service_id
         WHERE t.status = 'waiting' AND t.ticket_date = ? ORDER BY t.id LIMIT " . (int) $limit
    );
    $stmt->execute([date('Y-m-d')]);
    return $stmt->fetchAll();
}

function count_by_status(string $status): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM tickets WHERE status = ? AND ticket_date = ?');
    $stmt->execute([$status, date('Y-m-d')]);
    return (int) $stmt->fetchColumn();
}

function called_tickets(): array
{
    $stmt = db()->prepare(
        "SELECT code, window_no FROM tickets
         WHERE status = 'called' AND ticket_date = ? ORDER BY called_at DESC"
    );
    $stmt->execute([date('Y-m-d')]);
    return $stmt->fetchAll();
}
function active_ticket_for_chat(int $chatId): ?array
{
    $stmt = db()->prepare(
        "SELECT t.*, s.name AS service_name FROM tickets t JOIN services s ON s.id = t.service_id
         WHERE t.chat_id = ? AND t.ticket_date = ? AND t.status IN ('waiting', 'called')
         ORDER BY t.id DESC LIMIT 1"
    );
    $stmt->execute([$chatId, date('Y-m-d')]);
    return $stmt->fetch() ?: null;
}

function cancel_ticket(int $id): bool
{
    $stmt = db()->prepare(
        "UPDATE tickets SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status = 'waiting'"
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}



// ---------- Уведомления, ожидание, оценки ----------

const SOON_THRESHOLD = 2;            // «скоро ваша очередь», когда впереди <= 2
const DEFAULT_SERVICE_SECONDS = 300; // если истории обслуживания ещё нет

/** Среднее время обслуживания за последние 7 дней (в секундах). */
function avg_service_seconds(): int
{
    $stmt = db()->prepare(
        "SELECT AVG(TIMESTAMPDIFF(SECOND, called_at, finished_at)) FROM tickets
         WHERE status = 'done' AND called_at IS NOT NULL AND finished_at IS NOT NULL
           AND ticket_date >= ?"
    );
    $stmt->execute([date('Y-m-d', strtotime('-7 days'))]);
    $avg = $stmt->fetchColumn();
    if ($avg === false || $avg === null) {
        return DEFAULT_SERVICE_SECONDS;
    }
    return max(60, min(1800, (int) round((float) $avg))); // отсекаем выбросы
}

/** Сколько окон сегодня реально работало. */
function active_windows(): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(DISTINCT window_no) FROM tickets WHERE ticket_date = ? AND window_no IS NOT NULL'
    );
    $stmt->execute([date('Y-m-d')]);
    return max(1, (int) $stmt->fetchColumn());
}

/** Примерное ожидание в минутах для клиента, перед которым $ahead человек. */
function estimate_wait_minutes(int $ahead): int
{
    if ($ahead <= 0) {
        return 0;
    }
    return max(1, (int) round($ahead * avg_service_seconds() / active_windows() / 60));
}

function mark_soon_notified(int $ticketId): void
{
    db()->prepare('UPDATE tickets SET notified_soon = 1 WHERE id = ?')->execute([$ticketId]);
}

/** Пишет тем, у кого впереди осталось <= SOON_THRESHOLD человек (один раз на талон). */
function notify_queue_progress(): void
{
    $stmt = db()->prepare(
        "SELECT id, code, chat_id, notified_soon FROM tickets
         WHERE status = 'waiting' AND ticket_date = ? ORDER BY id LIMIT " . (SOON_THRESHOLD + 1)
    );
    $stmt->execute([date('Y-m-d')]);

    // ключ строки = сколько человек впереди
    foreach ($stmt->fetchAll() as $ahead => $t) {
        if (empty($t['chat_id']) || (int) $t['notified_soon'] === 1) {
            continue;
        }
        $text = '⏳ <b>Скоро ваша очередь</b> — талон ' . tg_esc((string) $t['code']) . "\n"
            . ($ahead === 0
                ? 'Вы следующий! Подойдите к окнам.'
                : 'Перед вами: <b>' . $ahead . '</b> (≈ ' . estimate_wait_minutes($ahead) . ' мин). Приготовьтесь.');
        if (tg_notify((int) $t['chat_id'], $text)) {
            mark_soon_notified((int) $t['id']);
        }
    }
}

/** Сохраняет оценку 1–5 (только владелец талона, только обслуженный, только один раз). */
function rate_ticket(int $ticketId, int $chatId, int $rating): bool
{
    if ($rating < 1 || $rating > 5) {
        return false;
    }
    $stmt = db()->prepare(
        "UPDATE tickets SET rating = ?
         WHERE id = ? AND chat_id = ? AND status = 'done' AND rating IS NULL"
    );
    $stmt->execute([$rating, $ticketId, $chatId]);
    return $stmt->rowCount() > 0;
}