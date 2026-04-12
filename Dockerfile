FROM php:8.4-cli-alpine AS builder

RUN apk add --no-cache postgresql-dev \
 && docker-php-ext-install pdo_mysql pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader \
 && rm /usr/bin/composer

FROM php:8.4-cli-alpine

RUN apk add --no-cache libpq \
 && rm -rf /var/cache/apk/*

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /app/vendor /app/vendor

WORKDIR /app
COPY . .

RUN mkdir -p /app/cache/latte && chmod 777 /app/cache/latte

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
