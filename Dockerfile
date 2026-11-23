FROM composer:2 AS composer

FROM node:20 AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npx playwright install --with-deps chromium

FROM php:8.3-apache-bookworm AS test-stage
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev sqlite3 \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring xml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./backend/
RUN composer install --working-dir=backend --no-interaction --prefer-dist
COPY backend/ ./backend/
COPY tests/ ./tests/

RUN cd backend && php vendor/bin/phpunit --configuration phpunit.xml

FROM php:8.3-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring xml \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./backend/
RUN composer install --working-dir=backend --no-dev --no-interaction --no-scripts --prefer-dist
COPY --from=test-stage /var/www/html/backend/vendor ./backend/vendor
COPY backend/ ./backend/
COPY frontend/ ./frontend/
RUN mkdir -p backend/bootstrap/cache backend/storage/app/private backend/storage/app/public \
    backend/storage/framework/cache/data backend/storage/framework/sessions \
    backend/storage/framework/testing backend/storage/framework/views backend/storage/logs \
    && composer dump-autoload --working-dir=backend --no-dev --optimize \
    && chown -R www-data:www-data backend/storage backend/bootstrap/cache \
    && sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/backend/public|' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/backend/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/asmrandle.conf \
    && a2enconf asmrandle

COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["/docker-entrypoint.sh"]
