<?php

declare(strict_types=1);

require __DIR__ . '/../src/queue.php';

require_operator();

$window = max(1, min(WINDOWS_COUNT, (int) ($_GET['w'] ?? 1)));
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $action = $_POST['action'] ?? '';

        if ($action === 'next') {
            $previous = current_ticket($window);
            $called = call_next($window);
            $_SESSION['flash'] = $called ? null : 'Очередь пуста';
            if ($previous) {
                notify_ticket_finished($previous, 'done'); // предыдущий обслужен, просим оценку
            }
            if ($called) {
                notify_ticket_called($called);
            }
            notify_queue_progress(); // «скоро ваша очередь» для тех, кто подошёл ближе
        } elseif ($action === 'done' || $action === 'skip') {
            $status = $action === 'done' ? 'done' : 'skipped';
            $finished = finish_current($window, $status);
            if ($finished) {
                notify_ticket_finished($finished, $status);
            }
        } elseif ($action === 'recall') {
            $current = current_ticket($window);
            if (!$current) {
                $_SESSION['flash'] = 'Нет активного клиента';
            } elseif (empty($current['chat_id'])) {
                $_SESSION['flash'] = 'Талон выдан не через Telegram — вызовите клиента голосом';
            } else {
                $_SESSION['flash'] = notify_ticket_called($current, true)
                    ? 'Повторный вызов отправлен'
                    : 'Не удалось отправить сообщение в Telegram';
            }
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $_SESSION['flash'] = 'Ошибка операции, попробуйте ещё раз';
    }
    header('Location: /operator.php?w=' . $window);
    exit;
}

$message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$current = current_ticket($window);
$waiting = waiting_tickets(10);

page_header('Панель оператора', 'operator');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Окно № <?= $window ?></h1>
    <div class="btn-group">
        <?php for ($i = 1; $i <= WINDOWS_COUNT; $i++): ?>
            <a class="btn btn-sm <?= $i === $window ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="/operator.php?w=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-warning"><?= h($message) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body text-center">
                <?php if ($current): ?>
                    <p class="text-muted mb-1">Обслуживается</p>
                    <div class="display-2 fw-bold text-success"><?= h($current['code']) ?></div>
                    <p class="fs-5"><?= h($current['service_name']) ?></p>
                <?php else: ?>
                    <p class="text-muted py-5 fs-5">Нет активного клиента</p>
                <?php endif; ?>

                <form method="post" class="d-grid gap-2">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary btn-lg" name="action" value="next">Вызвать следующего</button>
                    <?php if ($current): ?>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary" name="action" value="recall">🔁 Повторить вызов</button>
                            <button class="btn btn-success flex-fill" name="action" value="done">Завершить</button>
                            <button class="btn btn-outline-danger flex-fill" name="action" value="skip">Пропустить</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between">
                <span>В очереди: <strong><?= count_by_status('waiting') ?></strong></span>
                <span class="text-muted">Обслужено сегодня: <?= count_by_status('done') ?></span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($waiting as $row): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <strong><?= h($row['code']) ?></strong>
                        <span><?= h($row['service_name']) ?></span>
                        <span class="text-muted"><?= h(date('H:i', strtotime($row['created_at']))) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$waiting): ?>
                    <li class="list-group-item text-muted">Очередь пуста</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php page_footer();