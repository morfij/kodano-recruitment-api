COMPOSE = docker compose
PHP = $(COMPOSE) exec php

.DEFAULT_GOAL := help
.PHONY: help up down install migrate fixtures test stan cs cs-fix qa sh

help: ## List available targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-10s %s\n", $$1, $$2}'

up: ## Start the containers
	$(COMPOSE) up -d --wait

down: ## Stop the containers
	$(COMPOSE) down

install: ## Install PHP dependencies
	$(PHP) composer install

migrate: ## Apply database migrations
	$(PHP) bin/console doctrine:migrations:migrate -n

fixtures: ## Replace the database content with demo data
	$(PHP) bin/console doctrine:fixtures:load -n

test: ## Run PHPUnit against the test database
	$(PHP) composer test

stan: ## Run PHPStan
	$(PHP) composer stan

cs: ## Check coding style
	$(PHP) composer cs

cs-fix: ## Fix coding style
	$(PHP) composer cs-fix

qa: stan cs test ## Run static analysis, style check and tests

sh: ## Open a shell in the PHP container
	$(PHP) bash
