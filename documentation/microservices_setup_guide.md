# LLM Gateway v4 -- Руководство по развертыванию

Руководство по развертыванию Claude-шлюза (LLM Gateway v4) с нуля. Целевая аудитория: DevOps/SRE.

---

## Содержание

1. [Требования](#1-требования)
2. [Архитектура контейнеров](#2-архитектура-контейнеров)
3. [ENV переменные](#3-env-переменные)
4. [Docker Compose setup](#4-docker-compose-setup)
5. [PHP/nginx limits для 1M context window](#5-phpnginx-limits-для-1m-context-window)
6. [Streaming pool sizing](#6-streaming-pool-sizing)
7. [Миграции](#7-миграции)
8. [Создание первого клиента](#8-создание-первого-клиента)
9. [Healthcheck setup](#9-healthcheck-setup)
10. [Scheduled tasks](#10-scheduled-tasks)
11. [Logging](#11-logging)
12. [Рекомендации по prod](#12-рекомендации-по-prod)

---

## 1. Требования

### ОС

- Linux: Ubuntu 22.04+, Debian 12+
- Ядро 5.15+

### Софт

| Компонент | Минимальная версия |
|---|---|
| Docker Engine | 24.0 |
| Docker Compose | v2.20 (плагин `docker compose`) |
| Git | 2.30 |

### Железо (минимум)

| Ресурс | Значение |
|---|---|
| CPU | 4 ядра |
| RAM | 8 GB |
| Диск | 50 GB SSD |

Для production с высокой нагрузкой на streaming (50+ одновременных потоков): 16 GB RAM, 8 CPU. См. раздел 6 для формулы расчета.

### Сетевые требования

- Исходящий HTTPS (443) к `api.anthropic.com`
- Исходящий HTTPS к callback URL клиентов (webhook-доставка)
- Входящий порт для HTTP-трафика (по умолчанию 8080, за TLS-терминатором)

---

## 2. Архитектура контейнеров

Шлюз состоит из 7 контейнеров:

```
                    +------------------+
                    |   Load Balancer  |
                    | (TLS termination)|
                    +--------+---------+
                             |
                             v
                    +------------------+
                    |      nginx       |
                    |    (port 80)     |
                    +--------+---------+
                             |
                +------------+------------+
                |                         |
                v                         v
    +---------------------+   +------------------------+
    | php-fpm-default     |   | php-fpm-streaming      |
    | (port 9000)         |   | (port 9001)            |
    | Обычные запросы     |   | SSE/streaming запросы  |
    +---------------------+   +------------------------+
                |                         |
       +-------+-------+        +--------+--------+
       |               |        |                 |
       v               v        v                 v
  +---------+    +-----------+
  | MySQL   |    |   Redis   |
  | 8.4     |    |   7       |
  +---------+    +-----------+
                       ^
                       |
              +--------+--------+
              |                 |
    +------------------+  +------------------+
    | queue-worker (xN)|  |    scheduler     |
    | high,default,    |  | schedule:work    |
    | low,batch        |  |                  |
    +------------------+  +------------------+
```

### Роли контейнеров

| Контейнер | Образ | Назначение |
|---|---|---|
| nginx | `nginx:alpine` | Reverse proxy, маршрутизация по Accept header |
| php-fpm-default | `php:8.4-fpm` (custom) | Обработка обычных HTTP-запросов (port 9000) |
| php-fpm-streaming | `php:8.4-fpm` (custom) | Обработка streaming/SSE запросов (port 9001) |
| mysql | `mysql:8.4` | Хранение данных, аудит, логи запросов |
| redis | `redis:7-alpine` | Очереди, кеш, rate limiting |
| queue-worker | `php:8.4-fpm` (custom) | Обработка фоновых задач через Laravel Queue |
| scheduler | `php:8.4-fpm` (custom) | Запуск периодических задач (cron) |

### Сети

- `microservices-llm` -- внешняя сеть для связи с другими микросервисами
- `llm_internal` -- внутренняя bridge-сеть для межконтейнерной связи

---

## 3. ENV переменные

Полный список переменных окружения, сгруппированный по разделам. Файл `.env` размещается в корне проекта.

### Application

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `APP_NAME` | нет | `Laravel` | Имя приложения |
| `APP_ENV` | да | `production` | Окружение: `local`, `staging`, `production` |
| `APP_KEY` | да | -- | Ключ шифрования (base64:...). Генерация: `php artisan key:generate` |
| `APP_DEBUG` | да | `false` | Debug-режим. В production строго `false` |
| `APP_URL` | да | `http://localhost` | Базовый URL приложения |

### Database (MySQL)

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `DB_CONNECTION` | нет | `mysql` | Драйвер БД |
| `DB_HOST` | да | `llm_mysql` | Хост MySQL |
| `DB_PORT` | нет | `3306` | Порт MySQL |
| `DB_DATABASE` | да | `llm_gateway` | Имя базы данных |
| `DB_USERNAME` | да | `llm_user` | Пользователь MySQL |
| `DB_PASSWORD` | да | -- | Пароль MySQL |
| `DB_SOCKET` | нет | -- | Unix socket (альтернатива host:port) |
| `DB_CHARSET` | нет | `utf8mb4` | Кодировка |
| `DB_COLLATION` | нет | `utf8mb4_unicode_ci` | Сортировка |
| `MYSQL_ROOT_PASSWORD` | да | -- | Root-пароль MySQL (для контейнера) |
| `MYSQL_PASSWORD` | да | -- | Пароль пользователя MySQL (для контейнера) |
| `MYSQL_ATTR_SSL_CA` | нет | -- | Путь к CA-сертификату для SSL-подключения |

### Redis

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `REDIS_HOST` | да | `llm_redis` | Хост Redis |
| `REDIS_PORT` | нет | `6379` | Порт Redis |
| `REDIS_PASSWORD` | нет | -- | Пароль Redis |
| `REDIS_USERNAME` | нет | -- | Пользователь Redis (ACL, Redis 6+) |
| `REDIS_DB` | нет | `0` | Номер БД для основного подключения |
| `REDIS_CACHE_DB` | нет | `1` | Номер БД для кеша |
| `REDIS_CLIENT` | нет | `phpredis` | Клиент: `phpredis` или `predis` |
| `REDIS_PREFIX` | нет | `llm_gateway-database-` | Префикс ключей |

### Queue

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `QUEUE_CONNECTION` | да | `redis` | Драйвер очередей |

### Cache

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `CACHE_STORE` | нет | `database` | Хранилище кеша. Для production: `redis` |
| `CACHE_PREFIX` | нет | `llm_gateway-cache-` | Префикс ключей кеша |

### Session

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `SESSION_DRIVER` | нет | `database` | Драйвер сессий. Рекомендуется `redis` |

### Anthropic API

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `ANTHROPIC_API_KEY` | да | -- | API-ключ Anthropic для доступа к Claude |
| `CLAUDE_ADMIN_API_KEY` | нет | -- | Admin API-ключ для управления организацией |

### Model Overrides

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `CLAUDE_OPUS_MODEL` | нет | `claude-opus-4-6` | Идентификатор модели для алиаса `claude-opus` |
| `CLAUDE_SONNET_MODEL` | нет | `claude-sonnet-4-6` | Идентификатор модели для алиаса `claude-sonnet` |
| `CLAUDE_HAIKU_MODEL` | нет | `claude-haiku-4-5` | Идентификатор модели для алиаса `claude-haiku` |

### Auth

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `API_KEY_PEPPER` | да | -- | Pepper для хеширования API-ключей. Генерация: `openssl rand -hex 32` |

### Dev Mode

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `DEV_MODE_LATENCY_MS` | нет | `150` | Имитация задержки в dev-режиме (мс) |
| `DEV_MODE_CONTENT` | нет | `This is a dev_mode stub response.` | Содержимое stub-ответа в dev-режиме |

### Logging

| Переменная | Обязательна | По умолчанию | Описание |
|---|---|---|---|
| `LOG_CHANNEL` | нет | `stack` | Канал логирования по умолчанию |
| `LOG_LEVEL` | нет | `debug` | Уровень логирования |
| `LLM_LOG_LEVEL` | нет | `error` | Уровень логирования LLM-канала |
| `LOG_STACK` | нет | `single` | Каналы в стеке (через запятую) |
| `LOG_SLACK_WEBHOOK_URL` | нет | -- | Webhook URL для алертов в Slack |

### Итого переменных: 38

Минимальный `.env` для production:

```bash
APP_NAME=LLM_Gateway
APP_ENV=production
APP_KEY=base64:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
APP_DEBUG=false
APP_URL=https://llm-gateway.example.com

DB_CONNECTION=mysql
DB_HOST=llm_mysql
DB_PORT=3306
DB_DATABASE=llm_gateway
DB_USERNAME=llm_user
DB_PASSWORD=<strong-password>

REDIS_HOST=llm_redis
REDIS_PORT=6379
REDIS_PASSWORD=<redis-password>

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

MYSQL_ROOT_PASSWORD=<root-password>
MYSQL_PASSWORD=<strong-password>

ANTHROPIC_API_KEY=sk-ant-XXXXXXXXXXXX
API_KEY_PEPPER=<openssl rand -hex 32>

LOG_CHANNEL=stack
LOG_STACK=daily
LLM_LOG_LEVEL=error
```

---

## 4. Docker Compose setup

### docker-compose.yml

```yaml
services:
  php-fpm-default:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: llm_gateway_default
    volumes:
      - .:/var/www/html
      - ./docker/php/php-default.ini:/usr/local/etc/php/php.ini
      - ./docker/php-fpm/pool.d/www.conf:/usr/local/etc/php-fpm.d/www.conf
    networks:
      - microservices-llm
      - llm_internal
    depends_on:
      - llm_mysql
      - llm_redis
    env_file:
      - .env
    restart: unless-stopped

  php-fpm-streaming:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: llm_gateway_streaming
    volumes:
      - .:/var/www/html
      - ./docker/php/php-streaming.ini:/usr/local/etc/php/php.ini
      - ./docker/php-fpm/pool.d/streaming.conf:/usr/local/etc/php-fpm.d/www.conf
    networks:
      - microservices-llm
      - llm_internal
    depends_on:
      - llm_mysql
      - llm_redis
    env_file:
      - .env
    restart: unless-stopped

  llm_nginx:
    image: nginx:alpine
    container_name: llm_nginx
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    networks:
      - microservices-llm
      - llm_internal
    depends_on:
      - php-fpm-default
      - php-fpm-streaming
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/internal/health"]
      interval: 30s
      timeout: 10s
      retries: 3
    restart: unless-stopped

  llm_mysql:
    image: mysql:8.4
    container_name: llm_mysql
    ports:
      - "3307:3306"
    volumes:
      - llm_mysql_data:/var/lib/mysql
    networks:
      - llm_internal
    environment:
      MYSQL_DATABASE: llm_gateway
      MYSQL_USER: llm_user
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    restart: unless-stopped

  llm_redis:
    image: redis:7-alpine
    container_name: llm_redis
    command: redis-server --requirepass ${REDIS_PASSWORD:-}
    ports:
      - "6381:6379"
    volumes:
      - llm_redis_data:/data
    networks:
      - llm_internal
    restart: unless-stopped

  llm_queue_worker:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: llm_queue_worker
    command: php artisan queue:work redis --queue=high,default,low,batch --sleep=3 --tries=1 --max-time=3600 --max-jobs=1000 --memory=256
    volumes:
      - .:/var/www/html
      - ./docker/php/php-default.ini:/usr/local/etc/php/php.ini
    networks:
      - microservices-llm
      - llm_internal
    depends_on:
      - llm_mysql
      - llm_redis
      - php-fpm-default
    env_file:
      - .env
    restart: unless-stopped

  llm_scheduler:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: llm_scheduler
    command: php artisan schedule:work
    volumes:
      - .:/var/www/html
      - ./docker/php/php-default.ini:/usr/local/etc/php/php.ini
    networks:
      - llm_internal
    depends_on:
      - llm_mysql
      - llm_redis
      - php-fpm-default
    env_file:
      - .env
    restart: unless-stopped

networks:
  microservices-llm:
    external: true
  llm_internal:
    driver: bridge

volumes:
  llm_mysql_data:
  llm_redis_data:
```

### nginx конфигурация (docker/nginx/default.conf)

Маршрутизация между двумя php-fpm пулами на основе Accept header и location:

```nginx
map $http_accept $fpm_backend {
    default                    php-fpm-default:9000;
    ~text/event-stream         php-fpm-streaming:9001;
}

server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    # Обычные запросы: до 64 MB
    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Messages endpoint -- маршрутизация на streaming pool при SSE
    location ~ ^/api/v1/messages$ {
        fastcgi_pass $fpm_backend;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;

        fastcgi_read_timeout 900s;
        fastcgi_send_timeout 900s;
        fastcgi_buffering off;
        fastcgi_request_buffering off;

        proxy_read_timeout 900s;
    }

    # Files upload -- увеличенный лимит тела
    location ~ ^/api/v1/files$ {
        fastcgi_pass php-fpm-default:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;

        client_max_body_size 128M;
        fastcgi_read_timeout 300s;
    }

    # Все остальные PHP-запросы
    location ~ \.php$ {
        fastcgi_pass php-fpm-default:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Создание внешней сети

Перед первым запуском:

```bash
docker network create microservices-llm
```

### Запуск

```bash
docker compose up -d --build
```

---

## 5. PHP/nginx limits для 1M context window

Модели Claude Opus и Sonnet поддерживают context window 1 000 000 токенов. Один запрос на полный контекст может достигать десятков мегабайт и выполняться несколько минут. Ниже -- конкретные значения лимитов.

### nginx

| Параметр | Значение (messages) | Значение (files) | Назначение |
|---|---|---|---|
| `client_max_body_size` | `64M` | `128M` | Максимальный размер тела запроса |
| `proxy_read_timeout` | `900s` | `300s` | Таймаут ожидания ответа от upstream |
| `fastcgi_read_timeout` | `900s` | `300s` | Таймаут чтения ответа от php-fpm |
| `fastcgi_send_timeout` | `900s` | -- | Таймаут отправки запроса в php-fpm |
| `fastcgi_buffering` | `off` | -- | Отключено для streaming (SSE) |
| `fastcgi_request_buffering` | `off` | -- | Отключено для streaming |

### php-fpm default pool (php-default.ini)

```ini
memory_limit = 512M
post_max_size = 64M
upload_max_filesize = 64M
max_execution_time = 600
```

### php-fpm default pool (www.conf)

```ini
[www]
listen = 9000
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 1000
request_terminate_timeout = 600s
```

### php-fpm streaming pool (php-streaming.ini)

```ini
memory_limit = 512M
max_execution_time = 900
output_buffering = Off
zlib.output_compression = Off
```

### php-fpm streaming pool (streaming.conf)

```ini
[streaming]
listen = 9001
pm = dynamic
pm.max_children = 120
pm.start_servers = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 30
pm.max_requests = 500
request_terminate_timeout = 1800s
```

### Files API

Для загрузки файлов через Files API:

```ini
post_max_size = 128M
upload_max_filesize = 128M
```

Запросы объемом более 64 MB должны использовать Files API (`POST /api/v1/files`) вместо прямой передачи контента в messages. Файл загружается отдельно, а в запросе передается `file_id`.

### Сводная таблица лимитов

| Пул | memory_limit | max_execution_time | request_terminate_timeout | post_max_size |
|---|---|---|---|---|
| default | 512M | 600s | 600s | 64M |
| streaming | 512M | 900s | 1800s | 64M |
| files upload | 512M | 600s | 600s | 128M |

---

## 6. Streaming pool sizing

### Формула расчета

Streaming-запросы к Claude (SSE) удерживают php-fpm worker на всю длительность генерации. Для моделей с большим контекстом это может быть 1-10 минут на запрос.

```
max_concurrent_streams = pm.max_children (streaming pool)
required_children = peak_concurrent_streams * 1.3
memory_per_stream ~ 80 MB
required_ram = pool_size * 80 MB
```

### Пример расчета

| Параметр | Значение |
|---|---|
| Пиковая нагрузка | 50 одновременных streaming-запросов |
| Коэффициент запаса | 1.3 |
| Требуемый `pm.max_children` | 50 * 1.3 = 65 |
| Память на один worker | ~80 MB |
| Требуемая RAM для streaming pool | 65 * 80 MB = 5.2 GB |

Формула для `pm.max_children`:

```
pm.max_children = ceil(peak_concurrent * 1.3)
```

Формула для RAM:

```
streaming_pool_ram_gb = pm.max_children * 80 / 1024
```

### Конфигурация pm.start_servers

Рекомендуемые соотношения:

```
pm.start_servers     = ceil(pm.max_children * 0.15)
pm.min_spare_servers = ceil(pm.max_children * 0.08)
pm.max_spare_servers = ceil(pm.max_children * 0.25)
```

### Мониторинг listen_queue

Ключевая метрика для определения нехватки workers в streaming pool -- `listen queue` из php-fpm status page.

#### Включение status page

В `streaming.conf` добавить:

```ini
pm.status_path = /fpm-status-streaming
```

В nginx:

```nginx
location = /fpm-status-streaming {
    allow 127.0.0.1;
    allow 10.0.0.0/8;
    allow 172.16.0.0/12;
    deny all;
    fastcgi_pass php-fpm-streaming:9001;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

#### Чтение метрики

```bash
curl -s http://localhost:8080/fpm-status-streaming?json | jq '.["listen queue"]'
```

Ответ содержит:

- `listen queue` -- количество запросов в очереди ожидания свободного worker
- `active processes` -- количество занятых workers
- `idle processes` -- количество свободных workers
- `max children reached` -- сколько раз достигнут лимит `pm.max_children`

#### Пороги алертов

| Метрика | Порог | Условие | Действие |
|---|---|---|---|
| `listen queue` | > 5 | Устойчиво 2+ минуты | Увеличить `pm.max_children` |
| `listen queue` | > 20 | Любой момент | Критический алерт, немедленное масштабирование |
| `max children reached` | Растет | За последний час | Пул переполнен, нужно увеличение |
| `idle processes` | > 50% от max_children | Устойчиво 30+ минут | Можно уменьшить пул |

#### Пример Prometheus-правила

```yaml
groups:
  - name: php-fpm-streaming
    rules:
      - alert: StreamingPoolQueueHigh
        expr: phpfpm_listen_queue{pool="streaming"} > 5
        for: 2m
        labels:
          severity: warning
        annotations:
          summary: "Streaming pool listen_queue > 5 sustained 2 min"
      - alert: StreamingPoolQueueCritical
        expr: phpfpm_listen_queue{pool="streaming"} > 20
        for: 30s
        labels:
          severity: critical
        annotations:
          summary: "Streaming pool listen_queue > 20, immediate scaling required"
```

#### Процедура масштабирования

1. Проверить текущее значение `listen queue`:
   ```bash
   curl -s http://localhost:8080/fpm-status-streaming?json | jq '.'
   ```
2. Рассчитать новый `pm.max_children` по формуле выше
3. Проверить доступную RAM: `free -g`
4. Обновить `streaming.conf`
5. Перезапустить streaming pool: `docker compose restart php-fpm-streaming`
6. Убедиться, что `listen queue` вернулся к 0

Подробнее о процедурах реагирования: см. `operational_runbook.md`, раздел 12.

---

## 7. Миграции

### Первичный запуск миграций

```bash
docker compose exec php-fpm-default php artisan migrate --force
```

Флаг `--force` обязателен для окружений `production` и `staging`.

### Создание тестовой БД

Для запуска тестов требуется отдельная база `llm_gateway_test`:

```bash
docker compose exec php-fpm-default php artisan llm:create-test-database
```

Команда создаст БД `llm_gateway_test` на том же MySQL-сервере.

### Проверка статуса миграций

```bash
docker compose exec php-fpm-default php artisan migrate:status
```

### Legacy drop

Если требуется удаление устаревших таблиц или колонок при обновлении, см. `operational_runbook.md` -- раздел миграций с breaking changes. Все деструктивные миграции требуют подтверждения.

---

## 8. Создание первого клиента

### Создание клиента

```bash
docker compose exec php-fpm-default php artisan client:create "MyService" \
    --model-alias=claude-sonnet \
    --rate-limit=60 \
    --monthly-cap=500.00 \
    --features=thinking,prompt_caching,batch
```

Параметры:

| Параметр | Описание |
|---|---|
| `name` (аргумент, обязательный) | Имя клиента |
| `--model-alias` | Модель по умолчанию: `claude-opus`, `claude-sonnet`, `claude-haiku` |
| `--rate-limit` | Лимит запросов в минуту |
| `--monthly-cap` | Месячный лимит расходов в USD |
| `--features` | Разрешенные функции (через запятую) |

Доступные features: `thinking`, `web_search`, `code_execution`, `computer_use`, `bash`, `text_editor`, `priority_tier`, `citations`, `prompt_caching`, `structured_outputs`, `batch`.

Команда выведет API-ключ и signing secret. Сохраните их -- они показываются только один раз.

```
Client created: id=1 name="MyService"
================================================================
API KEY (save now, will not be shown again):
  llmgw_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
SIGNING SECRET (for webhook verification):
  whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
================================================================
```

### Просмотр клиента

```bash
docker compose exec php-fpm-default php artisan client:show 1
```

Выводит таблицу с workspace, rate limit, dev mode, allowed features, monthly cap, текущие расходы за месяц, количество запросов, среднюю latency и топ используемых моделей.

### Включение/отключение feature

```bash
docker compose exec php-fpm-default php artisan client:enable-feature 1 web_search
docker compose exec php-fpm-default php artisan client:disable-feature 1 web_search
```

### Ротация API-ключа

Мгновенная ротация без grace period. Старый ключ перестает работать сразу:

```bash
docker compose exec php-fpm-default php artisan client:rotate-key 1
```

### Ротация signing secret

Ротация с grace period 24 часа. Предыдущий secret продолжает работать в течение grace period:

```bash
docker compose exec php-fpm-default php artisan client:rotate-secret 1
```

Автоматическая очистка просроченных предыдущих секретов выполняется по расписанию командой `webhook:cleanup-expired-secrets` (каждый час).

---

## 9. Healthcheck setup

### Endpoints

| Endpoint | Метод | Middleware | Назначение |
|---|---|---|---|
| `/internal/health` | GET | `internal.network` | Healthcheck (MySQL, Redis, Anthropic API) |
| `/internal/stats` | GET | `internal.network` | Статистика очередей, расходов, pending requests |

Доступ к `/internal/*` ограничен middleware `internal.network` -- только внутренние сети (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.1).

### Ответ /internal/health

```json
{
  "status": "ok",
  "components": {
    "mysql": {"status": "ok", "latency_ms": 2, "error": null},
    "redis": {"status": "ok", "latency_ms": 1, "error": null}
  },
  "anthropic_last_check_at": "2026-04-12T10:00:00+00:00",
  "anthropic_last_status": "ok"
}
```

Статусы: `ok`, `degraded`, `down`. HTTP 200 для `ok` и `degraded`, HTTP 503 для `down`.

### Ответ /internal/stats

```json
{
  "queues": {"high": 0, "default": 5, "low": 12},
  "async_pending_counts": {"pending": 3, "processing": 1},
  "current_usage": {},
  "top_spenders_month": [
    {"id": 1, "name": "MyService", "current_month_spend_usd": "42.50"}
  ]
}
```

### Kubernetes probes

```yaml
livenessProbe:
  httpGet:
    path: /internal/health
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 30
  timeoutSeconds: 10
  failureThreshold: 3

readinessProbe:
  httpGet:
    path: /internal/health
    port: 80
  initialDelaySeconds: 5
  periodSeconds: 10
  timeoutSeconds: 5
  failureThreshold: 2
```

### Docker Compose healthcheck

Уже включен в конфигурацию nginx-контейнера:

```yaml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost/internal/health"]
  interval: 30s
  timeout: 10s
  retries: 3
```

### Prometheus scraping

Для интеграции с Prometheus рекомендуется настроить scrape target на `/internal/stats` с парсингом JSON. Формат ответа стабилен.

---

## 10. Scheduled tasks

Scheduler запускается в отдельном контейнере командой:

```bash
php artisan schedule:work
```

### Список задач

| Задача | Расписание | Описание |
|---|---|---|
| `RetryFailedWebhooks` | Каждую минуту | Повторная доставка неудавшихся webhook-ов |
| `llm:cleanup-expired` | Каждый час | Очистка expired pending prompts/responses |
| `llm:mark-timed-out` | Каждые 5 минут | Пометка зависших запросов как timed out |
| `ClaudeApiPingScheduled` | Каждую минуту | Проверка доступности Anthropic API |
| `requests:cleanup` | Ежедневно в 03:00 | Очистка старых записей в request_log |
| `webhook:cleanup-expired-secrets` | Каждый час | Удаление просроченных previous signing secrets |
| `claude:sync-capabilities` | Еженедельно (вс, 03:00) | Синхронизация capabilities моделей с API |
| `claude:poll-batches` | Каждую минуту | Проверка статуса batch-запросов |
| `claude:flush-accumulator` | Каждую минуту | Отправка накопленных batch-запросов |
| `claude:cleanup-files` | Еженедельно (вс, 03:00) | Очистка неиспользуемых файлов в Files API |

Все задачи используют `withoutOverlapping()` для предотвращения дублирования. Критические задачи используют `onOneServer()` для кластеризации.

Подробнее о каждой задаче: см. `internal_logic.md`, раздел 16.

---

## 11. Logging

### Каналы логирования

| Канал | Драйвер | Файл | Ротация | Уровень |
|---|---|---|---|---|
| `stack` (default) | stack | -- | -- | Зависит от вложенных каналов |
| `daily` | daily | `storage/logs/laravel.log` | 14 дней | По `LOG_LEVEL` |
| `llm` | daily | `storage/logs/llm.log` | 30 дней | По `LLM_LOG_LEVEL` (default: `error`) |

### Рекомендуемая конфигурация для production

```bash
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LLM_LOG_LEVEL=error
```

### Docker volume для логов

Примонтировать `storage/logs` на хост для доступа без exec:

```yaml
volumes:
  - ./logs:/var/www/html/storage/logs
```

### Примеры поиска по логам

Ошибки Anthropic API за последний час:

```bash
grep "$(date -d '1 hour ago' +%Y-%m-%d)" storage/logs/llm-$(date +%Y-%m-%d).log | grep -i "error"
```

Все ошибки аутентификации:

```bash
grep "authentication" storage/logs/laravel-$(date +%Y-%m-%d).log
```

Ошибки webhook-доставки:

```bash
grep "webhook\|callback" storage/logs/llm-$(date +%Y-%m-%d).log
```

Queue worker логи (supervisor):

```bash
tail -f storage/logs/worker.log
```

### Очистка логов

Автоматическая ротация: `llm` канал хранит файлы 30 дней, `daily` -- 14 дней. Дополнительная очистка не требуется.

---

## 12. Рекомендации по prod

### Queue worker scaling

- Запускайте N queue workers в зависимости от нагрузки. Базовое значение: 2 workers на ядро CPU
- Используйте supervisor для управления процессами (конфиг в `docker/supervisor/queue-worker.conf`)
- Параметр `--max-time=3600` перезапускает worker каждый час для предотвращения утечек памяти
- Параметр `--memory=256` останавливает worker при превышении 256 MB
- Мониторьте размеры очередей через `/internal/stats`
- Очередь `batch` можно обрабатывать отдельным набором workers

Масштабирование workers:

```yaml
services:
  llm_queue_worker:
    deploy:
      replicas: 4
```

### MySQL backup

```bash
docker compose exec llm_mysql mysqldump -u root -p${MYSQL_ROOT_PASSWORD} llm_gateway \
    | gzip > /backups/llm_gateway_$(date +%Y%m%d_%H%M%S).sql.gz
```

Рекомендации:
- Ежедневный полный дамп + binlog replication
- Point-in-time recovery через binlog
- Тестируйте восстановление из бекапа ежемесячно
- Retention: 30 дней для дампов, 7 дней для binlog

### Redis persistence

Включите AOF (Append Only File) для защиты данных очередей:

```yaml
llm_redis:
  image: redis:7-alpine
  command: redis-server --appendonly yes --appendfsync everysec --requirepass ${REDIS_PASSWORD:-}
  volumes:
    - llm_redis_data:/data
```

Рекомендации:
- `appendfsync everysec` -- баланс между производительностью и надежностью (потеря макс. 1 секунды данных)
- Мониторьте `used_memory` через `redis-cli info memory`
- Настройте `maxmemory-policy allkeys-lru` для production

### TLS termination

Шлюз не выполняет TLS-терминацию самостоятельно. Рекомендуемые варианты:

1. Reverse proxy -- nginx/HAProxy/Traefik перед контейнером llm_nginx
2. Cloud Load Balancer -- AWS ALB, GCP HTTPS LB
3. Kubernetes Ingress -- с cert-manager для автоматического обновления сертификатов

Минимальная конфигурация TLS:
- TLS 1.2+ (рекомендуется 1.3)
- HSTS header
- Strong cipher suites

Пример с внешним nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name llm-gateway.example.com;

    ssl_certificate     /etc/ssl/certs/llm-gateway.crt;
    ssl_certificate_key /etc/ssl/private/llm-gateway.key;
    ssl_protocols       TLSv1.2 TLSv1.3;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Streaming support
        proxy_read_timeout 900s;
        proxy_buffering off;
    }
}
```

### Secrets management

- Никогда не коммитьте `.env` в git
- Используйте Docker Secrets, HashiCorp Vault или AWS Secrets Manager
- `API_KEY_PEPPER` -- критический секрет. При его утрате все API-ключи клиентов станут невалидными
- `APP_KEY` -- ключ шифрования Laravel. При его утрате все encrypted данные (signing secrets) станут нечитаемыми
- Ротируйте `ANTHROPIC_API_KEY` при подозрении на компрометацию
- Signing secrets клиентов ротируются через `client:rotate-secret` с grace period 24 часа

### Сетевая безопасность

- Контейнеры MySQL и Redis не должны быть доступны извне. В production уберите проброс портов `3307:3306` и `6381:6379`
- Internal endpoints (`/internal/*`) защищены middleware `internal.network`
- Для межсервисного взаимодействия используйте внутреннюю docker-сеть `llm_internal`

### Мониторинг (чек-лист)

- `/internal/health` -- базовый healthcheck (MySQL, Redis, Anthropic)
- `/internal/stats` -- размеры очередей, pending requests, top spenders
- php-fpm status page -- `listen_queue` streaming pool (см. раздел 6)
- Redis `INFO` -- used_memory, connected_clients, rejected_connections
- MySQL slow query log
- Размер `storage/logs/` на диске
- Webhook delivery success rate (из request_log/response_log)
