# Fix Checklist — What Was Changed & How to Verify

> Run verifications **after** `make setup` (fresh start) or `make up-build` (rebuild).

---

## 1. `.dockerignore` — Created (was missing)

**What:** New file that prevents Docker from sending `vendor/`, `.git/`, `backups/`, logs etc. into build context.

**Verify:**
```bash
# Build should be fast now (< 5s context transfer instead of 108 MB)
make up-build
# Look for "transferring context: X.XX kB" — must be kilobytes, not megabytes
```

---

## 2. `docker-compose.yml`

### 2.1 PostgreSQL data path fixed
**What:** `postgres-data:/var/lib/postgresql` → `postgres-data:/var/lib/postgresql/data`

**Verify:**
```bash
make setup
# Insert a record, restart, check data persists
make migrate-seed
make down
make up
make db
# SELECT * FROM migrations; — should return rows, not be empty
```

### 2.2 App healthcheck fixed (PHP-FPM → pgrep)
**What:** `curl http://localhost` → `pgrep -x php-fpm` (FPM is FastCGI, not HTTP)

**Verify:**
```bash
docker compose ps
# app container must show "(healthy)" status after ~30s
```

### 2.3 `depends_on` with `service_healthy` conditions
**What:** `app` now waits for `db` and `redis` to be healthy before starting. `nginx` waits for `app`.

**Verify:**
```bash
make down
make up
docker compose ps
# Startup order: db → redis → app → nginx
# No "connection refused" errors in app logs on first start
make logs-app
```

### 2.4 Unified `restart: unless-stopped` for all services
**What:** All 4 containers (app, nginx, db, redis) now use `unless-stopped`.

**Verify:**
```bash
docker compose ps --format "table {{.Name}}\t{{.Status}}"
# All containers: "Up X seconds"
# After machine reboot (if Docker Desktop auto-start enabled) — all containers come back
```

### 2.5 Redis healthcheck added
**Verify:**
```bash
docker compose ps
# redis container shows "(healthy)" status
```

---

## 3. `.docker/php/Dockerfile`

### 3.1 Single `apt-get` layer with cleanup
**What:** Two separate `RUN apt-get update` merged into one. `apt-get clean && rm -rf /var/lib/apt/lists/*` added.

**Verify:**
```bash
docker images | grep dmed
# Image size should be smaller than before
docker history <image-id> | head -10
# Only one large apt-get layer instead of two
```

### 3.2 Composer version pinned to `2.8`
**What:** `composer:latest` → `composer:2.8`

**Verify:**
```bash
make cli
composer --version
# Should show Composer version 2.8.x
```

### 3.3 Node.js removed
**What:** `curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt-get install nodejs` removed entirely.

**Verify:**
```bash
make cli
node --version
# command not found: node — expected, Node is not needed in this image
```

### 3.4 All Xdebug code removed
**What:** ~30 lines of commented-out Xdebug configuration removed from Dockerfile.

**Verify:**
```bash
grep -i xdebug .docker/php/Dockerfile
# No output — file is clean
```

### 3.5 All comments in English
**Verify:**
```bash
grep -P "[\x{0400}-\x{04FF}]" .docker/php/Dockerfile
# No output — no Cyrillic characters
```

---

## 4. `.docker/nginx/default.conf`

### 4.1 FastCGI buffer sizes corrected
**What:** `128 4096k` (512 MB/request) → `16 16k` (256 KB/request)

**Verify:**
```bash
grep "fastcgi_buffers" .docker/nginx/default.conf
# fastcgi_buffers 16 16k;
```

### 4.2 `proxy_buffer_*` directives removed from FastCGI block
**Verify:**
```bash
grep "proxy_buffer" .docker/nginx/default.conf
# No output
```

### 4.3 `X-XSS-Protection` header removed (deprecated)
**Verify:**
```bash
grep "X-XSS" .docker/nginx/default.conf
# No output
```

### 4.4 Content-Security-Policy tightened
**What:** `unsafe-eval` removed. Added `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`.

**Verify:**
```bash
# After make up-build:
curl -I http://localhost | grep Content-Security-Policy
# Must not contain 'unsafe-eval'
```

### 4.5 Gzip compression added
**Verify:**
```bash
curl -H "Accept-Encoding: gzip" -I http://localhost
# Content-Encoding: gzip
```

### 4.6 Static asset caching headers added
**Verify:**
```bash
curl -I http://localhost/favicon.ico
# Cache-Control: public, immutable
# Expires: <1 year from now>
```

