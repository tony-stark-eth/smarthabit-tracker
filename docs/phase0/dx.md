# Developer Experience — Makefile, Git Hooks, Editor Config

## Makefile

```makefile
.PHONY: help up down build quality test

help:                 ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ── Docker ──────────────────────────────────────────────
up:                   ## Start Docker Compose (dev)
	docker compose --profile dev up -d

down:                 ## Stop Docker Compose
	docker compose --profile dev down

build:                ## Rebuild Docker images
	docker compose build --pull --no-cache

logs:                 ## Follow logs of all services
	docker compose logs -f

shell:                ## Shell into the PHP container
	docker compose exec php sh

# ── Backend Quality ─────────────────────────────────────
quality:              ## Run all backend checks locally
	@$(MAKE) ecs-check phpstan rector-check test infection

ecs:                  ## Coding standard (fix)
	cd backend && vendor/bin/ecs check --fix

ecs-check:            ## Coding standard (check)
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

test-unit:            ## Unit tests only with coverage
	cd backend && vendor/bin/phpunit --testsuite=unit --coverage-html=var/coverage

test-integration:     ## Integration tests only
	cd backend && vendor/bin/phpunit --testsuite=integration

infection:            ## Mutation testing
	cd backend && vendor/bin/infection --threads=4 --show-mutations

infection-fast:       ## Infection on changed files only
	cd backend && vendor/bin/infection --threads=4 --git-diff-filter=AM --git-diff-base=origin/main

# ── Frontend ────────────────────────────────────────────
frontend-install:     ## Bun install
	cd frontend && bun install

frontend-dev:         ## SvelteKit dev server
	cd frontend && bun run dev

frontend-build:       ## SvelteKit production build
	cd frontend && bun run build

frontend-check:       ## TypeScript + Svelte check
	cd frontend && bun run check

frontend-lint:        ## ESLint
	cd frontend && bun run lint

# ── Database ────────────────────────────────────────────
db-migrate:           ## Run Doctrine migrations
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

db-diff:              ## Generate migration diff
	docker compose exec php php bin/console doctrine:migrations:diff

db-reset:             ## Drop DB + recreate + migrate
	docker compose exec php php bin/console doctrine:database:drop --force --if-exists
	docker compose exec php php bin/console doctrine:database:create
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

## Git Hooks — CaptainHook

CaptainHook manages Git hooks. Installation requires running `make hooks` manually (the Composer plugin only works in non-containerized setups). No GrumPHP — it would be redundant since it brings its own task definitions instead of reusing our existing tools.

**Important**: `captainhook.json` lives in `backend/` (where `composer.json` is). The `git-directory` config points to the repo root so that hooks are installed in `.git/hooks/` (one level above `backend/`).

#### `backend/captainhook.json`

```json
{
    "config": {
        "verbosity": "normal",
        "run-mode": "local",
        "fail-on-first-error": true,
        "ansi-colors": true,
        "git-directory": "../.git"
    },
    "pre-commit": {
        "enabled": true,
        "actions": [
            {
                "action": "vendor/bin/ecs check {$STAGED_FILES|of-type:php}",
                "options": {
                    "label": "ECS (staged PHP files only)"
                },
                "conditions": [
                    {
                        "exec": "git diff --cached --name-only --diff-filter=ACM | grep -q '\\.php$'"
                    }
                ]
            },
            {
                "action": "vendor/bin/phpstan analyse {$STAGED_FILES|of-type:php} --memory-limit=512M",
                "options": {
                    "label": "PHPStan (staged PHP files only)"
                },
                "conditions": [
                    {
                        "exec": "git diff --cached --name-only --diff-filter=ACM | grep -q '\\.php$'"
                    }
                ]
            }
        ]
    },
    "commit-msg": {
        "enabled": true,
        "actions": [
            {
                "action": "\\CaptainHook\\App\\Hook\\Message\\Action\\Regex",
                "options": {
                    "regex": "#^(feat|fix|refactor|test|docs|chore|ci|style|perf|build|revert)(\\(.+\\))?: .{3,}#",
                    "error": "Commit message must follow Conventional Commits format: feat|fix|refactor|test|docs|chore: <description>"
                }
            }
        ]
    },
    "pre-push": {
        "enabled": true,
        "actions": [
            {
                "action": "vendor/bin/phpunit --testsuite=unit",
                "options": {
                    "label": "PHPUnit Unit Tests"
                }
            }
        ]
    }
}
```

**Important**: The pre-commit hooks run only on **staged PHP files**, not on the entire project. This keeps them fast (<5 seconds instead of 30+). PHPUnit and Infection run as a pre-push hook or in CI — too slow for pre-commit.

The `commit-msg` hook enforces Conventional Commits format (`feat:`, `fix:`, `refactor:`, etc.) at the Git level. This is stricter than just a CI check because the commit is not created at all if the format is wrong.

**Installation**: Requires running `make hooks` manually. The Composer plugin auto-install does not work reliably in containerized setups.

**Bypass**: In an emergency `git commit --no-verify` — but this should be the exception, not the rule.

## .editorconfig

Ensures consistent formatting across IDEs — PHPStorm, VS Code, Vim, etc. read `.editorconfig` natively.

```ini
# .editorconfig
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = space
indent_size = 4

[*.{js,ts,svelte,json,yaml,yml,css,html,md}]
indent_size = 2

