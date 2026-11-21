FROM composer:2 AS composer

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

# Create entrypoint script for migrations and seeding
RUN cat > /docker-entrypoint.sh << 'EOF'
#!/bin/bash
set -e

# Function to run migrations and seeding in background
run_db_setup() {
    DB_SETUP_LOG="/var/www/html/backend/storage/logs/db-setup.log"
    
    echo "$(date): Starting database setup process..." >> "$DB_SETUP_LOG"
    
    # Wait for database to be available
    max_attempts=30
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php backend/artisan db:monitor > /dev/null 2>&1; then
            echo "$(date): Database connection successful" >> "$DB_SETUP_LOG"
            break
        fi
        attempt=$((attempt + 1))
        echo "$(date): Waiting for database connection... (attempt $attempt/$max_attempts)" >> "$DB_SETUP_LOG"
        sleep 2
    done
    
    if [ $attempt -eq $max_attempts ]; then
        echo "$(date): WARNING: Could not connect to database after $max_attempts attempts" >> "$DB_SETUP_LOG"
        return 1
    fi
    
    # Run migrations
    echo "$(date): Running migrations..." >> "$DB_SETUP_LOG"
    if php backend/artisan migrate --force >> "$DB_SETUP_LOG" 2>&1; then
        echo "$(date): Migrations completed successfully" >> "$DB_SETUP_LOG"
    else
        echo "$(date): WARNING: Migrations failed" >> "$DB_SETUP_LOG"
    fi
    
    # Run seeders based on environment
    if [ "$APP_ENV" = "local" ] || [ "$APP_ENV" = "testing" ]; then
        echo "$(date): Running development seeder..." >> "$DB_SETUP_LOG"
        if php backend/artisan db:seed --class=DevelopmentSeeder --force >> "$DB_SETUP_LOG" 2>&1; then
            echo "$(date): Development seeder completed successfully" >> "$DB_SETUP_LOG"
        else
            echo "$(date): Running fallback browser test seeder..." >> "$DB_SETUP_LOG"
            php backend/artisan db:seed --class=BrowserTestSeeder --force >> "$DB_SETUP_LOG" 2>&1 || true
        fi
    else
        echo "$(date): Running database seeder..." >> "$DB_SETUP_LOG"
        php backend/artisan db:seed --class=DatabaseSeeder --force >> "$DB_SETUP_LOG" 2>&1 || true
    fi
    
    echo "$(date): Database setup process completed" >> "$DB_SETUP_LOG"
}

# Run database setup in background (non-blocking)
run_db_setup &
DB_SETUP_PID=$!
echo "Database setup started with PID $DB_SETUP_PID (logging to backend/storage/logs/db-setup.log)"

# Start Apache in foreground
exec apache2-foreground
EOF

RUN chmod +x /docker-entrypoint.sh && chown www-data:www-data /docker-entrypoint.sh

ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["/docker-entrypoint.sh"]
