FROM php:8.3-fpm-alpine

# pgsql идёт рядом с pdo_pgsql, а не вместо него: PDO не даёт интерфейса к
# асинхронным сообщениям соединения, то есть подписаться на LISTEN/NOTIFY
# через него физически нечем. SSE-стример (stream:serve) держит на ext-pgsql
# ровно одно соединение — для подписки, — а все запросы к данным идут обычным
# путём через PDO и конфиг Laravel.
RUN apk add --no-cache libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
 && docker-php-ext-install pdo_pgsql pgsql pcntl \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Контейнер запускается под uid хозяина проекта (см. compose), поэтому
# php-fpm не должен пытаться переключить пользователя: иначе он не сможет
# писать в примонтированный storage/.
RUN sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www
