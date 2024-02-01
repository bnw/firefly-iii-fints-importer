FROM php:8.1-alpine3.14

COPY . .

RUN rm -f /configurations/*

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN composer install --no-dev \
    && composer clearcache

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]
