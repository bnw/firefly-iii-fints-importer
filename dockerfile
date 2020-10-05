FROM php:7.3-cli-alpine

RUN apk add composer git
RUN docker-php-ext-install bcmath

COPY . .

RUN rm -f /data/configurations/*

RUN composer install

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]  
