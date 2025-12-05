FROM php:8.3-cli

# ставим драйвер для MySQL
RUN docker-php-ext-install pdo_mysql

WORKDIR /app
