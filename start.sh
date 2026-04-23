#!/bin/bash
set -e

echo "=== LLM Gateway — Start ==="

# 1. Остановить контейнеры, если запущены
if docker compose ps --quiet 2>/dev/null | grep -q .; then
    echo "[docker] Stopping running containers..."
    docker compose down
fi

# 2. Скопировать .env из .env.example, если .env не существует (первый запуск)
if [ ! -f .env ]; then
    echo "[init] Creating .env from .env.example..."
    cp .env.example .env
    FIRST_RUN=true
else
    FIRST_RUN=false
fi

# 3. Собрать и поднять контейнеры
echo "[docker] Building and starting containers..."
docker compose up -d --build

# 4. Дождаться готовности MySQL
echo "[docker] Waiting for MySQL to be ready..."
until docker compose exec -T llm_mysql mysqladmin ping -h localhost -u root --password="${MYSQL_ROOT_PASSWORD:-root_secret}" --silent 2>/dev/null; do
    sleep 2
done
echo "[docker] MySQL is ready."

# 5. Установить/обновить зависимости
echo "[composer] Installing dependencies..."
docker compose exec -T llm_gateway composer install --no-interaction --prefer-dist --optimize-autoloader

# 6. Первый запуск: права, ключ
if [ "$FIRST_RUN" = true ]; then
    echo "[init] Setting storage permissions..."
    docker compose exec -T llm_gateway chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

    echo "[init] Generating application key..."
    docker compose exec -T llm_gateway php artisan key:generate
fi

# 7. Применить миграции
echo "[database] Running migrations..."
docker compose exec -T llm_gateway php artisan migrate --force

# 8. Очистить кеши конфигурации
docker compose exec -T llm_gateway php artisan config:clear
docker compose exec -T llm_gateway php artisan route:clear

echo "=== LLM Gateway started successfully ==="
echo "    API: http://localhost:8080/api/v1/llm/request"
echo "    Health: http://localhost:8080/internal/health"
