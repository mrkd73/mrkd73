.PHONY: help setup db start lint test clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: ## Download WordPress core, configure it, and install the site
	@bash scripts/setup.sh

db: ## Start MariaDB and ensure the WordPress database/user exist
	@bash scripts/db-setup.sh

start: ## Run the WordPress development server (PHP built-in server)
	@bash scripts/start.sh

lint: ## Run PHP syntax checks + core checksum verification
	@bash scripts/lint.sh

test: ## Smoke test: WordPress installed + homepage returns HTTP 200
	@bash scripts/test.sh

clean: ## Remove downloaded WordPress core (keeps .env and scripts)
	@rm -rf wordpress && echo "Removed wordpress/ (run 'make setup' to recreate)"
