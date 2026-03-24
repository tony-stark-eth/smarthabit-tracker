.PHONY: help up down build logs shell quality ecs ecs-check phpstan rector rector-check test test-unit test-integration infection infection-fast i18n frontend-install frontend-dev frontend-build frontend-check frontend-lint db-migrate db-diff db-reset tofu-init tofu-plan tofu-apply tofu-destroy deploy-init deploy deploy-rollback deploy-logs deploy-destroy

help:                 ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ── Docker ──────────────────────────────────────────────
up:                   ## Start Docker Compose (dev)
	docker compose --profile dev up -d

down:                 ## Stop Docker Compose
	docker compose --profile dev down

build:                ## Rebuild Docker images
	docker compose build --pull --no-cache

logs:                 ## Follow all service logs
	docker compose logs -f

shell:                ## Shell into PHP container
	docker compose exec php sh

# ── Backend Quality ─────────────────────────────────────
quality:              ## Run all backend checks locally
	@$(MAKE) ecs-check phpstan rector-check test infection

hooks:                ## Install git hooks (CaptainHook) — run once after cloning
	cd backend && vendor/bin/captainhook install --force --skip-existing

ecs:                  ## Coding standard (fix)
	cd backend && vendor/bin/ecs check --fix

ecs-check:            ## Coding standard (check only)
	cd backend && vendor/bin/ecs check

phpstan:              ## Static analysis
	cd backend && vendor/bin/phpstan analyse

rector:               ## Rector auto-fix
	cd backend && vendor/bin/rector process

rector-check:         ## Rector dry-run
	cd backend && vendor/bin/rector process --dry-run

# ── Backend Tests ───────────────────────────────────────
test:                 ## PHPUnit (all suites)
	cd backend && vendor/bin/phpunit

test-unit:            ## Unit tests with coverage
	cd backend && vendor/bin/phpunit --testsuite=unit --coverage-html=var/coverage

test-integration:     ## Integration tests only
	cd backend && vendor/bin/phpunit --testsuite=integration

infection:            ## Mutation testing
	cd backend && vendor/bin/infection --threads=4 --show-mutations

infection-fast:       ## Mutation testing (changed files only)
	cd backend && vendor/bin/infection --threads=4 --git-diff-filter=AM --git-diff-base=origin/main

# ── Frontend ────────────────────────────────────────────
frontend-install:     ## Bun install
	cd frontend && bun install

i18n:                 ## Generate frontend i18n from Symfony YAML translations
	cd frontend && bun run i18n:generate

frontend-dev:         ## SvelteKit dev server
	cd frontend && bun run dev

frontend-build: i18n  ## SvelteKit production build (auto-generates i18n)
	cd frontend && bun run build

frontend-check:       ## TypeScript + Svelte check
	cd frontend && bun run check

frontend-lint:        ## ESLint
	cd frontend && bun run lint

e2e:                  ## E2E tests (requires docker compose --profile dev up -d)
	cd frontend && bun run test:e2e

e2e-ui:               ## E2E tests with Playwright UI
	cd frontend && bun run test:e2e:ui

# ── Database ────────────────────────────────────────────
db-migrate:           ## Run Doctrine migrations
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

db-diff:              ## Generate migration diff
	docker compose exec php php bin/console doctrine:migrations:diff

db-reset:             ## Drop + create + migrate database
	docker compose exec php php bin/console doctrine:database:drop --force --if-exists
	docker compose exec php php bin/console doctrine:database:create
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# ── Infrastructure ──────────────────────────────────────
tofu-init:            ## OpenTofu init
	cd infrastructure && tofu init

tofu-plan:            ## OpenTofu plan (dry-run)
	cd infrastructure && tofu plan

tofu-apply:           ## OpenTofu apply (WARNING: creates real resources!)
	cd infrastructure && tofu apply

tofu-destroy:         ## OpenTofu destroy (removes all cloud resources, stops billing)
	cd infrastructure && tofu destroy

# ── Production Deployment ─────────────────────────────
PROD_COMPOSE = docker compose -f compose.yaml -f compose.prod.yaml
DEPLOY_HOST  ?= $(shell grep DEPLOY_HOST .env 2>/dev/null | cut -d= -f2)

deploy-init:          ## First-time server setup (run locally, SSHs into server)
	@echo "── Provisioning infrastructure ──"
	cd infrastructure && tofu init && tofu apply
	@echo ""
	@echo "── Setting up server ──"
	ssh $(DEPLOY_HOST) '\
		apt-get update && apt-get install -y docker.io docker-compose-plugin && \
		systemctl enable docker && \
		mkdir -p /opt/smarthabit'
	@echo ""
	@echo "── Cloning repo on server ──"
	ssh $(DEPLOY_HOST) '\
		git clone https://github.com/tony-stark-eth/smarthabit-tracker /opt/smarthabit || \
		(cd /opt/smarthabit && git pull)'
	@echo ""
	@echo "── Copy .env to server (edit secrets first!) ──"
	scp .env.example $(DEPLOY_HOST):/opt/smarthabit/.env
	@echo ""
	@echo "Done. Next steps:"
	@echo "  1. ssh $(DEPLOY_HOST)"
	@echo "  2. Edit /opt/smarthabit/.env (DATABASE_URL, JWT_PASSPHRASE, VAPID keys)"
	@echo "  3. make deploy"

deploy:               ## Deploy latest main to production
	ssh $(DEPLOY_HOST) '\
		cd /opt/smarthabit && \
		git pull && \
		docker compose -f compose.yaml -f compose.prod.yaml build --pull && \
		docker compose -f compose.yaml -f compose.prod.yaml up -d --wait --wait-timeout 120'
	@echo "Deploy complete."

deploy-rollback:      ## Rollback to previous image (usage: make deploy-rollback TAG=abc1234)
	ssh $(DEPLOY_HOST) '\
		cd /opt/smarthabit && \
		docker pull ghcr.io/tony-stark-eth/smarthabit-tracker/app:$(or $(TAG),latest) && \
		docker tag ghcr.io/tony-stark-eth/smarthabit-tracker/app:$(or $(TAG),latest) app-php:prod && \
		docker compose -f compose.yaml -f compose.prod.yaml up -d --no-build --wait --wait-timeout 120'
	@echo "Rollback complete."

deploy-logs:          ## Follow production logs
	ssh $(DEPLOY_HOST) 'cd /opt/smarthabit && docker compose -f compose.yaml -f compose.prod.yaml logs -f'

deploy-destroy:       ## Tear down everything (server + infra, stops all billing)
	@echo "This will DESTROY the production server and all data."
	@read -p "Type 'yes' to confirm: " confirm && [ "$$confirm" = "yes" ] || exit 1
	ssh $(DEPLOY_HOST) 'cd /opt/smarthabit && docker compose -f compose.yaml -f compose.prod.yaml down -v' || true
	cd infrastructure && tofu destroy
	@echo "All resources destroyed. Billing stopped."
