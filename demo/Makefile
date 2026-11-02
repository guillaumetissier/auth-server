COMPOSE = docker compose

.PHONY: help up seed down logs

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-8s %s\n", $$1, $$2}'

up: ## Build and start the SPA (port 5173) and the API (port 8081)
	$(COMPOSE) up -d --build --wait

seed: ## Create the demo client, users, domains and rights in the auth server
	./seed.sh

down: ## Stop the demo
	$(COMPOSE) down

logs: ## Follow the logs of the API and the SPA
	$(COMPOSE) logs -f
