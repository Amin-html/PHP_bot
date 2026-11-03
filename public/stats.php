<?php

declare(strict_types=1);

require __DIR__ . '/../src/queue.php';

require_operator();

function stat_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fmt_minutes($seconds): string
{
    return $seconds === null ? '—' : number_format((float) $seconds / 60, 1, ',', '') . ' мин';
}

function fmt_rating($value): string
{
    return $value === null ? '—' : number_format((float) $value, 1, ',', '') . ' ⭐';
}

$today = date('Y-m-d');

$summary = stat_rows(
    "SELECT COUNT(*) AS total,
            SUM(status = 'done') AS done,
            SUM(status = 'skipped') AS skipped,
            SUM(status = 'cancelled') AS cancelled,
            SUM(status = 'waiting') AS waiting,
            AVG(TIMESTAMPDIFF(SECOND, created_at, called_at)) AS avg_wait,
            AVG(CASE WHEN status = 'done' THEN TIMESTAMPDIFF(SECOND, called_at, finished_at) END) AS avg_service,
            AVG(rating) AS avg_rating,
            COUNT(rating) AS rated
     FROM tickets WHERE ticket_date = ?",
    [$today]
)[0];

$byService = stat_rows(
    "SELECT s.prefix, s.name, COUNT(t.id) AS total, COALESCE(SUM(t.status = 'done'), 0) AS done,
            AVG(t.rating) AS avg_rating
     FROM services s
     LEFT JOIN tickets t ON t.service_id = s.id AND t.ticket_date = ?
     GROUP BY s.id ORDER BY s.id",
    [$today]
);

$byWindow = stat_rows(
    "SELECT window_no, COUNT(*) AS served,
            AVG(TIMESTAMPDIFF(SECOND, called_at, finished_at)) AS avg_service
     FROM tickets
     WHERE ticket_date = ? AND window_no IS NOT NULL AND status = 'done'
     GROUP BY window_no ORDER BY window_no",
    [$today]
);

$byDay = stat_rows(
    "SELECT ticket_date, COUNT(*) AS total, SUM(status = 'done') AS done, AVG(rating) AS avg_rating
     FROM tickets WHERE ticket_date >= ?
     GROUP BY ticket_date ORDER BY ticket_date DESC",
    [date('Y-m-d', strtotime('-6 days'))]
);

$cards = [
    ['Талонов сегодня', (int) $summary['total']],
    ['Обслужено', (int) $summary['done']],
    ['Среднее ожидание', fmt_minutes($summary['avg_wait'])],
    ['Среднее обслуживание', fmt_minutes($summary['avg_service'])],
    ['Средняя оценка', fmt_rating($summary['avg_rating']) . ' (' . (int) $summary['rated'] . ')'],
    ['Ждут сейчас', (int) $summary['waiting']],
    ['Пропущено', (int) $summary['skipped']],
    ['Отменено', (int) $summary['cancelled']],
];

page_header('Статистика', 'stats', false, 30);
?>
<h1 class="h3 mb-3">Статистика за сегодня</h1>

<div class="row g-3 mb-4">
    <?php foreach ($cards as [$label, $value]): ?>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small"><?= h($label) ?></div>
                    <div class="fs-4 fw-bold"><?= h((string) $value) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">По услугам</div>
            <table class="table mb-0">
                <thead><tr><th>Услуга</th><th>Талонов</th><th>Обслужено</th><th>Оценка</th></tr></thead>
                <tbody>
                <?php foreach ($byService as $r): ?>
                    <tr>
                        <td><strong><?= h($r['prefix']) ?></strong> <?= h($r['name']) ?></td>
                        <td><?= (int) $r['total'] ?></td>
                        <td><?= (int) $r['done'] ?></td>
                        <td><?= h(fmt_rating($r['avg_rating'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">По окнам</div>
            <table class="table mb-0">
                <thead><tr><th>Окно</th><th>Обслужено</th><th>Среднее время</th></tr></thead>
                <tbody>
                <?php foreach ($byWindow as $r): ?>
                    <tr>
                        <td>№ <?= (int) $r['window_no'] ?></td>
                        <td><?= (int) $r['served'] ?></td>
                        <td><?= h(fmt_minutes($r['avg_service'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byWindow): ?>
                    <tr><td colspan="3" class="text-muted">Пока никого не обслужили</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header">Последние 7 дней</div>
            <table class="table mb-0">
                <thead><tr><th>Дата</th><th>Талонов</th><th>Обслужено</th><th>Средняя оценка</th></tr></thead>
                <tbody>
                <?php foreach ($byDay as $r): ?>
                    <tr>
                        <td><?= h(date('d.m.Y', strtotime($r['ticket_date']))) ?></td>
                        <td><?= (int) $r['total'] ?></td>
                        <td><?= (int) $r['done'] ?></td>
                        <td><?= h(fmt_rating($r['avg_rating'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php page_footer();