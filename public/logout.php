<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

unset($_SESSION['operator']);
session_regenerate_id(true);
header('Location: /');
exit;