### 4.7 New security headers added
**Verify:**
```bash
curl -I http://localhost
# Referrer-Policy: strict-origin-when-cross-origin
# Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()
```

---

## 5. `.docker/supervisord/supervisord.conf`

### 5.1 PHP-FPM fully configured
**What:** Added `autostart`, `autorestart`, `user=www-data`, `priority=10`, `startretries`, log files.

### 5.2 `--nodaemonize` flag added
**What:** Prevents PHP-FPM from forking to background (Supervisor loses the process otherwise).

### 5.3 `stopwaitsecs=3600` for Horizon
**What:** Allows Horizon up to 1 hour to finish in-flight jobs before force-kill on `make down`.

**Verify all supervisord changes:**
```bash
make up-build
make logs-app
# Should see lines like:
# [program:php-fpm] started
# [program:horizon] started
# NO restart loops for php-fpm

docker compose exec app bash -c "supervisorctl status"
# php-fpm    RUNNING   pid X, uptime 0:00:XX
# horizon    RUNNING   pid X, uptime 0:00:XX
```

---

## 6. `.docker/php/laravel.ini`

### 6.1 OPcache configured
### 6.2 `max_execution_time = 60` added
### 6.3 `date.timezone = UTC` added
### 6.4 `memory_limit` reduced to 256M

**Verify:**
```bash
make cli
php -i | grep -E "opcache.enable|memory_limit|max_execution_time|date.timezone"
# opcache.enable => On => On
# memory_limit => 256M
# max_execution_time => 60
# date.timezone => UTC

php -i | grep "opcache.enable" | head -1
# opcache.enable => On => On
```

---

## 7. `Makefile`

### 7.1 All DB commands now use PostgreSQL (`psql`, `pg_dump`)
**Verify:**
```bash
# With containers running:
make db
# Should open: psql prompt — not "mysql: command not found"
# \q to exit
```

### 7.2 `cache-clear` uses single `docker exec`
**Verify:**
```bash
time make cache-clear
# Should run all 7 artisan commands in one exec call
# Faster than before (no repeated exec overhead)
```

### 7.3 Auto-generated `make help`
**Verify:**
```bash
make help
# All commands shown with descriptions in aligned columns
# Colors work in terminal
```

### 7.4 `make setup` — new one-command initialization
**Verify:**
```bash
make down
make prune-all     # clean slate
cp .env.example .env
# fill DB_PASSWORD in .env
make setup
# Should: build images → wait for DB → composer install → key:generate → migrate
# End: "Setup complete. Application is running at http://localhost"
curl http://localhost
# Should return HTTP 200 (Laravel welcome page or your app)
```

### 7.5 `make logs-redis` added
**Verify:**
```bash
make logs-redis
# Should stream Redis container logs
# Ctrl+C to stop
```

### 7.6 Dead MySQL comments removed
**Verify:**
```bash
grep -c "mysql\|mysqldump" Makefile
# 0
```

---

## 8. `.env.example`

### 8.1 `APP_KEY` cleared
**Verify:**
```bash
grep "APP_KEY=" .env.example
# APP_KEY=    ← empty, no value
```

### 8.2 `REDIS_HOST` inline comment fixed
**Verify:**
```bash
grep "REDIS_HOST=" .env.example
# REDIS_HOST=redis    ← clean value, comment on separate line above
```

### 8.3 SESSION / CACHE / QUEUE → redis
**Verify:**
```bash
grep -E "SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION" .env.example
# SESSION_DRIVER=redis
# CACHE_STORE=redis
# QUEUE_CONNECTION=redis
```

### 8.4 `REDIS_PASSWORD` is empty (not string "null")
**Verify:**
```bash
grep "REDIS_PASSWORD=" .env.example
# REDIS_PASSWORD=
```

### 8.5 `HORIZON_PREFIX` added
**Verify:**
```bash
grep "HORIZON" .env.example
# HORIZON_PREFIX=horizon:
```

### 8.6 `SESSION_DOMAIN` is empty (not string "null")
**Verify:**
```bash
grep "SESSION_DOMAIN=" .env.example
# SESSION_DOMAIN=
```

---

## 9. `.github/workflows/tests.yml`

### 9.1 PostgreSQL service added to CI
### 9.2 `pdo_pgsql` extension added
### 9.3 `fail-fast: false`
### 9.4 Composer dependency cache added
### 9.5 `php artisan test` replaces `vendor/bin/phpunit`
### 9.6 Scheduled cron removed (no external deps to check)

