<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function create_ticket(int $serviceId): int
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

        $stmt = $pdo->prepare('INSERT INTO tickets (service_id, ticket_date, number, code) VALUES (?, ?, ?, ?)');
        $stmt->execute([$serviceId, $today, $number, $code]);
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

function finish_current(int $window, string $status): void
{
    if (!in_array($status, ['done', 'skipped'], true)) {
        throw new InvalidArgumentException('Неверный статус');
    }
    $stmt = db()->prepare(
        "UPDATE tickets SET status = ?, finished_at = NOW()
         WHERE window_no = ? AND status = 'called' AND ticket_date = ?"
    );
    $stmt->execute([$status, $window, date('Y-m-d')]);
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