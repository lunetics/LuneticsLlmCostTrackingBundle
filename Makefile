.PHONY: install test test-coverage phpstan cs-check cs-fix ci release-patch release-minor release-major

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

update-snapshot: ## Regenerate resources/pricing_snapshot.json from models.dev
	$(PHP) bin/generate_snapshot.php

# --- Release targets ---

LATEST_TAG = $(shell git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0")
MAJOR = $(shell echo '$(LATEST_TAG)' | sed 's/^v//' | cut -d. -f1)
MINOR = $(shell echo '$(LATEST_TAG)' | sed 's/^v//' | cut -d. -f2)
PATCH = $(shell echo '$(LATEST_TAG)' | sed 's/^v//' | cut -d. -f3)

define check_release_ready
	@if [ "$$(git rev-parse --abbrev-ref HEAD)" != "main" ]; then \
		echo "Error: must be on main branch (current: $$(git rev-parse --abbrev-ref HEAD))"; exit 1; \
	fi
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "Error: working tree is not clean"; exit 1; \
	fi
	@git fetch origin main --quiet
	@if [ "$$(git rev-parse HEAD)" != "$$(git rev-parse origin/main)" ]; then \
		echo "Error: local main is not up to date with origin"; exit 1; \
	fi
endef

define do_release
	@echo "Tagging $(1) (was $(LATEST_TAG))"
	git tag -a $(1) -m "Release $(1)"
	git push origin $(1)
endef

release-patch: ## Release a patch version (0.1.0 → 0.1.1)
	$(check_release_ready)
	$(eval NEXT := v$(MAJOR).$(MINOR).$(shell echo $$(($(PATCH)+1))))
	$(call do_release,$(NEXT))

release-minor: ## Release a minor version (0.1.0 → 0.2.0)
	$(check_release_ready)
	$(eval NEXT := v$(MAJOR).$(shell echo $$(($(MINOR)+1))).0)
	$(call do_release,$(NEXT))

release-major: ## Release a major version (0.1.0 → 1.0.0)
	$(check_release_ready)
	$(eval NEXT := v$(shell echo $$(($(MAJOR)+1))).0.0)
	$(call do_release,$(NEXT))