**Verify (requires GitHub push):**
```bash
git add .github/workflows/tests.yml
git commit -m "ci: add PostgreSQL service, cache composer, fix extensions"
git push
# Check GitHub Actions tab — both PHP 8.2 and PHP 8.3 jobs should pass
# Even if one fails, the other still runs (fail-fast: false)
```

**Verify locally (unit tests without DB):**
```bash
make test
# php artisan test — all tests pass
```

---

## 10. `README.md`

### 10.1 Setup simplified to 3 steps + `make setup`
### 10.2 MySQL references removed
### 10.3 Stack table and key .env variables section added

**Verify:**
```bash
# Follow README from scratch on a clean machine — should work without any extra steps
```

---

## 11. `.gitignore`

### 11.1 `/backups/*.sql` added
**Verify:**
```bash
make export-db
git status
# backups/app-*.sql should NOT appear in "Changes not tracked for commit"
```

---

## 12. `composer.json`

### 12.1 `laravel/sail` removed from `require-dev`
**Verify:**
```bash
grep "laravel/sail" composer.json
# No output

# After running composer update inside container:
make cli
composer update
# Sail removed from vendor/
grep "laravel/sail" vendor/composer/installed.json 2>/dev/null || echo "sail not installed"
```

---

---

## Post-fix Bugs Fixed During Testing

### B1. `supervisord.conf` — `user=www-data` for php-fpm caused exit 78
**Root cause:** PHP-FPM master **must** run as root to fork workers into www-data via pool config. Adding `user=www-data` to the Supervisor program caused permission denial.
**Fix:** Removed `user=www-data` from `[program:php-fpm]`. Added `user=root` to `[supervisord]` to suppress CRIT warning.

**Verify:**
```bash
docker compose logs app | grep -E "RUNNING|ERROR|exit status"
# php-fpm  RUNNING   pid X
docker compose exec app supervisorctl status
# php-fpm  RUNNING
```

### B2. Horizon starts before `vendor/` exists (race condition)
**Root cause:** Supervisor auto-starts horizon immediately at container boot, but `composer install` runs later in `make setup`. Horizon crashes with exit 1 after 3 retries → FATAL.
**Fix:** `autostart=false` for horizon in supervisord. `make setup` starts it explicitly after `composer install` via `supervisorctl start horizon`.

**Verify:**
```bash
make setup
# After composer install, you should see no horizon FATAL errors
docker compose exec app supervisorctl status
# php-fpm  RUNNING
# horizon  RUNNING
```

### B3. `expose_php` was leaking PHP version
**What:** `X-Powered-By: PHP/8.3.9` header was visible.
**Fix:** Added `expose_php = Off` to `laravel.ini`.

**Verify:**
```bash
curl -sI http://localhost | grep -i "powered"
# No output — header not present
```

---

## Full Integration Test (from zero)

Run this sequence to validate everything works end-to-end:

```bash
# 1. Clean environment
make down 2>/dev/null; make prune-all 2>/dev/null; true

# 2. Configure
cp .env.example .env
# Edit .env: set DB_PASSWORD to any non-empty value

# 3. One-command setup
make setup
# Expected output:
#   ✓ Containers built and started
#   ✓ Database ready
#   ✓ Composer installed
#   ✓ App key generated
#   ✓ Migrations run
#   "Setup complete. Application is running at http://localhost"

# 4. Verify HTTP
curl -s -o /dev/null -w "%{http_code}" http://localhost
# 200

# 5. Verify security headers
curl -I http://localhost 2>/dev/null | grep -E "X-Frame|X-Content|Content-Security|Referrer|Permissions"
# All 5 headers present, no X-XSS-Protection

# 6. Verify containers health
docker compose ps
# All 4 containers: Up (healthy)

# 7. Verify Supervisor processes
make cli
# Then inside container:
# supervisorctl status
# php-fpm  RUNNING
# horizon  RUNNING

# 8. Verify Redis connection
make cli
# Then inside container:
# php artisan tinker
# Cache::put('test', 'ok', 10);
# Cache::get('test');  // "ok"

# 9. Verify PostgreSQL data persistence
make down
make up
make db
# \dt   — should show Laravel tables (migrations, etc.)

# 10. Verify make help
make help
# All commands listed with descriptions

# 11. Run tests
make test
# All tests pass
```
