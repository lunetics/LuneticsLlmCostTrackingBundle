.PHONY: install test test-coverage phpstan cs-check cs-fix ci

PHP_VERSION ?= 8.3
DOCKER_RUN = docker run --rm -v $$(pwd):/app -w /app
PHP = $(DOCKER_RUN) php:$(PHP_VERSION)-cli php
COMPOSER = $(DOCKER_RUN) composer:latest

install: ## Install dependencies
	$(COMPOSER) install

test: ## Run PHPUnit tests
	$(PHP) vendor/bin/phpunit

test-coverage: ## Run PHPUnit tests with pcov coverage report (requires: docker compose build)
	docker compose run --rm php vendor/bin/phpunit --coverage-text

phpstan: ## Run PHPStan static analysis
	$(PHP) vendor/bin/phpstan analyse

cs-check: ## Check coding standards (dry-run)
	$(PHP) vendor/bin/php-cs-fixer check

cs-fix: ## Fix coding standards
	$(PHP) vendor/bin/php-cs-fixer fix

ci: cs-check phpstan test ## Run all CI checks
