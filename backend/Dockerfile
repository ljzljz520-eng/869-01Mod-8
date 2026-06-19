FROM php:8.2-fpm-alpine

# 安装 SQLite 扩展
RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite

# 创建数据目录并设置权限
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

WORKDIR /var/www/html
