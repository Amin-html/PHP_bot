FROM php:8.2-apache

# Корень сайта: Apache отдаёт только содержимое public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Системные утилиты (git и unzip нужны Composer)
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP-расширения. Базовое расширение PDO уже встроено в официальный образ php,
# здесь добавляются драйвер pdo_mysql и mysqli.
RUN docker-php-ext-install pdo_mysql mysqli

# Модули Apache: rewrite (ЧПУ, .htaccess), headers (заголовки безопасности)
RUN a2enmod rewrite headers

# Конфигурация виртуального хоста
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# php.ini для разработки (для production замените на php.ini-production)
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Composer (понадобится для библиотеки Telegram-бота)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Код проекта. В разработке его перекрывает bind mount из docker-compose.yml
COPY --chown=www-data:www-data . /var/www/html

EXPOSE 80