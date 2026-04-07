.DEFAULT_GOAL := help

COMPOSE       := docker compose -f infra/docker/docker-compose.yml --env-file infra/docker/.env
COMPOSE_LOCAL := $(COMPOSE) -f infra/docker/docker-compose.override.yml
COMPOSE_PROD  := $(COMPOSE)

# ──────────────────────────────────────────────
# Docker Compose
# ──────────────────────────────────────────────

.PHONY: build
build: ## Build local dev images (with override — default)
	$(COMPOSE_LOCAL) build

.PHONY: build-prod
build-prod: ## Build production images (no override)
	$(COMPOSE_PROD) build

.PHONY: up
up: ## Start all services in local dev mode (detached)
	$(COMPOSE_LOCAL) up -d

.PHONY: up-prod
up-prod: ## Start all services in production mode (detached)
	$(COMPOSE_PROD) up -d

.PHONY: down
down: ## Stop and remove containers
	$(COMPOSE_LOCAL) down

.PHONY: reset
reset: ## Destroy all volumes and restart from scratch (local dev)
	$(COMPOSE_LOCAL) down -v
	$(COMPOSE_LOCAL) up -d

.PHONY: ps
ps: ## Show running containers
	$(COMPOSE_LOCAL) ps

.PHONY: logs
logs: ## Tail logs (SERVICE=<name> to filter)
	$(COMPOSE_LOCAL) logs -f $(SERVICE)

# ──────────────────────────────────────────────
# Per-service commands
# Usage: make test SERVICE=merchant-api
# ──────────────────────────────────────────────

.PHONY: test
test: ## Run PHPUnit tests for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make test SERVICE=<service-name>"; exit 1)
	$(COMPOSE_LOCAL) exec $(SERVICE) php artisan test

.PHONY: stan
stan: ## Run PHPStan analysis for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make stan SERVICE=<service-name>"; exit 1)
	$(COMPOSE_LOCAL) exec $(SERVICE) ./vendor/bin/phpstan analyse

.PHONY: pint
pint: ## Run Laravel Pint code style fixer for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make pint SERVICE=<service-name>"; exit 1)
	$(COMPOSE_LOCAL) exec $(SERVICE) ./vendor/bin/pint

.PHONY: tinker
tinker: ## Open Laravel Tinker REPL for SERVICE
	@test -n "$(SERVICE)" || (echo "Usage: make tinker SERVICE=<service-name>"; exit 1)
	$(COMPOSE_LOCAL) exec $(SERVICE) php artisan tinker

.PHONY: migrate
migrate: ## Run migrations (SERVICE=<name> for one service, omit for all)
	@if [ -n "$(SERVICE)" ]; then \
		$(COMPOSE_LOCAL) exec $(SERVICE) php artisan migrate --force; \
	else \
		for svc in merchant-api payment-domain payment-orchestrator provider-gateway \
		           webhook-ingest webhook-normalizer ledger-service \
		           merchant-callback-delivery reporting-projection; do \
			$(COMPOSE_LOCAL) exec $$svc php artisan migrate --force; \
		done; \
	fi

.PHONY: seed
seed: ## Run database seeders (SERVICE=<name> for one service, omit for all)
	@if [ -n "$(SERVICE)" ]; then \
		$(COMPOSE_LOCAL) exec $(SERVICE) php artisan db:seed --force; \
	else \
		for svc in merchant-api payment-domain payment-orchestrator provider-gateway \
		           webhook-ingest webhook-normalizer ledger-service \
		           merchant-callback-delivery reporting-projection; do \
			$(COMPOSE_LOCAL) exec $$svc php artisan db:seed --force; \
		done; \
	fi

.PHONY: shell
shell: ## Open a shell in SERVICE container
	@test -n "$(SERVICE)" || (echo "Usage: make shell SERVICE=<service-name>"; exit 1)
	$(COMPOSE_LOCAL) exec $(SERVICE) sh

# ──────────────────────────────────────────────
# Help
# ──────────────────────────────────────────────

.PHONY: help
help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'
