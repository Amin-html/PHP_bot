# ElectronicQueueBot

Система электронной очереди (курсовой проект): PHP 8.2 + Apache + MySQL 8 + phpMyAdmin в Docker.

## Требования

- Windows 10/11 с Docker Desktop (режим WSL 2), Docker Compose V2 (`docker compose`)
- Git
- VS Code (рекомендуется)
- Свободные порты: `8080`, `8081`, `3307`

Проверка:

```powershell
docker --version
docker compose version
```

## Структура

| Путь | Назначение |
|---|---|
| `Dockerfile` | Образ PHP 8.2 + Apache, расширения PDO_MYSQL, mysqli |
| `docker-compose.yml` | Сервисы app, db, phpmyadmin |
| `docker/apache-vhost.conf` | Конфигурация Apache (DocumentRoot = `public/`) |
| `public/` | Публичная директория (единственная, доступная по HTTP) |
| `storage/logs/` | Логи приложения (не коммитятся) |

## Настройка .env

```powershell
Copy-Item .env.example .env
```

Откройте `.env` и замените значения `change_me` и `change_root_password`.
`DB_NAME`, `DB_USER`, `DB_PASS` должны совпадать с `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`.
Не используйте в паролях символы `$ " ' #`.
Файл `.env` нельзя коммитить в Git. `BOT_TOKEN` вставляется только локально.

## Панель оператора

В `.env` задайте `OPERATOR_PASSWORD` — тогда `/operator.php` доступен только после входа на `/login.php`.
Если переменная пустая, вход без пароля (только для разработки). После изменения `.env`: `docker compose up -d`.

`TG_PROXY` (необязательно) — прокси для запросов к Telegram, если `api.telegram.org` недоступен.

## Запуск

```powershell
docker compose config
docker compose up -d --build
```

Первый запуск MySQL занимает 30-60 секунд (инициализация).

## Проверка

```powershell
docker compose ps
docker compose exec app php -v
docker compose exec app php -m
```

В `php -m` должны быть `PDO`, `pdo_mysql`, `mysqli`.

| Сервис | Адрес |
|---|---|
| Приложение | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |

На http://localhost:8080 должно быть написано «Подключение успешно».
В phpMyAdmin войдите как `MYSQL_USER` / `MYSQL_PASSWORD` (или `root` / `MYSQL_ROOT_PASSWORD`).

## Подключение к MySQL

Из контейнера:

```powershell
docker compose exec db mysql -u queue_user -p electronic_queue
```

С компьютера (DBeaver, MySQL Workbench и т.п.):

- Host: `127.0.0.1` (не `localhost`)
- Port: `3307`
- User / Password / Database: из `.env`

Внутри Docker-сети приложение подключается к `db:3306`.

## Остановка и перезапуск

```powershell
docker compose stop                 # остановить, контейнеры сохраняются
docker compose start                # запустить снова
docker compose restart app          # перезапустить один сервис
docker compose down                 # удалить контейнеры, данные БД СОХРАНЯЮТСЯ
docker compose down -v              # удалить контейнеры И данные БД (необратимо!)
docker compose up -d --build        # пересобрать образ после изменения Dockerfile
```

### Проверка сохранности данных

```powershell
docker compose exec db mysql -u root -p -e "CREATE TABLE electronic_queue.t (id INT); SHOW TABLES FROM electronic_queue;"
docker compose down
docker compose up -d
docker compose exec db mysql -u root -p -e "SHOW TABLES FROM electronic_queue;"
```

Таблица `t` должна остаться. Удалите её: `DROP TABLE electronic_queue.t;`

## Логи

```powershell
docker compose logs -f
docker compose logs -f app
docker compose logs -f db
docker compose logs --tail 100 app
```

## Устранение ошибок

**`env file .env not found`** — не создан `.env`: `Copy-Item .env.example .env`.

**`port is already allocated` / `ports are not available`** — порт занят. Освободите его или измените левую часть в `ports:` (например `"8082:80"`). Найти процесс: `netstat -ano | findstr :8080`.

**`Access denied for user` после смены пароля в .env** — MySQL создаёт пользователей только при первой инициализации тома. Если данные не нужны:
`docker compose down -v` и затем `docker compose up -d`.

**Контейнер `db` в состоянии `unhealthy`** — смотрите `docker compose logs db`. Частые причины: неверный `MYSQL_ROOT_PASSWORD`, недостаточно памяти у Docker Desktop, повреждённый том (`down -v`).

**`app` не стартует, пока `db` не healthy** — это нормально, подождите 30-60 секунд.

**403 Forbidden / 404 на http://localhost:8080** — нет файла `public/index.php`.

**`SQLSTATE[HY000] [2002] Connection refused` в PHP** — в `.env` должно быть `DB_HOST=db` и `DB_PORT=3306` (не `127.0.0.1` и не `3307`). Изменив `.env`, выполните `docker compose up -d`.

**С компьютера не подключается к MySQL** — используйте `127.0.0.1:3307`.

**Docker Desktop не запущен** (`error during connect`) — запустите Docker Desktop и дождитесь статуса «Running».

**Изменения в Dockerfile не применяются** — `docker compose up -d --build`.

**Изменения в apache-vhost.conf не применяются** — конфиг копируется при сборке: `docker compose up -d --build`.

**Ошибка `Illegal instruction` / проблемы WSL** — `wsl --update`, затем перезапуск Docker Desktop.