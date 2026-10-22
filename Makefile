COMPOSE  = docker compose
CONSOLE  = $(COMPOSE) exec auth-server php bin/console

.PHONY: help secrets up down logs user import 2fa-status role impersonations client client-policy resource-type domain access access-import purge test

help: ## List the targets
	@grep -E '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-14s %s\n", $$1, $$2}'

secrets: ## Fill the empty secrets of .env with random values
	@test -f .env || cp .env.example .env
	@for v in APP_SECRET MYSQL_PASSWORD MYSQL_ROOT_PASSWORD OAUTH_ENCRYPTION_KEY WEBHOOK_SECRET; do \
		grep -q "^$$v=$$" .env && sed -i.bak "s|^$$v=$$|$$v=$$(openssl rand -hex 32)|" .env || true; \
	done
	@grep -q '^TOTP_ENCRYPTION_KEY=$$' .env && sed -i.bak "s|^TOTP_ENCRYPTION_KEY=$$|TOTP_ENCRYPTION_KEY=$$(openssl rand -base64 32)|" .env || true
	@rm -f .env.bak
	@echo "Secrets written to .env (keep this file out of version control)."

up: ## Build and start the stack
	$(COMPOSE) up -d --build

down: ## Stop the stack
	$(COMPOSE) down

logs: ## Follow the auth server logs
	$(COMPOSE) logs -f auth-server

user: ## Create a user: make user USERNAME=alice EXTERNAL_ID=42
	$(CONSOLE) app:user:create $(USERNAME) $(EXTERNAL_ID)

import: ## Import existing users: make import FILE=users.csv (username,external_user_id,password_hash; FLAG=--dry-run)
	$(COMPOSE) cp $(FILE) auth-server:/tmp/users-import.csv
	$(CONSOLE) app:user:import /tmp/users-import.csv $(FLAG); status=$$?; \
		$(COMPOSE) exec auth-server rm -f /tmp/users-import.csv; exit $$status

2fa-status: ## 2FA roll-out: who has it, who postpones (FLAG=--pending to list them)
	$(CONSOLE) app:2fa:status $(FLAG)

role: ## Grant a role: make role USERNAME=carla ROLE=ROLE_ADMIN (add FLAG=--remove or FLAG=--transfer)
	$(CONSOLE) app:user:role $(USERNAME) $(ROLE) $(FLAG)

impersonations: ## List impersonation sessions (audit); end one with FLAG=--end=<id>
	$(CONSOLE) app:impersonation $(FLAG)

client: ## Create a public SPA client: make client ID=my-spa REDIRECT=http://localhost:5173/callback
	$(CONSOLE) league:oauth2-server:create-client "$(ID)" $(ID) --public \
		--redirect-uri=$(REDIRECT) --grant-type=authorization_code --grant-type=refresh_token \
		--scope=profile --scope=offline_access $(SCOPES)

client-policy: ## make client-policy ID=my-spa FLAG=--require-dpop (--allow-bearer, '--impersonation-uri=https://…?user={user}')
	$(CONSOLE) app:client:policy $(ID) $(FLAG)

resource-type: ## List resource types, or declare one: make resource-type CODE=vehicle LABEL=Vehicles (FLAG=--remove)
	$(CONSOLE) app:resource-type $(CODE) $(if $(LABEL),"$(LABEL)") $(FLAG)

domain: ## Domain tree; make domain CODE=a10 FLAG='--create="Region 10" --parent=a1' or FLAG='--owner=alice --owner-rights="*:m @access:w"'
	$(CONSOLE) app:domain $(CODE) $(FLAG)

access: ## A user's rights: make access USERNAME=bob [DOMAIN=a10 RIGHTS="*:r vehicle:w" FLAG=--subdomains] (FLAG=--revoke, --move=a10)
	$(CONSOLE) app:access $(USERNAME) $(DOMAIN) $(if $(RIGHTS),"$(RIGHTS)") $(FLAG)

access-import: ## Import domains and rights: make access-import FILE=access.json (FLAG=--dry-run)
	$(COMPOSE) cp $(FILE) auth-server:/tmp/access-import.json
	$(CONSOLE) app:access:import /tmp/access-import.json $(FLAG); status=$$?; \
		$(COMPOSE) exec auth-server rm -f /tmp/access-import.json; exit $$status

purge: ## Remove expired tokens and DPoP replay entries (schedule it, e.g. hourly)
	$(CONSOLE) league:oauth2-server:clear-expired-tokens
	$(CONSOLE) app:purge

test: ## Run the server test suite locally (needs PHP 8.3+ and composer)
	cd server && composer install -q && vendor/bin/phpunit
