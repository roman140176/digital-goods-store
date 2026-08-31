FROM php:8.3-fpm-alpine

RUN apk add --no-cache libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
 && docker-php-ext-install pdo_pgsql pcntl \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Контейнер запускается под uid хозяина проекта (см. compose), поэтому
# php-fpm не должен пытаться переключить пользователя: иначе он не сможет
# писать в примонтированный storage/.
RUN sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www
