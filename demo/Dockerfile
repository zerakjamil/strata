FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

WORKDIR /app

COPY . /app

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "demo/strata-server.php"]
