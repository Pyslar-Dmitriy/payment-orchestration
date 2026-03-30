.DEFAULT_GOAL := help

# ──────────────────────────────────────────────
# Docker Compose
# ──────────────────────────────────────────────

.PHONY: up
up: ## Start all services (detached)
	docker compose up -d

.PHONY: down
down: ## Stop and remove containers
	docker compose down

.PHONY: ps
ps: ## Show running containers
	docker compose ps

.PHONY: logs
logs: ## Tail logs (SERVICE=<name> to filter)
	docker compose logs -f $(SERVICE)

# ──────────────────────────────────────────────
# Per-service commands
# Usage: make test SERVICE=merchant-api
# ──────────────────────────────────────────────

.PHONY: test
test: ## Run PHPUnit tests for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make test SERVICE=<service-name>"; exit 1)
	docker compose exec $(SERVICE) php artisan test

.PHONY: stan
stan: ## Run PHPStan analysis for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make stan SERVICE=<service-name>"; exit 1)
	docker compose exec $(SERVICE) ./vendor/bin/phpstan analyse

.PHONY: pint
pint: ## Run Laravel Pint code style fixer for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make pint SERVICE=<service-name>"; exit 1)
	docker compose exec $(SERVICE) ./vendor/bin/pint

.PHONY: tinker
tinker: ## Open Laravel Tinker REPL for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make tinker SERVICE=<service-name>"; exit 1)
	docker compose exec $(SERVICE) php artisan tinker

# ──────────────────────────────────────────────
# Help
# ──────────────────────────────────────────────

.PHONY: help
help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'
