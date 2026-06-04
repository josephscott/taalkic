SHELL = /bin/bash
.DEFAULT_GOAL := help

# https://mwop.net/blog/2023-12-11-advent-makefile.html
##@ Help
help:  ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[0-9a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

.PHONY: all
all: style lint analyze tests ## Do everything

##@ Build

install: ## Install composer dependencies
	@echo
	@echo "--> Install: composer"
	composer install
	@echo

.PHONY: classmap
classmap: ## Update composer classmap
	@echo
	@echo "--> classmap: update"
	composer dump-autoload
	@echo

# ### #

##@ Server

.PHONY: start
start: ## Start the server as a background service ( production, no watching )
	@echo
	@echo "--> Server: start ( daemon )"
	php demo/server.php start -d
	@echo

.PHONY: stop
stop: ## Stop the background service
	@echo
	@echo "--> Server: stop"
	php demo/server.php stop
	@echo

.PHONY: restart
restart: ## Restart the background service
	@echo
	@echo "--> Server: restart ( daemon )"
	php demo/server.php restart -d
	@echo

.PHONY: reload
reload: ## Gracefully reload code without dropping requests ( production )
	@echo
	@echo "--> Server: reload ( graceful )"
	@# Workerman recycles the workers one at a time and the reload command
	@# returns before that finishes, so wait until every old worker is gone.
	@# Until then some requests still hit old workers running the old code.
	@master=$$( cat demo/workerman.server.php.pid 2>/dev/null ); \
	old=$$( pgrep -P "$$master" 2>/dev/null ); \
	php demo/server.php reload -g; \
	for pid in $$old; do \
		tries=0; \
		while kill -0 "$$pid" 2>/dev/null && [ $$tries -lt 100 ]; do \
			sleep 0.1; \
			tries=$$(( tries + 1 )); \
		done; \
	done
	@echo

.PHONY: status
status: ## Show the server status
	@echo
	@echo "--> Server: status"
	php demo/server.php status
	@echo

# ### #

##@ Quality

.PHONY: style
style: ## Fix any style issues
	@echo
	@echo "--> Style: php-cs-fixer"
	@# Filter only the composer.json-minimum-PHP-version warning from stderr; let
	@# real warnings/errors through. php-cs-fixer 3.95 has no config switch for it.
	@vendor/bin/php-cs-fixer fix -v 2> >( grep -v -e 'running PHP CS Fixer on PHP' -e 'If you need help while solving warnings' >&2 )
	@echo

.PHONY: lint
lint: ## Check if the code is valid
	@echo
	@echo "--> Lint"
	find src tests demo -name "*.php" -exec php -l {} \;
	@echo

.PHONY: analyze
analyze: ## Static analysis
	@echo
	@echo "--> PHPStan"
	vendor/bin/phpstan analyse --memory-limit=512M
	@echo

.PHONY: tests
tests: ## Run tests
	@echo
	@echo "--> Tests: Pest"
	@echo
	./vendor/bin/pest
	@echo

# ### #
