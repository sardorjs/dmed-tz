# Code Review — Docker / Infrastructure
> Автор ревью: Senior Team Lead Backend (PHP/Laravel, 10+ лет)
> Дата: 2026-02-18
> Приоритеты: 🔴 Critical · 🟠 High · 🟡 Medium · 🟢 Low

---

## Содержание

1. [Отсутствует `.dockerignore`](#1-отсутствует-dockerignore)
2. [docker-compose.yml](#2-docker-composeyml)
3. [Dockerfile](#3-dockerfile)
4. [nginx/default.conf](#4-nginxdefaultconf)
5. [supervisord.conf](#5-supervisordconf)
6. [laravel.ini](#6-laravelini)
7. [Makefile](#7-makefile)
8. [.env.example](#8-envexample)
9. [CI/CD (.github/workflows)](#9-cicd-githubworkflows)
10. [README.md](#10-readmemd)
11. [Общие замечания](#11-общие-замечания)

---

## 1. Отсутствует `.dockerignore`

**🔴 Critical**

В проекте нет файла `.dockerignore`. Это означает, что при каждой пересборке образа Docker отправляет в build context **весь** проект, включая `vendor/` (~40–100 MB), `.git/`, `node_modules/`, `backups/`, `storage/`.

**Последствия:**
- Чудовищно медленная сборка (видно в README: `transferring context: 108.71MB`)
- Утечка чувствительных данных в образ (`.env`, ключи)
- Нарушение принципа минимального образа

**Решение — создать `.dockerignore`:**
```
.git
.github
.idea
.vscode
node_modules
vendor
backups
storage/logs
storage/framework/cache
storage/framework/sessions
storage/framework/views
public/hot
public/storage
*.md
*.log
docker-compose*.yml
Makefile
.env*
phpunit.xml
tests/
```

---

## 2. docker-compose.yml

### 2.1 🔴 Critical — Неверный путь volume для PostgreSQL

```yaml
# НЕВЕРНО — данные хранятся в /var/lib/postgresql, не в /data
volumes:
    - postgres-data:/var/lib/postgresql

# ВЕРНО — данные PostgreSQL живут в /data
volumes:
    - postgres-data:/var/lib/postgresql/data
```

При текущей конфигурации при рестарте контейнера данные **не сохраняются**. Это катастрофическая ошибка.

---

### 2.2 🔴 Critical — Healthcheck для PHP-FPM работает неправильно

```yaml
# app (PHP-FPM) — НЕВЕРНО
healthcheck:
    test: ["CMD", "curl", "-f", "http://localhost"]
```

PHP-FPM — это FastCGI-демон, а не HTTP-сервер. `curl http://localhost` всегда будет падать, потому что никто не слушает 80-й порт внутри контейнера `app`.

**Верный healthcheck для PHP-FPM:**
```yaml
healthcheck:
    test: ["CMD-SHELL", "php-fpm -t || exit 1"]
    interval: 30s
    retries: 3
    start_period: 30s
    timeout: 10s
```

---

### 2.3 🟠 High — `depends_on` без проверки healthy

```yaml
# Текущее состояние — app ждёт только *старта* db, не его готовности
depends_on:
    - db
```

PostgreSQL стартует медленнее, чем Docker считает контейнер запущенным. Приложение будет пытаться подключиться к БД, пока она ещё инициализируется, и упадёт.

```yaml
# Верно — ждём реальной готовности
depends_on:
    db:
        condition: service_healthy
    redis:
        condition: service_healthy
```

---

### 2.4 🟠 High — У `nginx` нет `depends_on: app`

Nginx стартует и пытается резолвить upstream `app:9000`, которого ещё нет.

```yaml
nginx:
    depends_on:
        app:
            condition: service_healthy
```

---

### 2.5 🟠 High — Inconsistency: `restart` политики разные

| Сервис | restart |
|--------|---------|
| db     | `unless-stopped` |
| redis  | `always` |
| app    | ❌ не задан |
| nginx  | ❌ не задан |

`always` перезапустит контейнер даже после `docker compose down` при следующем старте Docker. `unless-stopped` — правильный выбор для всех сервисов. Определите единую политику.

---

### 2.6 🟡 Medium — Redis не имеет healthcheck

```yaml
redis:
    healthcheck:
        test: ["CMD", "redis-cli", "ping"]
        interval: 10s
        retries: 5
        start_period: 10s
        timeout: 5s
```

---

### 2.7 🟡 Medium — Комментарий "MySQL" вместо "PostgreSQL"

```yaml
# Пробрасывает порты 3306 из контейнера на хост  ← MySQL comment, проект на PostgreSQL
ports:
    - "5432:5432"
```

Это создаёт путаницу при онбординге новых разработчиков.

---

### 2.8 🟡 Medium — Нет resource limits

В production и даже в dev-окружении отсутствуют ограничения ресурсов. Один контейнер может поглотить всю RAM хоста.

```yaml
app:
    deploy:
        resources:
            limits:
                cpus: '2.0'
                memory: 512M
            reservations:
                memory: 256M
```

---

### 2.9 🟢 Low — Hardcoded `image: nginx:1.26.2` без `alpine`

Рекомендуется использовать `-alpine` образы для уменьшения размера: `nginx:1.26.2-alpine` (~45 MB vs ~190 MB).

---

## 3. Dockerfile

### 3.1 🔴 Critical — Два отдельных `RUN apt-get update`

```dockerfile
# Слой 1
RUN apt-get update && apt-get install -y ... && docker-php-ext-install ... pdo_mysql mysqli

# Слой 2 (отдельно!)
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo_pgsql pgsql
```

Это антипаттерн. Каждый `RUN` создаёт отдельный слой. Список пакетов в слое 1 может быть уже устаревшим к моменту слоя 2 (кэш). Оба `RUN` надо объединить. Также после установки надо очищать кэш apt:

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
    bash git unzip curl locales \
    libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
    libonig-dev libzip-dev libicu-dev libpq-dev \
    g++ make autoconf supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd mbstring pdo pdo_pgsql pgsql zip intl opcache exif pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
```

---

### 3.2 🔴 Critical — Установлены MySQL-расширения в PostgreSQL проекте

```dockerfile
docker-php-ext-install -j$(nproc) gd mbstring pdo pdo_mysql zip intl mysqli opcache exif pcntl
#                                                   ^^^^^^^^^        ^^^^^^^
#                                                   MySQL!           MySQL!
```

Проект использует **PostgreSQL**. `pdo_mysql` и `mysqli` — лишний вес и потенциальная путаница. Удалить.

---

### 3.3 🟠 High — Нет multi-stage build

Текущий образ содержит: `nano`, `git`, `g++`, `make`, `autoconf`, `curl`, исходники компиляторов — всё это нужно только во время **сборки**, не в рантайме.

Multi-stage build решает это:

```dockerfile
# --- Stage 1: builder ---
FROM php:8.3-fpm AS builder
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl g++ make autoconf libpq-dev libpng-dev ...
# установка composer, php extensions, composer install --no-dev --optimize-autoloader

# --- Stage 2: production ---
FROM php:8.3-fpm AS production
# копируем только то, что нужно в рантайме
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /var/www /var/www
# минимум системных пакетов для рантайма
```

Это сокращает размер финального образа в 3–5 раз и убирает dev-инструменты из production.

---

### 3.4 🟠 High — `composer:latest` без закрепления версии

```dockerfile
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
```

`latest` — антипаттерн в Dockerfile. Сборка должна быть воспроизводимой:

```dockerfile
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
```

---

### 3.5 🟠 High — Контейнер работает от root

В `supervisord.conf` только `horizon` запускается от `www-data`. PHP-FPM процесс запускается без явного указания пользователя. В Dockerfile нет:

```dockerfile
USER www-data
```

Запуск от root — нарушение принципа least privilege. Если атакующий получит RCE, он будет root внутри контейнера.

---

### 3.6 🟠 High — Node.js установлен, но не используется

```dockerfile
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs
```

`npm install` и `vite build` закомментированы. Если фронтенд не собирается в контейнере — убрать Node.js из образа. Это +300 MB лишнего веса. Для сборки фронтенда — отдельный builder stage или CI-шаг.

---

### 3.7 🟡 Medium — `COPY . ${DOCKER_BASE_PATH}` монтирует весь проект включая vendor

```dockerfile
COPY . ${DOCKER_BASE_PATH}
```

Из-за отсутствия `.dockerignore` (см. п.1) в образ попадает `vendor/`, `.git/`, `backups/` и всё остальное. Даже если `.dockerignore` будет создан — `composer install` внутри Dockerfile закомментирован, то есть в образе нет vendor. Нужно определиться с подходом:

- **Вариант A (правильный для prod)**: `composer install --no-dev --optimize-autoloader` внутри Dockerfile, volume не монтируется.
- **Вариант B (для dev)**: volume mount поверх, vendor ставится руками. Но тогда в Dockerfile не нужен `COPY . .` (он перекрывается volume).

Сейчас смешаны оба подхода.

---

### 3.8 🟢 Low — `nano` в production образе

`nano` — интерактивный редактор. В production-образе ему нечего делать. Его наличие сигнализирует о том, что предполагается ручная работа внутри контейнера — это антипаттерн.

---

## 4. nginx/default.conf

### 4.1 🔴 Critical — Колоссальные размеры FastCGI буферов

```nginx
fastcgi_buffers 128 4096k;       # 128 × 4 MB = 512 MB на один запрос
fastcgi_buffer_size 4096k;       # 4 MB начальный буфер
fastcgi_busy_buffers_size 4096k; # 4 MB
```

512 MB на **один** параллельный запрос — это катастрофа. При 10 одновременных запросах nginx займёт 5 GB RAM. Адекватные значения:

```nginx
fastcgi_buffers 16 16k;
fastcgi_buffer_size 32k;
fastcgi_busy_buffers_size 64k;
```

---

### 4.2 🟠 High — proxy_buffer настройки в FastCGI блоке

```nginx
location ~ \.php$ {
    proxy_buffer_size   128k;   # ← это директивы для proxy_pass, не для fastcgi_pass
    proxy_buffers   4 256k;
    proxy_busy_buffers_size   256k;
}
```

`proxy_buffer_*` применяются к `proxy_pass`. Здесь используется `fastcgi_pass` — эти директивы игнорируются nginx и вводят в заблуждение.

---

### 4.3 🟠 High — Заголовок `X-XSS-Protection` устарел

```nginx
add_header X-XSS-Protection "1; mode=block";
```

Этот заголовок **deprecated** во всех современных браузерах (Chrome удалил в 2019, Firefox никогда не поддерживал). Правильная замена — строгий `Content-Security-Policy`.

---

### 4.4 🟠 High — CSP слишком мягкий

```nginx
add_header Content-Security-Policy "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: https:;";
```

`'unsafe-inline'` и `'unsafe-eval'` фактически нейтрализуют защиту CSP. С ними CSP не защищает от XSS. Для Laravel API нужно минимум:

```nginx
add_header Content-Security-Policy "default-src 'none'; script-src 'self'; connect-src 'self'; img-src 'self'; style-src 'self';";
```

---

### 4.5 🟡 Medium — Нет gzip-сжатия

```nginx
# В конфиге это отсутствует полностью
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 6;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
```

Без сжатия JSON-ответы API передаются в несжатом виде.

---

### 4.6 🟡 Medium — Нет кэширования статических файлов

```nginx
location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}
```

---

### 4.7 🟡 Medium — Нет заголовков безопасности

Отсутствуют:
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), microphone=(), camera=()`
- `Strict-Transport-Security` (для HTTPS)

---

### 4.8 🟡 Medium — `server_tokens off` в server блоке

```nginx
server {
    server_tokens off;  # ← работает, но лучше в http {} блоке глобально
}
```

Должно быть в `nginx.conf` на уровне `http {}`, чтобы применялось ко всем виртуальным хостам.

---

### 4.9 🟢 Low — Путь `/var/www/public` захардкожен

```nginx
root /var/www/public;
```

Этот путь дублирует значение `DOCKER_BASE_PATH` из `.env`. Если переменная изменится — nginx надо обновлять вручную. Рекомендуется вынести в переменную nginx или использовать `envsubst` при старте контейнера.

---

## 5. supervisord.conf

### 5.1 🟠 High — PHP-FPM секция почти пустая

```ini
[program:php-fpm]
command=php-fpm
# Нет: autostart, autorestart, stdout_logfile, stderr_logfile, user, priority
```

Laravel Horizon может стартовать раньше PHP-FPM. Нужна конфигурация с приоритетами:

```ini
[program:php-fpm]
command=php-fpm --nodaemonize
autostart=true
autorestart=true
user=www-data
priority=10
stdout_logfile=/var/log/supervisor/php-fpm.log
stderr_logfile=/var/log/supervisor/php-fpm.err

[program:horizon]
command=php /var/www/artisan horizon
directory=/var/www
autostart=true
autorestart=true
user=www-data
priority=20
stopwaitsecs=3600
stdout_logfile=/var/log/supervisor/horizon.log
stderr_logfile=/var/log/supervisor/horizon.err
startretries=3
```

`stopwaitsecs=3600` важен — позволяет Horizon завершить текущие задачи перед остановкой (graceful shutdown).

---

### 5.2 🟡 Medium — `php-fpm` без `--nodaemonize`

PHP-FPM по умолчанию форкается в фон. Supervisor теряет процесс, считает его упавшим и перезапускает в бесконечном цикле. Нужен флаг `--nodaemonize` (или `-F`).

---

## 6. laravel.ini

### 6.1 🟠 High — Нет конфигурации OPcache

```ini
; Полностью отсутствует — при 512M memory_limit это важно
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.revalidate_freq=0      ; 0 = никогда не проверять в production
opcache.validate_timestamps=0  ; отключить в production
opcache.save_comments=1
opcache.fast_shutdown=1
```

OPcache установлен (`docker-php-ext-install opcache`), но не настроен — работает с дефолтами, которые далеко не оптимальны.

---

### 6.2 🟡 Medium — Нет `max_execution_time`

```ini
max_execution_time = 60  ; для API запросов
; или 300 для консольных команд
```

---

### 6.3 🟡 Medium — Нет `date.timezone`

```ini
date.timezone = UTC
```

Без этого PHP генерирует предупреждение и использует значение системы, которое может отличаться.

---

### 6.4 🟢 Low — `memory_limit = 512M` как дефолт

512 MB — очень высокий порог даже для enterprise. Нормальные значения — 128M–256M для API, 512M+ только для специфичных задач (импорт). Завышенный лимит маскирует утечки памяти.

---

## 7. Makefile

### 7.1 🔴 Critical — MySQL команды в PostgreSQL проекте

```makefile
# НЕВЕРНО — проект использует PostgreSQL
db:
    docker compose exec -it db bash -c "mysql -u $(DB_USERNAME) -p$(DB_PASSWORD) $(DB_DATABASE)"

db-root:
    docker compose exec -it db bash -c "mysql -h 127.0.0.1 -P 3306 -u root -p$(DB_ROOT_PASSWORD)"

export-db:
    docker compose exec db mysqldump -u root -p$(DB_ROOT_PASSWORD) $(DB_DATABASE) > ...

import-db:
    cat backups/... | docker compose exec -T db mysql -u ...

db-grant-privileges:
    ... "GRANT SYSTEM_VARIABLES_ADMIN ON *.* TO ..."  # MySQL-синтаксис
```

Все 5 команд используют MySQL CLI для PostgreSQL-контейнера. Ни одна из них не работает.

**Верные команды для PostgreSQL:**
```makefile
db:
    docker compose exec -it db psql -U $(DB_USERNAME) -d $(DB_DATABASE)

export-db:
    docker compose exec db pg_dump -U $(DB_USERNAME) $(DB_DATABASE) > "backups/app-$(TIMESTAMP).sql"

import-db:
    cat backups/$(LAST_SQL_BACKUP_FILE) | docker compose exec -T db psql -U $(DB_USERNAME) -d $(DB_DATABASE)
```

PostgreSQL не имеет концепции "root" в смысле MySQL — есть superuser (обычно `postgres`).

---

### 7.2 🟠 High — `$(APP_DOCKER_COMPOSE)` используется непоследовательно

```makefile
up:          docker compose up -d                             # без переменной
up-force:    docker compose -f $(APP_DOCKER_COMPOSE) up ...  # с переменной
down:        docker compose down                             # без переменной
down-v-prune: docker compose -f $(APP_DOCKER_COMPOSE) down  # с переменной
```

Половина команд использует переменную `APP_DOCKER_COMPOSE` из `.env`, половина — нет. Это ломает поддержку нескольких окружений. Привести к единому стилю.

---

### 7.3 🟠 High — `cache-clear` выполняет 7 отдельных `docker exec`

```makefile
cache-clear:
    docker compose exec -it app bash -c "php artisan cache:clear"
    docker compose exec -it app bash -c "php artisan config:clear"
    docker compose exec -it app bash -c "php artisan event:clear"
    ... # ещё 4 вызова
```

Каждый `docker exec` — отдельный форк процесса с overhead. Оптимальнее:

```makefile
cache-clear:
    docker compose exec -it app bash -c "\
        php artisan cache:clear && \
        php artisan config:clear && \
        php artisan event:clear && \
        php artisan route:clear && \
        php artisan view:clear && \
        php artisan schedule:clear-cache && \
        php artisan config:cache"
```

---

### 7.4 🟡 Medium — `.PHONY` список неполный

Не включены: `up-build`, `last-sql-file`, `import-db`, `export-db`, `db-grant-privileges`, `logs-nginx`, `help`

---

### 7.5 🟡 Medium — `help` обслуживается вручную

Текст в `make help` не синхронизирован с реальными командами (например, `restore-db` и `backup-db` в help, а в Makefile — `import-db` и `export-db`). Используйте авто-генерацию:

```makefile
help: ## Показать список команд
    @grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
        awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
```

Тогда каждая команда документируется прямо в Makefile через `## описание`.

---

### 7.6 🟡 Medium — Нет команды `make setup`

Онбординг требует: `make up` → `make cli` → `composer install` → `php artisan key:generate` → `php artisan migrate` — это 5 шагов. Должна быть одна команда:

```makefile
setup: up ## Полная инициализация проекта
    docker compose exec app composer install
    docker compose exec app php artisan key:generate
    docker compose exec app php artisan migrate --seed
    docker compose exec app php artisan storage:link
```

---

### 7.7 🟡 Medium — Мёртвый закомментированный код

```makefile
prune-all:
    ...
    #rm -rf .docker/mysql/data  ← мёртвая команда, MySQL не используется
```

Нарушает принцип "dead code must not be in project" из требований.

---

### 7.8 🟢 Low — Нет `logs-redis` команды

Есть `logs-nginx`, `logs-app`, `logs-db` — но нет `logs-redis`.

---

## 8. .env.example

### 8.1 🔴 Critical — Реальный `APP_KEY` в `.env.example`

```env
APP_KEY=base64:1xIHtk1BTxa/QiAm+SoRGbS+hJBjXjRREV0GgLM21F0=
```

**Никогда** не помещать реальный ключ в `.env.example`. Файл example коммитится в git, ключ становится публичным. Должно быть:

```env
APP_KEY=
```

---

### 8.2 🔴 Critical — Inline-комментарий нарушает парсинг

```env
REDIS_HOST=redis# название контейнера Docker
```

Нет пробела перед `#`. Большинство парсеров `.env` (включая Laravel's `vlucas/phpdotenv`) будут трактовать `redis# название контейнера Docker` как **значение переменной**. Redis-соединение упадёт с ошибкой хоста `redis# название контейнера Docker`.

```env
# название контейнера Docker
REDIS_HOST=redis
```

---

### 8.3 🟠 High — `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` используют `database` вместо `redis`

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

В `docker-compose.yml` развёрнут Redis. Использование БД для сессий, кэша и очередей:
- Создаёт лишнюю нагрузку на PostgreSQL
- Замедляет приложение в 5–10 раз по сравнению с Redis
- Требует миграций для sessions/jobs таблиц

Правильные значения при наличии Redis:
```env
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

---

### 8.4 🟠 High — `REDIS_PASSWORD=null` как строка

```env
REDIS_PASSWORD=null
```

Laravel передаёт это в Redis как строку `"null"`, что приводит к ошибке аутентификации если Redis без пароля. Должно быть:

```env
REDIS_PASSWORD=
```

---

### 8.5 🟡 Medium — Нет переменных для Laravel Horizon

В `supervisord.conf` запускается `php artisan horizon`, но в `.env.example` нет:

```env
HORIZON_PREFIX=horizon:
# HORIZON_MEMORY_LIMIT=64
# HORIZON_BALANCE=auto
```

---

### 8.6 🟡 Medium — `SESSION_DOMAIN=null` как строка

```env
SESSION_DOMAIN=null
```

Аналогично п.8.4 — строка `"null"` будет использована как домен сессии. Должно быть пустым:

```env
SESSION_DOMAIN=
```

---

### 8.7 🟢 Low — Нет переменных для Kafka

В `CLAUDE.md` указано, что Kafka установлена и используется в проекте. В `.env.example` нет ни одной Kafka-переменной:

```env
KAFKA_BROKERS=kafka:9092
KAFKA_GROUP_ID=
KAFKA_CONSUMER_TIMEOUT_MS=1000
```

---

## 9. CI/CD (.github/workflows)

### 9.1 🟠 High — Тесты запускаются с SQLite, а проект на PostgreSQL

```yaml
# tests.yml
extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite
# pdo_pgsql отсутствует!
```

В `phpunit.xml` `DB_CONNECTION=sqlite` закомментирован, но в CI также нет PostgreSQL. Тесты могут проходить на SQLite и падать на PostgreSQL из-за PostgreSQL-specific синтаксиса.

**Правильный подход — поднять PostgreSQL в CI:**

```yaml
services:
    postgres:
        image: postgres:16.4
        env:
            POSTGRES_DB: laravel_test
            POSTGRES_USER: postgres
            POSTGRES_PASSWORD: password
        ports:
            - 5432:5432
        options: >-
            --health-cmd pg_isready
            --health-interval 10s
            --health-timeout 5s
            --health-retries 5
```

---

### 9.2 🟡 Medium — `fail-fast: true` в matrix

```yaml
strategy:
    fail-fast: true
    matrix:
        php: [8.2, 8.3]
```

При `fail-fast: true` если PHP 8.2 упал — PHP 8.3 даже не запустится. Рекомендуется `fail-fast: false` чтобы видеть полную картину совместимости.

---

### 9.3 🟡 Medium — `schedule: cron: '0 0 * * *'` — зачем?

Ежедневный запуск тестов по расписанию оправдан для проверки внешних зависимостей (API-клиенты, SDK). Если проект — внутренний Laravel API без внешних зависимостей, это лишний расход GitHub Actions минут.

---

### 9.4 🟡 Medium — Нет кэширования Composer в CI

```yaml
# Добавить перед composer install
- name: Cache Composer dependencies
  uses: actions/cache@v4
  with:
      path: ~/.composer/cache
      key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
      restore-keys: ${{ runner.os }}-composer-
```

Ускоряет CI на 30–60 секунд на каждом прогоне.

---

### 9.5 🟢 Low — `vendor/bin/phpunit` вместо `php artisan test`

```yaml
run: vendor/bin/phpunit
```

`php artisan test` предоставляет более красивый вывод, поддерживает `--parallel` и интегрируется с Laravel test helpers. Предпочтительнее в экосистеме Laravel.

---

## 10. README.md

### 10.1 🟠 High — 6 шагов, каждый требует `make cli` + ручной команды

Onboarding flow:
```
make cli → composer install
make cli → chown -R ...
make cli → php artisan key:generate
make cli → php artisan migrate
```

Это 4 отдельных входа в контейнер. При наличии `make setup` (см. п.7.6) весь процесс должен быть:

```bash
cp .env.example .env
# Отредактировать .env
make setup
```

---

### 10.2 🟡 Medium — Упоминания MySQL вместо PostgreSQL

```markdown
## Logging in to MySQL as a created user  ← в make help
## Logging in to MySQL as root             ← в make help
```

Проект на PostgreSQL — документация создаёт ложное впечатление.

---

### 10.3 🟡 Medium — Нет раздела с описанием архитектуры и стека

README — это первое, что видит разработчик. Должно быть:
- Стек технологий (PHP 8.3, Laravel 11, PostgreSQL 16, Redis 7, Nginx)
- Назначение проекта (тестовое задание для dmed)
- Описание ключевых переменных `.env`

---

### 10.4 🟡 Medium — Пароль в примере слишком очевидный

```markdown
DB_PASSWORD="exampleBigPassword1234324543#$2"
```

Разработчики часто копируют пример напрямую. Лучше давать инструкцию сгенерировать случайный пароль:

```bash
openssl rand -base64 32
```

---

## 11. Общие замечания

### 11.1 🟠 High — `backups/` директория в репозитории

```
drwxr-xr-x  5  backups/
```

Папка с SQL-бэкапами не должна быть в git. Добавить в `.gitignore`:

```
/backups/*.sql
```

---

### 11.2 🟠 High — `laravel/sail` в `require-dev` при наличии кастомного Docker

```json
"require-dev": {
    "laravel/sail": "^1.26"
}
```

Sail — это своя Docker-инфраструктура Laravel. Она конфликтует концептуально с кастомным Docker setup. Sail не используется, это лишняя зависимость. Удалить.

---

### 11.3 🟡 Medium — Нет `docker-compose.override.yml`

Стандартная практика: `docker-compose.yml` — базовая конфигурация, `docker-compose.override.yml` — локальные переопределения (Xdebug, volume mounts, dev-порты). Это позволяет production-config не содержать dev-специфичных настроек.

---

### 11.4 🟡 Medium — Xdebug только через закомментированный код

Весь Xdebug-блок в Dockerfile закомментирован. При необходимости разработчик должен раскомментировать код в Dockerfile — это антипаттерн. Правильный подход: отдельный `docker-compose.override.yml` с dev-образом, который наследует базовый и добавляет Xdebug через build arg.

---

### 11.5 🟢 Low — Смешанный язык комментариев

В `docker-compose.yml`, `Dockerfile`, `Makefile` комментарии на русском. В коде приложения, конфигах — английский. Определите единый стандарт команды.

---

## Итоговая таблица приоритетов

| # | Файл | Проблема | Приоритет |
|---|------|----------|-----------|
| 1 | — | Нет `.dockerignore` | 🔴 Critical |
| 2 | docker-compose.yml | Неверный path для PostgreSQL data | 🔴 Critical |
| 3 | docker-compose.yml | Healthcheck PHP-FPM через curl | 🔴 Critical |
| 4 | Dockerfile | MySQL расширения в PostgreSQL проекте | 🔴 Critical |
| 5 | .env.example | Реальный APP_KEY закоммичен | 🔴 Critical |
| 6 | .env.example | Inline-комментарий в REDIS_HOST сломан | 🔴 Critical |
| 7 | Makefile | Все DB-команды используют MySQL CLI | 🔴 Critical |
| 8 | nginx | Буферы FastCGI по 512 MB на запрос | 🔴 Critical |
| 9 | docker-compose.yml | `depends_on` без `service_healthy` | 🟠 High |
| 10 | docker-compose.yml | nginx не зависит от app | 🟠 High |
| 11 | docker-compose.yml | Разные restart-политики | 🟠 High |
| 12 | Dockerfile | Нет multi-stage build | 🟠 High |
| 13 | Dockerfile | `composer:latest` не закреплён | 🟠 High |
| 14 | Dockerfile | Контейнер работает от root | 🟠 High |
| 15 | Dockerfile | Node.js установлен, не используется | 🟠 High |
| 16 | Dockerfile | Два отдельных `apt-get update` | 🔴 Critical |
| 17 | nginx | proxy_buffer в FastCGI блоке | 🟠 High |
| 18 | nginx | X-XSS-Protection устарел | 🟠 High |
| 19 | nginx | CSP с unsafe-inline/eval | 🟠 High |
| 20 | supervisord | php-fpm без nodaemonize и настроек | 🟠 High |
| 21 | laravel.ini | OPcache не настроен | 🟠 High |
| 22 | .env.example | SESSION/CACHE/QUEUE на database не redis | 🟠 High |
| 23 | .env.example | REDIS_PASSWORD=null (строка) | 🟠 High |
| 24 | CI/CD | Тесты на SQLite, проект на PostgreSQL | 🟠 High |
| 25 | composer.json | laravel/sail при кастомном Docker | 🟠 High |
| 26 | общее | backups/ в репозитории | 🟠 High |
