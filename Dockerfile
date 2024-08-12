FROM php:8.1-alpine3.14

RUN apk add --no-cache --update --virtual buildDeps  autoconf gcc make g++ zlib-dev \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del buildDeps

RUN echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini
RUN echo "apc.enable=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini
    
RUN apk add composer git
    
COPY . .

RUN rm -f /data/configurations/*

RUN composer install --no-dev
RUN composer clearcache

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]  
