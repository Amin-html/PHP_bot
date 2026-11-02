<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (operator_logged_in()) {
    header('Location: /operator.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        // Простая защита от подбора: пауза при неверном пароле
        if (operator_login((string) ($_POST['password'] ?? ''))) {
            header('Location: /operator.php');
            exit;
        }
        sleep(1);
        $error = 'Неверный пароль';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

page_header('Вход', 'operator');
?>
<div class="row justify-content-center">
    <div class="col-md-4">
        <h1 class="h3 mb-3">Вход для оператора</h1>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input class="form-control mb-3" type="password" name="password" placeholder="Пароль" autofocus required>
            <button class="btn btn-primary w-100" type="submit">Войти</button>
        </form>
    </div>
</div>
<?php page_footer();
