# LLM Gateway v4 -- Deployment Guide

End-to-end deployment guide for the Claude gateway (LLM Gateway v4). Audience: DevOps/SRE.

---

## Contents

1. [Requirements](#1-requirements)
2. [Container architecture](#2-container-architecture)
3. [ENV variables](#3-env-variables)
4. [Docker Compose setup](#4-docker-compose-setup)
5. [PHP/nginx limits for the 1M context window](#5-phpnginx-limits-for-the-1m-context-window)
6. [Streaming pool sizing](#6-streaming-pool-sizing)
7. [Migrations](#7-migrations)
8. [Creating the first client](#8-creating-the-first-client)
9. [Healthcheck setup](#9-healthcheck-setup)
10. [Scheduled tasks](#10-scheduled-tasks)
11. [Logging](#11-logging)
12. [Production recommendations](#12-production-recommendations)

---

## 1. Requirements

### OS

- Linux: Ubuntu 22.04+, Debian 12+
- Kernel 5.15+

### Software

| Component | Minimum version |
|---|---|
| Docker Engine | 24.0 |
| Docker Compose | v2.20 (`docker compose` plugin) |
| Git | 2.30 |

### Hardware (minimum)

| Resource | Value |
|---|---|
| CPU | 4 cores |
| RAM | 8 GB |
| Disk | 50 GB SSD |

For production with heavy streaming load (50+ concurrent streams): 16 GB RAM, 8 CPU. See section 6 for the sizing formula.

### Network requirements

- Outbound HTTPS (443) to `api.anthropic.com`
- Outbound HTTPS to client callback URLs (webhook delivery)
- Inbound port for HTTP traffic (default 8080, behind a TLS terminator)

---

## 2. Container architecture

The gateway consists of 6 containers. `llm_gateway` is a single php-fpm container running two pools in parallel: `www` (port 9000) for regular requests and `streaming` (port 9001) for SSE. Routing between pools happens in nginx based on the `Accept` header.

```text
                    +------------------+
                    |   Load Balancer  |
                    | (TLS termination)|
                    +--------+---------+
                             |
                             v
                    +------------------+
                    |    llm_nginx     |
                    |    (port 80)     |
                    +--------+---------+
                             |
                             v
                  +-------------------------+
                  |      llm_gateway        |
                  |  (php-fpm, one container) |
                  |  pool www       : 9000  |
                  |  pool streaming : 9001  |
                  +-----------+-------------+
                              |
                  +-----------+-----------+
                  |                       |
                  v                       v
            +-----------+           +-----------+
            | llm_mysql |           | llm_redis |
            |  8.4      |           |  7-alpine |
            +-----------+           +-----------+
                                         ^
                                         |
                            +------------+------------+
                            |                         |
              +----------------------+    +------------------+
              |  llm_queue_worker    |    |  llm_scheduler   |
              |  queue:work          |    |  schedule:work   |
              |  high,default,low,   |    |                  |
              |  batch               |    |                  |
              +----------------------+    +------------------+
```

### Container roles

| Container | Image | Purpose |
|---|---|---|
| `llm_nginx` | `nginx:alpine` | Reverse proxy, routes between pools by Accept header |
| `llm_gateway` | `php:8.4-fpm` (custom) | Single php-fpm container with two pools: `www` (9000, regular requests) and `streaming` (9001, SSE) |
| `llm_mysql` | `mysql:8.4` | Data storage, audit, request log |
| `llm_redis` | `redis:7-alpine` | Queues, cache, rate limiting |
| `llm_queue_worker` | `php:8.4-fpm` (custom) | Background job processing via Laravel Queue |
| `llm_scheduler` | `php:8.4-fpm` (custom) | Periodic tasks (`schedule:work`) |

### Networks

- `microservices-llm` -- external network for connectivity with other microservices
- `llm_internal` -- internal bridge network for inter-container traffic

---

## 3. ENV variables

Full list of environment variables, grouped by section. The `.env` file lives in the project root.

### Application

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `APP_NAME` | no | `LLM_Gateway` | Application name |
| `APP_ENV` | yes | `production` | Environment: `local`, `staging`, `production` |
| `APP_KEY` | yes | -- | Encryption key (`base64:...`). Generate with `php artisan key:generate` |
| `APP_DEBUG` | yes | `false` | Debug mode. Always `false` in production |
| `APP_URL` | yes | `http://localhost` | Base URL of the application |
| `CORS_ALLOWED_ORIGINS` | no | -- | Comma-separated list of allowed CORS origins |

### Database (MySQL)

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `DB_CONNECTION` | no | `mysql` | Database driver |
| `DB_HOST` | yes | `llm_mysql` | MySQL host |
| `DB_PORT` | no | `3306` | MySQL port |
| `DB_DATABASE` | yes | `llm_gateway` | Database name |
| `DB_USERNAME` | yes | `llm_user` | MySQL user |
| `DB_PASSWORD` | yes | -- | MySQL password |
| `MYSQL_ROOT_PASSWORD` | yes | -- | MySQL root password (container init) |
| `MYSQL_PASSWORD` | yes | -- | MySQL user password (container init) |

### Redis

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `REDIS_HOST` | yes | `llm_redis` | Redis host |
| `REDIS_PORT` | no | `6379` | Redis port |

### Queue

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `QUEUE_CONNECTION` | yes | `redis` | Queue driver |

### Cache

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `CACHE_STORE` | no | `redis` | Cache backend. `redis` for production |

### Session

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `SESSION_DRIVER` | no | `redis` | Session driver. `redis` recommended |

### Anthropic API

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `ANTHROPIC_API_KEY` | yes | -- | Anthropic API key for Claude access |
| `CLAUDE_ADMIN_API_KEY` | no | -- | Admin API key for organisation management |
| `CLAUDE_HTTP_RETRY_MAX_ATTEMPTS` | no | `3` | Maximum retry attempts for Anthropic HTTP requests |
| `CLAUDE_HTTP_RETRY_BASE_DELAY_MS` | no | `500` | Base backoff delay (ms) between Anthropic HTTP retries |

### Model overrides

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `CLAUDE_OPUS_MODEL` | no | `claude-opus-4-6` | Model identifier for the `claude-opus` alias |
| `CLAUDE_SONNET_MODEL` | no | `claude-sonnet-4-6` | Model identifier for the `claude-sonnet` alias |
| `CLAUDE_HAIKU_MODEL` | no | `claude-haiku-4-5` | Model identifier for the `claude-haiku` alias |

### Auth

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `API_KEY_PEPPER` | yes | -- | Pepper for hashing API keys. Generate with `openssl rand -hex 32` |

### Gateway-side configuration

| Variable | Default | Purpose |
|---|---|---|
| `GATEWAY_DEFAULT_RATE_LIMIT_PER_MINUTE` | `600` | Default per-client request budget per minute, used when `clients.rate_limit_rpm` is `NULL`. The rate limiter is registered in `App\Providers\AppServiceProvider::boot()` as `RateLimiter::for('api-client', ...)` and applied via the `throttle:api-client` route middleware. |

### Dev mode

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `DEV_MODE_LATENCY_MS` | no | `150` | Simulated latency in dev mode (ms) |
| `DEV_MODE_CONTENT` | no | `This is a dev_mode stub response.` | Stub response payload in dev mode |

Minimal `.env` for production:

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

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

MYSQL_ROOT_PASSWORD=<root-password>
MYSQL_PASSWORD=<strong-password>

ANTHROPIC_API_KEY=sk-ant-XXXXXXXXXXXX
API_KEY_PEPPER=<openssl rand -hex 32>

GATEWAY_DEFAULT_RATE_LIMIT_PER_MINUTE=600
```

---

## 4. Docker Compose setup

### docker-compose.yml

```yaml
services:
  llm_gateway:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: llm_gateway
    volumes:
      - .:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/php.ini
      - ./docker/php-fpm/pool.d/www.conf:/usr/local/etc/php-fpm.d/www.conf
      - ./docker/php-fpm/pool.d/streaming.conf:/usr/local/etc/php-fpm.d/streaming.conf
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
      - llm_gateway
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
    ports:
      - "6381:6379"
    networks:
      - llm_internal
    restart: unless-stopped

  llm_queue_worker:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: llm_queue_worker
    command: php artisan queue:work redis --queue=high,default,low,batch --sleep=3 --tries=3 --max-time=3600 --max-jobs=1000 --memory=256
    volumes:
      - .:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/php.ini
    networks:
      - microservices-llm
      - llm_internal
    depends_on:
      - llm_mysql
      - llm_redis
      - llm_gateway
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
      - ./docker/php/php.ini:/usr/local/etc/php/php.ini
    networks:
      - llm_internal
    depends_on:
      - llm_mysql
      - llm_redis
      - llm_gateway
    env_file:
      - .env
    restart: unless-stopped

networks:
  microservices-llm:
    external: true
  llm_internal:
    driver: bridge
    ipam:
      config:
        - subnet: "10.10.1.0/24"

volumes:
  llm_mysql_data:
```

### Host port mappings

| Host | Container | Service |
|---|---|---|
| 8080 | 80 | `llm_nginx` (HTTP) |
| 3307 | 3306 | `llm_mysql` |
| 6381 | 6379 | `llm_redis` |

### nginx configuration (docker/nginx/default.conf)

Routing between pools `www` (9000) and `streaming` (9001) of the same `llm_gateway` container, based on the `Accept` header:

```nginx
map $http_accept $fpm_backend {
    default             llm_gateway:9000;
    ~text/event-stream  llm_gateway:9001;
}

server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/api/v1/messages$ {
        fastcgi_pass $fpm_backend;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 1800s;
        fastcgi_send_timeout 1800s;
        fastcgi_buffering off;
        fastcgi_request_buffering off;
    }

    location ~ \.php$ {
        fastcgi_pass llm_gateway:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

> **Note.** The global `client_max_body_size 50M` is currently identical for every endpoint. For uploads of 64+ MB to `/api/v1/files`, add a dedicated `location` with a higher limit and align `post_max_size` / `upload_max_filesize` in `docker/php/php.ini` (currently also 50M).

### Create the external network

Before the first launch:

```bash
docker network create microservices-llm
```

### Start

```bash
docker compose up -d --build
```

---

## 5. PHP/nginx limits for the 1M context window

Claude Opus and Sonnet support a 1,000,000-token context window. A single full-context request can reach tens of megabytes and run for several minutes. The actual limits are listed below.

### nginx (current values)

| Parameter | Value | Purpose |
|---|---|---|
| `client_max_body_size` | `50M` | Maximum request body size (global) |
| `fastcgi_read_timeout` (`/api/v1/messages`) | `1800s` | Read timeout for php-fpm responses for long requests and SSE |
| `fastcgi_send_timeout` (`/api/v1/messages`) | `1800s` | Send timeout for php-fpm requests |
| `fastcgi_read_timeout` (other `\.php$`) | `300s` | Read timeout for the rest of the PHP requests |
| `fastcgi_buffering` (`/api/v1/messages`) | `off` | Disabled for streaming (SSE) |
| `fastcgi_request_buffering` (`/api/v1/messages`) | `off` | Disabled for streaming |

### php-fpm, shared `docker/php/php.ini`

A single `php.ini` is applied to both pools (pool-level `php_admin_value` overrides take precedence):

```ini
memory_limit=256M
upload_max_filesize=50M
post_max_size=50M
max_execution_time=300
```

### php-fpm `www` pool (`docker/php-fpm/pool.d/www.conf`)

```ini
[www]
listen = 9000
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 1000
request_terminate_timeout = 1800s

php_admin_value[max_execution_time] = 1800
```

> `request_terminate_timeout = 1800s` and `max_execution_time = 1800` are aligned with `fastcgi_read_timeout 1800s` from nginx for `/api/v1/messages` and the same timeout in the `streaming` pool. Required for sync requests with large context, which can take several minutes to process.

### php-fpm `streaming` pool (`docker/php-fpm/pool.d/streaming.conf`)

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

php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 0
php_admin_value[output_buffering] = Off
php_admin_value[zlib.output_compression] = Off
```

### Files API

The current global nginx limit is `50M`, with `php.ini` also at `50M`. To accept larger uploads:

1. add a dedicated `location ~ ^/api/v1/files$` block in nginx with a higher `client_max_body_size`;
2. align `post_max_size` / `upload_max_filesize` in `docker/php/php.ini` (or via a dedicated `php_admin_value` inside `www.conf`).

For payloads above 50 MB, prefer the Files API (`POST /api/v1/files`) and pass `file_id` instead of inlining content into messages.

### Limits summary (actual)

| Pool | php_admin memory_limit | php_admin max_execution_time | request_terminate_timeout | nginx client_max_body_size |
|---|---|---|---|---|
| www | 256M (from php.ini) | 1800s | 1800s | 50M |
| streaming | 512M | 0 (unlimited) | 1800s | 50M |

---

## 6. Streaming pool sizing

### Sizing formula

Streaming requests to Claude (SSE) hold a php-fpm worker for the entire generation. For large-context models this can be 1-10 minutes per request.

```text
max_concurrent_streams = pm.max_children (streaming pool)
required_children = peak_concurrent_streams * 1.3
memory_per_stream ~ 80 MB
required_ram = pool_size * 80 MB
```

### Worked example

| Parameter | Value |
|---|---|
| Peak load | 50 concurrent streaming requests |
| Safety factor | 1.3 |
| Required `pm.max_children` | 50 * 1.3 = 65 |
| Memory per worker | ~80 MB |
| Required RAM for the streaming pool | 65 * 80 MB = 5.2 GB |

Formula for `pm.max_children`:

```text
pm.max_children = ceil(peak_concurrent * 1.3)
```

Formula for RAM:

```text
streaming_pool_ram_gb = pm.max_children * 80 / 1024
```

### pm.start_servers configuration

Recommended ratios:

```text
pm.start_servers     = ceil(pm.max_children * 0.15)
pm.min_spare_servers = ceil(pm.max_children * 0.08)
pm.max_spare_servers = ceil(pm.max_children * 0.25)
```

### Monitoring listen_queue

The key metric for detecting worker starvation in the streaming pool is `listen queue` from the php-fpm status page.

#### Enable the status page

In `streaming.conf` add:

```ini
pm.status_path = /fpm-status-streaming
```

In nginx:

```nginx
location = /fpm-status-streaming {
    allow 127.0.0.1;
    allow 10.0.0.0/8;
    allow 172.16.0.0/12;
    deny all;
    fastcgi_pass llm_gateway:9001;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

#### Reading the metric

```bash
curl -s http://localhost:8080/fpm-status-streaming?json | jq '.["listen queue"]'
```

The response contains:

- `listen queue` -- requests waiting for a free worker
- `active processes` -- number of busy workers
- `idle processes` -- number of idle workers
- `max children reached` -- how many times the `pm.max_children` cap was hit

#### Alert thresholds

| Metric | Threshold | Condition | Action |
|---|---|---|---|
| `listen queue` | > 5 | Sustained for 2+ minutes | Increase `pm.max_children` |
| `listen queue` | > 20 | Any moment | Critical alert, scale immediately |
| `max children reached` | Increasing | Within the last hour | Pool exhausted, increase capacity |
| `idle processes` | > 50% of max_children | Sustained 30+ minutes | Pool can be shrunk |

#### Prometheus rule example

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

#### Scaling procedure

1. Check the current `listen queue` value:
   ```bash
   curl -s http://localhost:8080/fpm-status-streaming?json | jq '.'
   ```
2. Compute the new `pm.max_children` with the formula above.
3. Check available RAM: `free -g`.
4. Update `streaming.conf`.
5. Restart the gateway container to reload both pools: `docker compose restart llm_gateway`.
6. Confirm `listen queue` is back to 0.

For incident response procedures see `operational_runbook.md`, section 12.

---

## 7. Migrations

### Initial migration run

```bash
docker compose exec llm_gateway php artisan migrate --force
```

The `--force` flag is mandatory in `production` and `staging`.

### Create the test database

Tests need a dedicated `llm_gateway_test` database:

```bash
docker compose exec llm_gateway php artisan llm:create-test-db
```

The command creates `llm_gateway_test` on the same MySQL server.

### Check migration status

```bash
docker compose exec llm_gateway php artisan migrate:status
```

### Legacy drop

For removing obsolete tables or columns during upgrades, see `operational_runbook.md` -- the breaking-change migration section. All destructive migrations require explicit confirmation.

---

## 8. Creating the first client

### Create a client

```bash
docker compose exec llm_gateway php artisan client:create "MyService" \
    --model-alias=claude-sonnet \
    --rate-limit=60 \
    --monthly-cap=500.00 \
    --features=thinking,prompt_caching,batch
```

Parameters:

| Parameter | Purpose |
|---|---|
| `name` (positional, required) | Client name |
| `--model-alias` | Default model: `claude-opus`, `claude-sonnet`, `claude-haiku` |
| `--rate-limit` | Requests per minute |
| `--monthly-cap` | Monthly spend cap in USD |
| `--features` | Allowed features (comma-separated) |

Available features: `thinking`, `web_search`, `code_execution`, `computer_use`, `bash`, `text_editor`, `priority_tier`, `citations`, `prompt_caching`, `structured_outputs`, `batch`.

The command prints the API key and signing secret. Store them -- they are shown only once.

```text
Client created: id=1 name="MyService"
================================================================
API KEY (save now, will not be shown again):
  llmgw_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
SIGNING SECRET (for webhook verification):
  whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
================================================================
```

### Inspect a client

```bash
docker compose exec llm_gateway php artisan client:show 1
```

Prints a table with workspace, rate limit, dev mode, allowed features, monthly cap, current month spend, request count, average latency, and top used models.

### Enable/disable a feature

```bash
docker compose exec llm_gateway php artisan client:enable-feature 1 web_search
docker compose exec llm_gateway php artisan client:disable-feature 1 web_search
```

### Rotate the API key

Instant rotation, no grace period. The previous key stops working immediately:

```bash
docker compose exec llm_gateway php artisan client:rotate-key 1
```

### Rotate the signing secret

Rotation with a 24-hour grace period. The previous secret keeps working during the grace window:

```bash
docker compose exec llm_gateway php artisan client:rotate-secret 1
```

Expired previous secrets are purged on schedule by `webhook:cleanup-expired-secrets` (every hour).

---

## 9. Healthcheck setup

### Endpoints

| Endpoint | Method | Middleware | Purpose |
|---|---|---|---|
| `/internal/health` | GET | `internal.network` | Healthcheck (MySQL, Redis, Anthropic API) |
| `/internal/stats` | GET | `internal.network` | Queue, spend, and pending-request stats |

Access to `/internal/*` is restricted by the `internal.network` middleware -- internal networks only (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.1).

### /internal/health response

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

Statuses: `ok`, `degraded`, `down`. HTTP 200 for `ok` and `degraded`, HTTP 503 for `down`.

### /internal/stats response

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

Already wired into the nginx container:

```yaml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost/internal/health"]
  interval: 30s
  timeout: 10s
  retries: 3
```

### Prometheus scraping

For Prometheus integration, set up a scrape target on `/internal/stats` with JSON parsing. The response format is stable.

---

## 10. Scheduled tasks

The scheduler runs in a dedicated container with:

```bash
php artisan schedule:work
```

### Task list

| Task | Schedule | Purpose |
|---|---|---|
| `RetryFailedWebhooks` | Every minute | Retry failed webhook deliveries |
| `ClaudeApiPingScheduled` | Every minute | Probe Anthropic API availability |
| `requests:cleanup` | Daily at 03:00 | Purge old `request_log` rows |
| `webhook:cleanup-expired-secrets` | Hourly | Drop expired previous signing secrets |
| `claude:sync-capabilities` | Weekly (Sun, 03:00) | Sync model capabilities with the API |
| `claude:poll-batches` | Every minute | Poll batch request status |
| `claude:flush-accumulator` | Every minute | Flush accumulated batch requests |
| `claude:cleanup-files` | Weekly (Sun, 03:00) | Clean unused files in the Files API |

All tasks use `withoutOverlapping()` to prevent duplication. Critical tasks use `onOneServer()` for clustered scheduling.

For per-task details see `internal_logic.md`, section 16.

---

## 11. Logging

### Log channels

| Channel | Driver | File | Rotation | Level |
|---|---|---|---|---|
| `stack` (default) | stack | -- | -- | Inherits from nested channels |
| `daily` | daily | `storage/logs/laravel.log` | 14 days | Per `LOG_LEVEL` |
| `llm` | daily | `storage/logs/llm.log` | 30 days | Per `LLM_LOG_LEVEL` (default: `error`) |

### Recommended production configuration

```bash
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LLM_LOG_LEVEL=error
```

### Docker volume for logs

Mount `storage/logs` on the host for access without `exec`:

```yaml
volumes:
  - ./logs:/var/www/html/storage/logs
```

### Log search examples

Anthropic API errors in the last hour:

```bash
grep "$(date -d '1 hour ago' +%Y-%m-%d)" storage/logs/llm-$(date +%Y-%m-%d).log | grep -i "error"
```

All authentication errors:

```bash
grep "authentication" storage/logs/laravel-$(date +%Y-%m-%d).log
```

Webhook delivery errors:

```bash
grep "webhook\|callback" storage/logs/llm-$(date +%Y-%m-%d).log
```

Queue worker logs (supervisor):

```bash
tail -f storage/logs/worker.log
```

### Log cleanup

Automatic rotation: the `llm` channel keeps files for 30 days, `daily` for 14 days. No extra cleanup required.

---

## 12. Production recommendations

### Queue worker scaling

- Run N queue workers depending on load. Baseline: 2 workers per CPU core.
- Use supervisor for process management (config in `docker/supervisor/queue-worker.conf`).
- `--max-time=3600` restarts the worker every hour to prevent memory leaks.
- `--memory=256` stops the worker when it exceeds 256 MB.
- Monitor queue depth via `/internal/stats`.
- The `batch` queue can be served by a separate worker pool.

Worker scaling:

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

Recommendations:
- Daily full dump plus binlog replication.
- Point-in-time recovery via binlog.
- Test restores monthly.
- Retention: 30 days for dumps, 7 days for binlog.

### Redis persistence

Enable AOF (Append Only File) to protect queue data:

```yaml
llm_redis:
  image: redis:7-alpine
  command: redis-server --appendonly yes --appendfsync everysec
  volumes:
    - llm_redis_data:/data
```

Recommendations:
- `appendfsync everysec` -- balance between throughput and durability (max 1 second of data loss).
- Monitor `used_memory` via `redis-cli info memory`.
- Set `maxmemory-policy allkeys-lru` for production.

### TLS termination

The gateway does not perform TLS termination on its own. Recommended options:

1. Reverse proxy -- nginx/HAProxy/Traefik in front of the `llm_nginx` container.
2. Cloud Load Balancer -- AWS ALB, GCP HTTPS LB.
3. Kubernetes Ingress -- with cert-manager for automatic certificate renewal.

Minimum TLS configuration:
- TLS 1.2+ (1.3 recommended).
- HSTS header.
- Strong cipher suites.

Example with an external nginx:

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

- Never commit `.env` to git.
- Use Docker Secrets, HashiCorp Vault, or AWS Secrets Manager.
- `API_KEY_PEPPER` is a critical secret. Losing it invalidates every client API key.
- `APP_KEY` is the Laravel encryption key. Losing it makes all encrypted data (signing secrets) unreadable.
- Rotate `ANTHROPIC_API_KEY` on suspected compromise.
- Client signing secrets rotate via `client:rotate-secret` with a 24-hour grace period.

### Network security

- MySQL and Redis containers must not be reachable from outside. In production drop the `3307:3306` and `6381:6379` port mappings.
- Internal endpoints (`/internal/*`) are protected by the `internal.network` middleware.
- Use the internal docker network `llm_internal` for service-to-service traffic.

### Monitoring (checklist)

- `/internal/health` -- baseline healthcheck (MySQL, Redis, Anthropic).
- `/internal/stats` -- queue depth, pending requests, top spenders.
- php-fpm status page -- streaming pool `listen_queue` (see section 6).
- Redis `INFO` -- used_memory, connected_clients, rejected_connections.
- MySQL slow query log.
- `storage/logs/` size on disk.
- Webhook delivery success rate (from `request_log`/`response_log`).
