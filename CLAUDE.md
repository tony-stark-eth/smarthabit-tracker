# SmartHabit Tracker

Household habit tracking app with adaptive notifications.

## Quick Start

```
docker compose --profile dev up -d    # composer install runs automatically via entrypoint
docker compose exec php vendor/bin/ecs check
docker compose exec php vendor/bin/phpstan analyse
docker compose exec php vendor/bin/phpunit
```

## Project

Full-Stack Mono-Repo: `backend/` (Symfony 8 API) + `frontend/` (SvelteKit PWA).
Repo: https://github.com/tony-stark-eth/smarthabit-tracker
Template: https://github.com/tony-stark-eth/template-symfony-sveltekit

## Current State (Phase 6.5 complete)

- **132 backend tests**, 346 assertions (unit + integration)
- **38 E2E tests** (Playwright, all green)
- **Infection mutation testing** with Xdebug path coverage (MSI 61%, threshold 50%)
- **4 parallel CI jobs**: ECS, PHPStan, Rector, Tests+Infection
- **E2E CI job** (blocking, 1 worker + 2 retries)
- Phases 0–6.5 complete, Phases 7–8 remaining

## Guidelines

- `.claude/coding-php.md` — PHP Coding Guidelines
- `.claude/coding-frontend.md` — Svelte / TypeScript Guidelines
- `.claude/testing.md` — Testing, PHPStan (10 Extensions), CI Pipeline
- `.claude/architecture.md` — Docker, DB, Folder Structure, Patterns

## Rules

- No React — Svelte 5 or plain JS
- No `DateTime` — use `DateTimeImmutable` only
- No `var_dump`/`dump`/`dd`
- No `ignoreErrors` in phpstan.neon
- No `empty()`
- Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`
- Use Sonnet (model: "sonnet") for sub-agents, Opus for planning
- All configs in PHP, not YAML
- Docker is the only host dependency

## SmartHabit Context

- **Design: Neo Utility** — Sora + JetBrains Mono, Progress-Bar, Tags, Light/Dark Mode
- **No Firebase** — Web Push (RFC 8030), ntfy (self-hosted), APNs direct
- **Household Isolation** — Security Voter checks every API access for household scope
- **GDPR from Day 1** — Consent tracking, Export, Deletion, Retention periods
- **Time Windows as Local Time** — PostgreSQL `TIME`, DST-safe
- **Adaptive Notifications** — MAD algorithm learns optimal time windows from logs
- **Domain Folders**: `src/Habit/`, `src/Notification/`, `src/Auth/`, `src/Household/`, `src/Stats/`, `src/Shared/`

## Key Technical Decisions

- **FrankenPHP = ZTS PHP** → PCOV doesn't work, use XDEBUG_MODE=coverage
- **PgBouncer** only proxies `app` DB, not `app_test` → tests use force="true" DATABASE_URL to database:5432
- **Infection** runs its own PHPUnit (not --skip-initial-tests) to avoid coverage mismatch
- **captainhook/plugin-composer** disabled via allow-plugins: false (plugin API, .git not in Docker)
- **HouseholdAwareUserInterface** in Shared/Contract avoids Stats→Auth phpat violation
- **JWT key paths**: use `%kernel.project_dir%` directly, NOT `%env(JWT_SECRET_KEY)%` (env vars don't resolve parameters)

## Planning Files

| File | Purpose |
|---|---|
| `task_plan.md` | Phases, waves, parallelization, decisions |
| `progress.md` | Session logs, discoveries, next steps |
| `findings.md` | Phase 0 research, CI fixes, stale spec corrections |

## Documentation

| File | Content |
|---|---|
| `docs/architecture.md` | Docker, Timezone, Data Model, API Endpoints |
| `docs/security.md` | GDPR, Auth Flows, Rate Limiting, Email |
| `docs/frontend.md` | SvelteKit PWA, Design System (Neo Utility), i18n |
| `docs/notifications.md` | Push Architecture (Web Push, ntfy, APNs) |
| `docs/testing.md` | PHPStan Config, Test Strategy, CI Pipeline |
| `docs/statistics.md` | Stats, Analytics, Heatmaps |
| `docs/deployment.md` | Hetzner Deployment, GlitchTip, Backups |
| `docs/phases.md` | Phase Plan (Phase 0 + 9 Phases) |
