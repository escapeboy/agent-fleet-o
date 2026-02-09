.PHONY: install start stop restart logs update shell migrate fresh test

# First-time setup
install:
	@cp -n .env.example .env 2>/dev/null || true
	docker compose up -d --build
	docker compose exec app composer install --no-interaction --optimize-autoloader
	docker compose exec app npm install
	docker compose exec app php artisan app:install

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
	docker compose exec app composer install --no-interaction --optimize-autoloader
	docker compose exec app npm install
	docker compose exec app php artisan migrate --force
	docker compose exec app php artisan config:clear
	docker compose exec app php artisan view:clear

# Open a shell in the app container
shell:
	docker compose exec app sh

# Run migrations
migrate:
	docker compose exec app php artisan migrate

# Fresh database (destructive)
fresh:
	docker compose exec app php artisan migrate:fresh
	docker compose exec app php artisan app:install --force

# Run tests
test:
	docker compose exec app php artisan test
