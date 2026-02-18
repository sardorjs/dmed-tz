include .env
default: up

#####################################################
#                                                   #
#               ARGUMENTS / VARIABLES               #
#                                                   #
#####################################################

# Current UTC timestamp — used for backup filenames
TIMESTAMP = $(shell date -u +%Y-%m-%d_%H-%M-%S)

# Latest backup file from the backups/ directory
LAST_SQL_BACKUP_FILE = $(shell ls -t backups/*.sql 2>/dev/null | head -n 1)

.PHONY: up up-build up-force down down-v-prune setup \
        cli db db-su db-grant-privileges \
        df prune-all \
        logs-nginx logs-app logs-db logs-redis logs-minio restart \
        import-db export-db last-sql-file \
        cache-clear config-clear \
        migrate seed migrate-seed test \
        minio-setup help

#####################################################
#                                                   #
#                     Docker                        #
#                                                   #
#####################################################

up: ## Start all containers in detached mode
	docker compose up -d

up-build: ## Build images and start all containers
	docker compose up -d --build

up-force: ## Force-rebuild images and start all containers
	docker compose up -d --build --force-recreate

down: ## Stop and remove all containers
	docker compose down

down-v-prune: ## Stop containers and remove all associated volumes
	docker compose down --volumes

setup: ## Full project init: build, install deps, generate key, migrate, start horizon
	docker compose up -d --build
	@echo "Waiting for database to be ready..."
	@until docker compose exec db pg_isready -U $(DB_USERNAME) -d $(DB_DATABASE) > /dev/null 2>&1; do \
		echo "  database unavailable — retrying in 2s..."; \
		sleep 2; \
	done
	@echo "Database is ready."
	docker compose exec app composer install
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan storage:link --force
	docker compose exec app php artisan migrate
	$(MAKE) minio-setup
	docker compose exec app supervisorctl start horizon
	@echo ""
	@echo "Setup complete. Application is running at http://localhost"
	@echo "Horizon dashboard: http://localhost/horizon"

cli: ## Open an interactive bash shell inside the app container
	docker compose exec -it app bash -c "echo 'alias ll=\"ls -lah\"' >> ~/.bashrc && bash"

df: ## Show Docker disk usage
	docker system df

prune-all: ## Remove all Docker images, containers, volumes and build cache
	docker image prune -af
	docker system prune -af
	docker volume prune -af
	docker builder prune -af
	docker system df

#####################################################
#                                                   #
#                     Logging                       #
#                                                   #
#####################################################

logs-nginx: ## Tail Nginx container logs
	docker compose logs -f nginx

logs-app: ## Tail app (PHP-FPM) container logs
	docker compose logs -f app

logs-db: ## Tail database container logs
	docker compose logs -f db

logs-redis: ## Tail Redis container logs
	docker compose logs -f redis

logs-minio: ## Tail MinIO container logs
	docker compose logs -f minio

restart: ## Restart all containers
	docker compose restart

#####################################################
#                                                   #
#                    Database                       #
#                                                   #
#####################################################

db: ## Connect to PostgreSQL as the project user
	docker compose exec -it db psql -U $(DB_USERNAME) -d $(DB_DATABASE)

db-su: ## Connect to PostgreSQL as the postgres superuser
	docker compose exec -it db psql -U postgres

db-grant-privileges: ## Grant all privileges to the project user (run as superuser)
	docker compose exec -it db psql -U postgres -c \
		"GRANT ALL PRIVILEGES ON DATABASE $(DB_DATABASE) TO $(DB_USERNAME); GRANT ALL ON SCHEMA public TO $(DB_USERNAME);"

last-sql-file: ## Print the name of the latest SQL backup file
	@echo $(LAST_SQL_BACKUP_FILE)

export-db: ## Dump the database to backups/app-<timestamp>.sql
	docker compose exec db pg_dump -U $(DB_USERNAME) $(DB_DATABASE) > "backups/app-$(TIMESTAMP).sql"
	@echo "Backup saved to backups/app-$(TIMESTAMP).sql"

import-db: ## Restore the latest SQL backup from the backups/ directory
	@echo "Importing $(LAST_SQL_BACKUP_FILE) ..."
	cat $(LAST_SQL_BACKUP_FILE) | docker compose exec -T db psql -U $(DB_USERNAME) -d $(DB_DATABASE)
	@echo "Done."

#####################################################
#                                                   #
#                   Application                     #
#                                                   #
#####################################################

cache-clear: ## Clear all Laravel caches and rebuild config cache
	docker compose exec app bash -c "\
		php artisan cache:clear && \
		php artisan config:clear && \
		php artisan event:clear && \
		php artisan route:clear && \
		php artisan view:clear && \
		php artisan schedule:clear-cache && \
		php artisan config:cache"

config-clear: ## Clear and rebuild only the config cache
	docker compose exec app bash -c "php artisan config:clear && php artisan config:cache"

migrate: ## Run database migrations
	docker compose exec app bash -c "php artisan migrate"

seed: ## Run database seeders
	docker compose exec app bash -c "php artisan db:seed"

migrate-seed: ## Run migrations and seeders together
	docker compose exec app bash -c "php artisan migrate --seed"

test: ## Run the test suite
	docker compose exec app bash -c "php artisan test"

#####################################################
#                                                   #
#                      MinIO                        #
#                                                   #
#####################################################

minio-setup: ## Create MinIO bucket (AWS_BUCKET) if it does not exist
	@NETWORK=$$(docker inspect $(APP_SHORT_NAME)-minio \
		--format='{{range $$k,$$v := .NetworkSettings.Networks}}{{$$k}}{{end}}' 2>/dev/null); \
	docker run --rm --network "$$NETWORK" --entrypoint sh \
		minio/mc:latest \
		-c "mc alias set minio http://minio:9000 $(MINIO_ROOT_USER) $(MINIO_ROOT_PASSWORD) \
			&& mc mb --ignore-existing minio/$(AWS_BUCKET)"
	@echo "MinIO bucket '$(AWS_BUCKET)' is ready."

#####################################################
#                                                   #
#                      Help                         #
#                                                   #
#####################################################

help: ## Show this help message
	@echo "Available commands:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'