[*.{neon,neon.dist}]
indent_style = tab
indent_size = 4

[Makefile]
indent_style = tab

[*.md]
trim_trailing_whitespace = false

[docker-compose*.{yml,yaml}]
indent_size = 2

[*.hcl]
indent_size = 2

[composer.json]
indent_size = 4
```

Key points: PHP uses 4 spaces, frontend code (JS/TS/Svelte/CSS) uses 2 spaces, PHPStan neon files use tabs (PHPStan convention), Makefile must use tabs (Make syntax requirement), Markdown keeps trailing whitespace (semantic line breaks).

## .gitattributes

Ensures consistent line endings, better diffs, and correct language statistics on GitHub.

```gitattributes
# Enforce LF line endings
* text=auto eol=lf

# PHP — better diffs (shows function names in hunk header)
*.php diff=php

# Frontend
*.ts text
*.svelte text
*.css text
*.html text

# Lockfiles — do not edit manually
bun.lock text -diff linguist-generated
composer.lock text -diff linguist-generated

# Binaries
*.png binary
*.jpg binary
*.jpeg binary
*.gif binary
*.ico binary
*.woff binary
*.woff2 binary
*.ttf binary

# GitHub linguist — templates/generated not in language statistics
docs/** linguist-documentation
infrastructure/** linguist-vendored
*.lock linguist-generated
```

**`diff=php`** — Git shows the class/method name in the hunk header for PHP diffs instead of just the line number. Makes `git log -p` and PR diffs much more readable.

**`eol=lf`** — enforces Unix line endings even on Windows. Prevents mixed endings that break Docker volumes on Windows.

**`-diff` on lockfiles** — suppresses diffs for generated lockfiles, keeps PRs clean.

## .dockerignore

Without `.dockerignore`, Docker copies the entire repo content into the build context — including `.git/`, `node_modules/`, `vendor/`. This slows down builds and can leak secrets.

```
.git
.github
.claude
infrastructure
frontend/node_modules
frontend/.svelte-kit
frontend/build
backend/var
backend/vendor
*.md
!backend/README.md
docker-compose*.yml
compose*.yaml
.env.local
.env.*.local
.idea
.vscode
.DS_Store
```

**Note**: The `.gitignore` specification is documented in [`phase0/infrastructure.md`](infrastructure.md) (including backend, frontend, Docker, OpenTofu, IDE files).

## cloud-init.yml (Infrastructure)

Referenced in `modules/server/main.tf`, here is the specification:

```yaml
#cloud-config
package_update: true
package_upgrade: true

packages:
  - docker.io
  - docker-compose-plugin
  - unattended-upgrades
  - fail2ban
  - curl

runcmd:
  # Docker without sudo
  - usermod -aG docker ubuntu

  # Mount volume (Hetzner Volume available as /dev/disk/by-id/scsi-0HC_Volume_*)
  - mkdir -p /mnt/data
  - echo '/dev/disk/by-id/scsi-0HC_Volume_* /mnt/data ext4 defaults,nofail 0 2' >> /etc/fstab
  - mount -a

  # Directories for app data
  - mkdir -p /mnt/data/postgres
  - mkdir -p /mnt/data/backups
  - chown -R 999:999 /mnt/data/postgres    # PostgreSQL User ID

  # Enable unattended upgrades (security only)
  - dpkg-reconfigure --priority=low unattended-upgrades

  # Start fail2ban
  - systemctl enable fail2ban
  - systemctl start fail2ban

  # Start Docker
  - systemctl enable docker
  - systemctl start docker
```

Installs Docker + fail2ban, mounts the Hetzner Volume, creates directories for PostgreSQL data and backups. Security updates run automatically via unattended-upgrades.

## .env.example

```env
# ── App ─────────────────────────────
APP_ENV=dev
APP_SECRET=ChangeThisToASecureSecret

# ── Database ────────────────────────
DATABASE_URL="postgresql://app:!ChangeMe!@pgbouncer:5432/app?serverVersion=17&sslmode=disable&charset=utf8"
# Messenger Worker connects DIRECTLY to the DB (LISTEN/NOTIFY):
MESSENGER_DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/app?serverVersion=17&sslmode=disable&charset=utf8"

# ── Messenger ──────────────────────
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# ── Mercure ─────────────────────────
MERCURE_URL=https://php/.well-known/mercure
MERCURE_PUBLIC_URL=https://localhost/.well-known/mercure
MERCURE_JWT_SECRET=ChangeThisMercureHubJWTSecretKey

# ── JWT ─────────────────────────────
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=ChangeThisPassphrase
JWT_TOKEN_TTL=900         # 15 minutes
JWT_REFRESH_TTL=2592000   # 30 days

# ── Mailer ──────────────────────────
MAILER_DSN=null://null
# Brevo Prod: MAILER_DSN=smtp://user:pass@smtp-relay.brevo.com:587

# ── Frontend ────────────────────────
# No PUBLIC_API_URL needed — API calls are relative (/api/v1/...)
# Dev: Vite proxy forwards /api to FrankenPHP
# Prod: Caddy serves frontend + API under one domain

# ── Monitoring ──────────────────────
SENTRY_DSN=
# GlitchTip Prod: SENTRY_DSN=https://key@errors.example.com/1

# ── Claude Code (GitHub Actions) ────
# Not in .env.local — set as GitHub repository secret:
# ANTHROPIC_API_KEY=sk-ant-...
```
