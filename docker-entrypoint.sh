#!/bin/bash
set -e

run_db_setup() {
    DB_SETUP_LOG="/var/www/html/backend/storage/logs/db-setup.log"
    
    echo "$(date): Starting database setup process..." >> "$DB_SETUP_LOG"
    
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
    
    echo "$(date): Running migrations..." >> "$DB_SETUP_LOG"
    if php backend/artisan migrate --force >> "$DB_SETUP_LOG" 2>&1; then
        echo "$(date): Migrations completed successfully" >> "$DB_SETUP_LOG"
    else
        echo "$(date): WARNING: Migrations failed" >> "$DB_SETUP_LOG"
    fi
    
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

run_db_setup &
DB_SETUP_PID=$!
echo "Database setup started with PID $DB_SETUP_PID (logging to backend/storage/logs/db-setup.log)"

exec apache2-foreground
