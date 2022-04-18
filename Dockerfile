FROM php:8.1-alpine3.14

RUN apk add composer git

COPY . .

RUN rm -f /data/configurations/*

RUN composer install --no-dev

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]  
