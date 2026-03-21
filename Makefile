.PHONY: install start stop restart logs update shell migrate fresh test lint lint-fix analyse quality ci bridge

# Helper — all php/composer/artisan commands inside the app container must
# override disable_functions because security.ini blocks proc_open (used by
# Symfony Process in composer scripts, artisan test, pint, phpstan, etc.).
PHP = php -d disable_functions=""

# First-time setup
install:
	@cp -n .env.example .env 2>/dev/null || true
	docker compose up -d --build
	docker compose exec app $(PHP) /usr/bin/composer install --no-interaction --optimize-autoloader
	docker compose exec app npm install
	docker compose exec app $(PHP) artisan app:install

# Start services
start:
	docker compose up -d

# Stop services
stop:
	docker compose down

# Restart services
restart:
	docker compose restart

# Tail logs
logs:
	docker compose logs -f app horizon scheduler

# Update to latest version
update:
	git pull
	docker compose up -d --build
	docker compose exec app $(PHP) /usr/bin/composer install --no-interaction --optimize-autoloader
	docker compose exec app npm install
	docker compose exec app $(PHP) artisan migrate --force
	docker compose exec app $(PHP) artisan config:clear
	docker compose exec app $(PHP) artisan view:clear

# Open a shell in the app container
shell:
	docker compose exec app sh

# Run migrations
migrate:
	docker compose exec app $(PHP) artisan migrate

# Fresh database (destructive)
fresh:
	docker compose exec app $(PHP) artisan migrate:fresh
	docker compose exec app $(PHP) artisan app:install --force

# Run tests
test:
	docker compose exec app $(PHP) artisan test

# Code style check
lint:
	docker compose exec app $(PHP) vendor/bin/pint --test

# Code style fix
lint-fix:
	docker compose exec app $(PHP) vendor/bin/pint

# Static analysis
analyse:
	docker compose exec app $(PHP) vendor/bin/phpstan analyse --memory-limit=512M

# Full quality check (lint + analyse)
quality: lint analyse

# Full CI check (quality + tests)
ci: quality test

# Start the host agent bridge (run on host machine, not in Docker)
# Auto-detects PHP or Python 3, auto-generates secret if needed
bridge:
	@sh docker/start-bridge.sh
