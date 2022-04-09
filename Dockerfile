FROM php:7.4-cli-alpine

RUN apk add git
RUN docker-php-ext-install bcmath
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php -r "if (hash_file('sha384', 'composer-setup.php') === '906a84df04cea2aa72f40b5f787e49f22d4c2f19492ac310e8cba5b96ac8b64115ac402c8cd292b8a03482574915d1a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
RUN php composer-setup.php
RUN php -r "unlink('composer-setup.php');"

COPY . .

RUN rm -f /data/configurations/*

RUN php composer.phar install --no-dev

EXPOSE 8080

ENTRYPOINT [ "php", "-S", "0.0.0.0:8080", "/app/index.php" ]  
