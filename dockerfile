FROM php:8.0.13-cli-alpine 

RUN apk add composer git php8-bcmath php8-xml php8-xmlwriter php8-tokenizer php8-dom
RUN docker-php-ext-install bcmath

COPY . .

RUN rm -f /data/configurations/*

RUN composer install

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]  
