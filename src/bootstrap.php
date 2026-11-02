<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Bishkek');

const WINDOWS_COUNT = 5;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_NAME') ?: ''
        );
        $pdo = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+06:00'");
    }

    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Сессия устарела, обновите страницу');
    }
}

function page_header(string $title, string $active = '', bool $dark = false): void
{
    $links = [
        'client'   => ['/', 'Клиент'],
        'operator' => ['/operator.php', 'Оператор'],
        'board'    => ['/board.php', 'Табло'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="<?= $dark ? 'dark' : 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — Электронная очередь</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand navbar-dark bg-primary mb-4 d-print-none">
    <div class="container">
        <span class="navbar-brand">Электронная очередь</span>
        <div class="navbar-nav">
            <?php foreach ($links as $key => [$url, $label]): ?>
                <a class="nav-link <?= $key === $active ? 'active fw-bold' : '' ?>" href="<?= $url ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
<div class="container">
<?php
}

function page_footer(): void
{
    echo "</div>\n</body>\n</html>";
}