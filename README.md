# dmed-tz — Laravel 11 API

## Stack

| Component   | Version       |
|-------------|---------------|
| PHP         | 8.3 (FPM)     |
| Laravel     | 11.x          |
| PostgreSQL  | 16.4          |
| Redis       | 7.4.1         |
| Nginx       | 1.26.2        |
| Supervisor  | system        |

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose plugin)
- Git

---

## Quick Start

```bash
# 1. Clone
git clone https://github.com/sardorjs/dmed-tz.git
cd dmed-tz

# 2. Configure environment
cp .env.example .env
# Open .env and fill in at minimum:
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 3. Build, start and initialise (one command)
make setup
```

After `make setup` the application is available at **http://localhost**.
Horizon dashboard is available at **http://localhost/horizon**.

---

## Key .env Variables

| Variable | Description |
|---|---|
| `APP_KEY` | Generated automatically by `make setup` |
| `DB_DATABASE` | PostgreSQL database name |
| `DB_USERNAME` | PostgreSQL user (becomes database owner) |
| `DB_PASSWORD` | PostgreSQL password |
| `REDIS_HOST` | Redis container name (default: `redis`) |
| `SESSION_DRIVER` | `redis` (recommended, already set) |
| `CACHE_STORE` | `redis` (recommended, already set) |
| `QUEUE_CONNECTION` | `redis` (recommended, already set) |

---

## Common Commands

```bash
make help            # List all available commands

make up              # Start containers
make down            # Stop containers
make restart         # Restart all containers

make cli             # Shell into the app container
make db              # psql shell as project user
make db-su           # psql shell as postgres superuser

make migrate         # Run migrations
make seed            # Run seeders
make migrate-seed    # Migrate + seed
make test            # Run test suite

make cache-clear     # Clear all caches (route, view, config, etc.)
make config-clear    # Clear and rebuild config cache

make export-db       # Dump database → backups/app-<timestamp>.sql
make import-db       # Restore latest SQL file from backups/

make logs-app        # Tail app container logs
make logs-nginx      # Tail Nginx logs
make logs-db         # Tail PostgreSQL logs
make logs-redis      # Tail Redis logs

make prune-all       # Full Docker cleanup (images, volumes, cache)
```

---

## Troubleshooting

### Docker Desktop is not running

```
error during connect: Get "http://%2F%2F.%2Fpipe%2FdockerDesktopLinuxEngine/...
```

Launch **Docker Desktop** and try again.

---

### Build context is too large / slow build

If the build transfers hundreds of MB, ensure `.dockerignore` exists in the project root.
Delete `node_modules/` and `vendor/` before building if they were created outside Docker:

```bash
rm -rf node_modules vendor
make up-build
```

---

### Composer issues inside the container

```bash
make cli
cd /root/.composer
rm -rf cache
# Then retry:
composer install
```

---

### PostgreSQL permission errors

If migrations fail with permission denied:

```bash
make db-grant-privileges
make migrate
```

---

### Containers rebuild required

```bash
make up-force
```
