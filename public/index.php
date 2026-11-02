<?php

declare(strict_types=1);

require __DIR__ . '/../src/queue.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $id = create_ticket((int) ($_POST['service_id'] ?? 0));
        header('Location: /?ticket=' . $id);
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $error = 'Не удалось выдать талон, попробуйте ещё раз';
    }
}

$ticket = isset($_GET['ticket']) ? get_ticket((int) $_GET['ticket']) : null;
$services = db()->query('SELECT id, name, prefix FROM services WHERE is_active = 1 ORDER BY id')->fetchAll();

page_header('Получить талон', 'client');
?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($ticket): ?>
    <?php $ahead = $ticket['status'] === 'waiting' ? people_ahead($ticket) : null; ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card text-center shadow">
                <div class="card-body py-5">
                    <p class="text-muted mb-1">Ваш талон</p>
                    <div class="display-1 fw-bold text-primary"><?= h($ticket['code']) ?></div>
                    <p class="fs-5 mt-3"><?= h($ticket['service_name']) ?></p>
                    <p class="text-muted"><?= h(date('d.m.Y H:i', strtotime($ticket['created_at']))) ?></p>
                    <?php if ($ticket['status'] === 'waiting'): ?>
                        <div class="alert alert-info mb-0">Перед вами в очереди: <strong><?= $ahead ?></strong></div>
                    <?php elseif ($ticket['status'] === 'called'): ?>
                        <div class="alert alert-success mb-0">Вас вызывают! Окно № <strong><?= (int) $ticket['window_no'] ?></strong></div>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">Талон закрыт</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3 d-print-none">
                <a class="btn btn-outline-primary flex-fill" href="/?ticket=<?= (int) $ticket['id'] ?>">Обновить статус</a>
                <button class="btn btn-outline-secondary flex-fill" onclick="window.print()">Печать</button>
                <a class="btn btn-primary flex-fill" href="/">Новый талон</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <h1 class="h3 mb-4">Выберите услугу</h1>
    <div class="row g-3">
        <?php foreach ($services as $service): ?>
            <div class="col-md-6">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                    <button class="btn btn-outline-primary w-100 py-4 fs-5" type="submit">
                        <span class="badge bg-primary me-2"><?= h($service['prefix']) ?></span><?= h($service['name']) ?>
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php page_footer();