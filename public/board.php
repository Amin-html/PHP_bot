<?php

declare(strict_types=1);

require __DIR__ . '/../src/queue.php';

$called = called_tickets();
$waiting = waiting_tickets(8);

page_header('Табло', 'board', true, 5);
?>
<div class="row g-4">
    <div class="col-lg-8">
        <h1 class="h3 mb-3">Вызываются</h1>
        <?php if (!$called): ?>
            <p class="text-muted fs-4">Пока никого не вызывали</p>
        <?php endif; ?>
        <div class="row g-3">
            <?php foreach ($called as $i => $row): ?>
                <div class="col-md-6">
                    <div class="card <?= $i === 0 ? 'border-success border-3' : '' ?>">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <span class="display-4 fw-bold"><?= h($row['code']) ?></span>
                            <span class="fs-3">Окно <strong><?= (int) $row['window_no'] ?></strong></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <h2 class="h4 mb-3">Ожидают</h2>
        <ul class="list-group">
            <?php foreach ($waiting as $row): ?>
                <li class="list-group-item fs-5"><strong><?= h($row['code']) ?></strong>
                    <small class="text-muted ms-2"><?= h($row['service_name']) ?></small></li>
            <?php endforeach; ?>
            <?php if (!$waiting): ?>
                <li class="list-group-item text-muted">Очередь пуста</li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php page_footer();