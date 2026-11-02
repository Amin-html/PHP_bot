<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$env  = getenv('APP_ENV') ?: 'production';

$dbStatus = '';
$dbOk = false;

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $version = $pdo->query('SELECT VERSION() AS v')->fetch()['v'];
    $dbStatus = "Подключение успешно. MySQL {$version}";
    $dbOk = true;
} catch (PDOException $e) {
    $dbStatus = $env === 'development'
        ? 'Ошибка подключения: ' . $e->getMessage()
        : 'Ошибка подключения к базе данных';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Electronic Queue — проверка инфраструктуры</title>
</head>
<body>
    <h1>Electronic Queue</h1>
    <p>PHP: <?= htmlspecialchars(PHP_VERSION) ?></p>
    <p>PDO: <?= extension_loaded('pdo') ? 'OK' : 'нет' ?>,
       PDO_MYSQL: <?= extension_loaded('pdo_mysql') ? 'OK' : 'нет' ?>,
       mysqli: <?= extension_loaded('mysqli') ? 'OK' : 'нет' ?></p>
    <p>MySQL: <strong style="color: <?= $dbOk ? 'green' : 'red' ?>">
        <?= htmlspecialchars($dbStatus) ?></strong></p>
</body>
</html>