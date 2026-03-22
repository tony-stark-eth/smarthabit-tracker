# SmartHabit Tracker — Task Plan

## Overview

Full-Stack Mono-Repo: Symfony 8 API + SvelteKit PWA for household habit tracking with adaptive notifications.

**Current state**: Phase 0 COMPLETE — template published at https://github.com/tony-stark-eth/template-symfony-sveltekit (v1.0.0)
**Strategy**: Phase 0 builds a public GitHub template repo first, then SmartHabit forks it.
**Next step**: Phase 1a — fork template into private smarthabit-tracker repo.

---

## Phase 0 — Repo Template (public, MIT) ✅ COMPLETE

**Repo**: https://github.com/tony-stark-eth/template-symfony-sveltekit
**Release**: v1.0.0 (2026-03-21)
**All checks green**: ECS ✅ PHPStan ✅ Rector ✅ PHPUnit 4/4 ✅ Svelte Check ✅ ESLint ✅ Build ✅ E2E 3/3 ✅

### 0.1 — Parallel Group A: Docker Layer
- [ ] `docker/frankenphp/Dockerfile` (multi-stage: base → dev → prod)
- [ ] `docker/frankenphp/Caddyfile` (API routing + Mercure + SPA fallback)
- [ ] `docker/postgres/init.sql` (test DB creation)
- [ ] `compose.yaml` (php, database, pgbouncer, messenger-worker, bun)
- [ ] `compose.override.yaml` (dev ports, volumes, healthcheck)
- [ ] `compose.prod.yaml` (prod target, worker mode, restart policy)
- [ ] Verify: `docker compose --profile dev up -d` starts cleanly

**Spec**: `docs/phase0/docker.md`

### 0.2 — Parallel Group B: Backend Skeleton
- [ ] `backend/composer.json` (require + require-dev, 14 dev packages)
- [ ] `backend/phpstan.neon` (level max, 10 extensions, bleeding edge flags)
- [ ] `backend/rector.php` (PHP 8.4 + Symfony 8 + Doctrine + PHPUnit sets)
- [ ] `backend/ecs.php` (PSR-12 + common + strict + cleanCode)
- [ ] `backend/infection.json5` (unit-only, MSI 80%, Covered MSI 90%)
- [ ] `backend/phpunit.xml.dist` (path coverage, two suites, PCOV)
- [ ] `backend/src/Shared/Controller/HealthController.php` (two endpoints)
- [ ] `backend/tests/Integration/Controller/HealthControllerTest.php`
- [ ] `backend/tests/Architecture/LayerDependencyTest.php` (phpat)
- [ ] `backend/tests/Architecture/NamingConventionTest.php` (phpat)
- [ ] `composer install` + verify all tools work
- [ ] Verify: `make quality` passes (ECS, PHPStan, Rector, PHPUnit, Infection)

**Spec**: `docs/phase0/backend.md`
**Depends on**: 0.1 (needs DB for integration test)

### 0.3 — Parallel Group C: Frontend Skeleton
- [ ] `frontend/package.json` (SvelteKit 2, Svelte 5, Bun, Tailwind 4, adapter-static)
- [ ] `frontend/tsconfig.json` (strict, noUncheckedIndexedAccess, etc.)
- [ ] `frontend/vite.config.ts` (Tailwind plugin, API proxy, Mercure proxy)
- [ ] `frontend/svelte.config.js` (adapter-static)
- [ ] `frontend/src/lib/api/client.ts` (generic fetch wrapper with JWT)
- [ ] `frontend/src/routes/+page.svelte` (landing page with dark mode toggle)
- [ ] `bun install` + verify check + build

**Spec**: `docs/phase0/frontend.md`
**Independent** (can run parallel with 0.2)

### 0.4 — Parallel Group D: Infrastructure (OpenTofu)
- [ ] `infrastructure/versions.tf`
- [ ] `infrastructure/main.tf` (4 modules)
- [ ] `infrastructure/variables.tf`
- [ ] `infrastructure/terraform.tfvars.example`
- [ ] `infrastructure/modules/server/main.tf` + `cloud-init.yml`
- [ ] `infrastructure/modules/network/main.tf`
- [ ] `infrastructure/modules/volume/main.tf`
- [ ] `infrastructure/modules/dns/main.tf`
- [ ] Module `variables.tf` + `outputs.tf` for each module
- [ ] `infrastructure/.gitignore`

**Spec**: `docs/phase0/infrastructure.md`
**Fully independent** — no runtime deps

### 0.5 — Parallel Group E: DX Files
- [ ] `Makefile` (all targets: docker, quality, test, frontend, db, infra)
- [ ] `backend/captainhook.json` (pre-commit, commit-msg, pre-push)
- [ ] `.editorconfig` (PHP 4-space, frontend 2-space, neon tabs, Makefile tabs)
- [ ] `.gitattributes` (LF, diff=php, lockfile -diff, linguist)
- [ ] `.gitignore` (backend, frontend, docker, infra, IDE)
- [ ] `.dockerignore`
- [ ] `.env.example` (all vars documented, no real secrets)
- [ ] `docker/frankenphp/conf.d/` placeholder for local PHP ini overrides

**Spec**: `docs/phase0/dx.md`
**Mostly independent** — Makefile targets reference 0.2/0.3 commands

### 0.6 — Parallel Group F: Claude Code Integration
- [ ] `CLAUDE.md` (~30 lines, entry point)
- [ ] `.claude/coding-php.md`
- [ ] `.claude/coding-frontend.md`
- [ ] `.claude/testing.md`
- [ ] `.claude/architecture.md`

**Spec**: `docs/phase0/claude-spec.md`
**Fully independent**

### 0.7 — Parallel Group G: Open Source Files
- [ ] `LICENSE` (MIT)
- [ ] `CONTRIBUTING.md`
- [ ] `CODE_OF_CONDUCT.md` (Contributor Covenant v2.1)
- [ ] `SECURITY.md`
- [ ] `CHANGELOG.md` (Keep a Changelog format)
- [ ] `README.md` (badges, quick start, quality stack table, after-fork checklist)
- [ ] `.github/ISSUE_TEMPLATE/bug_report.yml`
- [ ] `.github/ISSUE_TEMPLATE/feature_request.yml`
- [ ] `.github/PULL_REQUEST_TEMPLATE.md`
- [ ] `.github/CODEOWNERS`

**Spec**: `docs/phase0/open-source.md`
**Fully independent**

### 0.8 — CI/CD Workflows (after 0.2 + 0.3 structure exists)
- [ ] `.github/workflows/ci.yml` (Backend: ECS/PHPStan/Rector parallel → Tests+Infection sequential)
- [ ] `.github/workflows/ci-frontend.yml` (lint → check → build)
- [ ] `.github/workflows/claude-update.yml` (biweekly dependency update)
- [ ] `.github/workflows/claude.yml` (@claude mentions)
- [ ] `.github/workflows/claude-review.yml` (auto code review on PR)
- [ ] `.github/dependabot.yml` (composer, npm, docker, actions)
- [ ] `.github/workflows/dependabot-automerge.yml`
- [ ] Verify: CI green on first push

**Spec**: `docs/phase0/ci.md`
**Depends on**: 0.2 + 0.3 (needs file structure)

### 0.9 — Finalization
- [ ] `docker compose --profile dev up -d` starts all services
- [ ] `make quality` passes all 6 checks
- [ ] HealthController integration test green against real PostgreSQL
- [ ] GitHub Actions CI green (backend + frontend)
- [ ] Git Hooks install on `composer install`
- [ ] Repo marked as GitHub Template
- [ ] Topics, description, social preview set
- [ ] Discussions enabled, Wiki + Projects disabled
- [ ] v1.0.0 release created

---

## Phase 0 — Parallelization Map

```
Time →
─────────────────────────────────────────────────────

Agent 1: [0.4 Infrastructure] [0.8 CI Workflows    ]
Agent 2: [0.6 Claude Spec   ] [0.7 Open Source     ]
Agent 3: [0.5 DX Files      ] [0.1 Docker Layer    ]
Agent 4:                       [0.2 Backend Skeleton ]
Agent 5:                       [0.3 Frontend Skeleton]

                               ↓ after 0.1-0.3 done
Agent 1-5:                     [0.9 Integration Test + Finalize]
```

**Wave 1** (fully parallel, no deps):
- 0.4 Infrastructure (OpenTofu)
- 0.5 DX Files (Makefile, editorconfig, gitignore, etc.)
- 0.6 Claude Spec (.claude/ directory)
- 0.7 Open Source (LICENSE, README, etc.)

**Wave 2** (Docker must start first, then backend+frontend parallel):
- 0.1 Docker Layer
- 0.2 Backend Skeleton (needs Docker for testing)
- 0.3 Frontend Skeleton (independent but part of wave 2)

**Wave 3** (needs structure from wave 2):
- 0.8 CI Workflows

**Wave 4** (integration):
- 0.9 Full verification + GitHub settings

---

## Phase 0.10 — Docker Hardening (dunglas/symfony-docker patterns) ✅ COMPLETE

- [x] Entrypoint: database readiness check (retry loop, 60s timeout)
- [x] Entrypoint: auto-run Doctrine migrations on startup
- [x] Prod Dockerfile: remove setuid/setgid bits
- [x] Dev compose: add `tty: true` for interactive console

**Context**: Comparison analysis found our entrypoint is too minimal — container can start before DB is ready, and migrations require manual `make db-migrate` after every deploy. dunglas handles both in the entrypoint with retry logic.

---

## Phase 1a — Domain Scaffolding & Auth

**Status**: COMPLETE
**Repo**: https://github.com/tony-stark-eth/smarthabit-tracker

### 1a.1 — Wave 1: Scaffold (parallel, no dependencies)

**Agent A: Domain structure + Architecture tests**
- [ ] Create domain folders: `src/Habit/`, `src/Notification/`, `src/Auth/`, `src/Household/`, `src/Stats/`
- [ ] Move `Shared/Controller/HealthController.php` to `src/Shared/Controller/`
- [ ] Create enums: `Locale` (de/en), `Theme` (auto/light/dark), `HabitFrequency` (daily/weekly/custom)
- [ ] phpat `DomainIsolationTest`: Auth→Shared OK, Auth→Habit FORBIDDEN, Habit→Auth FORBIDDEN, etc.
- [ ] phpat `NamingConventionTest`: extend with SmartHabit-specific suffixes (Voter, Handler)
- [ ] Verify: `make phpstan` passes with new rules

**Agent B: Entities + Migrations**
- [ ] `Household` entity (UUID, name, invite_code 8-char, timestamps)
- [ ] `User` entity (UUID, household FK, email, password, display_name, timezone IANA, locale, theme, push_subscriptions JSON, consent_at, consent_version, email_verified_at, soft-delete, timestamps)
- [ ] `Habit` entity (UUID, household FK, name, description, frequency, icon, color, sort_order, time_window_start/end TIME, time_window_mode, soft-delete, timestamps)
- [ ] `HabitLog` entity (UUID, habit FK, user FK, logged_at, note, timestamps)
- [ ] `NotificationLog` entity (UUID, user FK, habit FK nullable, channel, status, sent_at, message)
- [ ] Generate migration, verify against real PostgreSQL
- [ ] Verify: `make test` passes (existing HealthController test still works)

**Agent C: i18n setup**
- [ ] Backend: Symfony Translator config (`translations/messages.de.yaml`, `messages.en.yaml`)
- [ ] Backend: Add translation keys for auth responses (register_success, login_failed, email_taken, etc.)
- [ ] Frontend: Install `paraglide-sveltekit` + configure (de/en)
- [ ] Frontend: Create message files (`messages/de.json`, `messages/en.json`) with auth UI strings
- [ ] Frontend: Language switcher component (stores preference in localStorage + user.locale API)

### 1a.2 — Wave 2: Auth system (sequential, needs entities)

- [ ] JWT config: `lexik/jwt-authentication-bundle` (Access 15min, Refresh 30d, ES256 keys)
- [ ] Generate JWT keypair: `php bin/console lexik:jwt:generate-keypair`
- [ ] Security config (`security.php`): firewalls (login, api), user provider, password hasher
- [ ] `POST /api/v1/register` — create Household + User, validate timezone/locale, hash password, store consent, return JWT pair
- [ ] `POST /api/v1/login` — verify credentials, return JWT pair
- [ ] `POST /api/v1/token/refresh` — validate refresh token, issue new pair
- [ ] `POST /api/v1/household/join` — validate invite_code, add user to existing household
- [ ] `GET /api/v1/user/me` — return user profile
- [ ] `PUT /api/v1/user/me` — update display_name, timezone, locale, theme
- [ ] Rate limiting: login 5/min, register 3/15min, password-forgot 3/15min, api-general 60/min

### 1a.3 — Wave 3: GDPR + Email + Voter (parallel after Wave 2)

**Agent D: GDPR endpoints**
- [ ] `GET /api/v1/user/export` — full JSON export (Art. 20 DSGVO)
- [ ] `DELETE /api/v1/user/me` — cascade delete (User → Logs, Notifications, PushSubscriptions; if last user in Household → delete Household + Habits)
- [ ] `GET /api/v1/privacy` — return privacy policy version + text
- [ ] Consent validation at registration (consent_at + consent_version required)

**Agent E: Email stubs + Password reset**
- [ ] Mailer config: `null://null` transport (Brevo added later)
- [ ] `POST /api/v1/password/forgot` — generate reset token, send email (stubbed)
- [ ] `POST /api/v1/password/reset` — validate token (1h TTL, single-use), set new password
- [ ] `PUT /api/v1/user/password` — change password (authenticated, requires current password)
- [ ] Email verification stub: token generation + `GET /api/v1/verify-email?token=...`

**Agent F: Household Isolation Voter**
- [ ] `HouseholdVoter` — checks `$subject->getHousehold() === $user->getHousehold()` for all domain entities
- [ ] Register voter in security config
- [ ] Unit tests: same household → GRANTED, different household → DENIED, no household → DENIED
- [ ] Wire voter to all Habit/HabitLog controllers (annotation or manual check)

### 1a.4 — Wave 4: Integration tests + CI green

- [ ] Integration test: register → verify → login → access protected endpoint
- [ ] Integration test: rate limiting (6th login attempt → 429)
- [ ] Integration test: GDPR export returns correct data
- [ ] Integration test: GDPR delete cascades correctly
- [ ] Integration test: Household isolation (User A cannot see Household B's habits)
- [ ] Integration test: password reset flow (forgot → token → reset → login with new password)
- [ ] Integration test: join household via invite code
- [ ] Verify: `make quality` passes (ECS + PHPStan + Rector + PHPUnit + Infection)
- [ ] CI green on GitHub Actions

### 1a Parallelization Map

```
Time →
──────────────────────────────────────────────────────────

Wave 1 (parallel, no deps):
  Agent A: [Domain folders + phpat rules        ]
  Agent B: [Entities + Migrations               ]
  Agent C: [i18n setup (backend + frontend)     ]

Wave 2 (sequential, needs entities from Wave 1):
  Main:    [JWT + Auth controllers + Rate Limit ]

Wave 3 (parallel, needs auth from Wave 2):
  Agent D: [GDPR endpoints                     ]
  Agent E: [Email stubs + Password reset        ]
  Agent F: [Household Voter + unit tests        ]

Wave 4 (integration, needs everything):
  Main:    [Integration tests + CI green        ]
```

## Phase 1b — Core Features & Frontend

**Status**: IN PROGRESS
**Depends on**: Phase 1a (entities, auth, JWT)

### 1b.1 — Wave 1: Backend CRUD + Frontend scaffold (parallel)

**Agent A (sonnet): Habit CRUD + Repository**
- [ ] `HabitRepository` with `findActiveByHousehold()` (filters `deleted_at IS NULL`)
- [ ] `TimeWindow` value object (`final readonly class`, `contains()` method)
- [ ] `HabitLogSource` enum (manual, notification) + add `source` column to HabitLog + migration
- [ ] `POST /api/v1/habits` — create habit (validate name, frequency, time window)
- [ ] `GET /api/v1/habits` — list active habits for household (sorted by sort_order)
- [ ] `PUT /api/v1/habits/{id}` — update habit (voter check: HOUSEHOLD_EDIT)
- [ ] `DELETE /api/v1/habits/{id}` — soft-delete (set deleted_at)
- [ ] `PATCH /api/v1/habits/reorder` — update sort_order for multiple habits
- [ ] Unit tests for TimeWindow value object

**Agent B (sonnet): Dashboard + One-Tap Logging**
- [ ] `POST /api/v1/habits/{id}/log` — one-tap log (source: manual/notification)
- [ ] `DELETE /api/v1/habits/{id}/log/{logId}` — undo log
- [ ] `GET /api/v1/habits/{id}/history` — paginated log history
- [ ] `GET /api/v1/dashboard` — all habits + today's completion status per habit
- [ ] Update HealthController: add DB + Messenger status checks

**Agent C (sonnet): SvelteKit base + auth flow**
- [ ] Route structure: `(auth)/login`, `(auth)/register`, `(app)/+page` (dashboard), `(app)/settings`
- [ ] API client (`$lib/api/client.ts`): fetch wrapper with JWT, auto-refresh, error handling
- [ ] Auth store (`$lib/stores/auth.ts`): Svelte 5 runes, token persistence, user state
- [ ] `+layout.svelte`: auth guard, redirect to login if no token
- [ ] Login page: email + password form, error display
- [ ] Register page: email, password, display_name, timezone (auto-detected), locale, household_name/invite_code, consent checkbox

### 1b.2 — Wave 2: Frontend UI + Design system (needs Wave 1 API)

**Agent D (sonnet): Dashboard UI + Design tokens**
- [ ] CSS custom properties: light/dark mode tokens (Sora + JetBrains Mono fonts)
- [ ] Dark mode toggle (reads user.theme, stores in $state, applies via body class)
- [ ] Dashboard page: habit cards with completion status, progress bar header
- [ ] Habit card component: emoji + name + time window tag + check button
- [ ] One-tap animation: scale-pulse + color transition to `--success`
- [ ] Optimistic UI: update card immediately, revert on error
- [ ] Settings page: language switcher, theme toggle, export data, delete account

### 1b.3 — Wave 3: Tests + CI green

- [ ] Integration tests: Habit CRUD (create, read, update, soft-delete, reorder)
- [ ] Integration tests: one-tap log + undo + history
- [ ] Integration tests: dashboard returns correct daily status
- [ ] Integration tests: Household isolation on habits (User A can't edit Household B's habits)
- [ ] Infection: first mutation testing round on Habit domain (TimeWindow, Repository)
- [ ] Frontend: `bun run check` + `bun run build` + E2E smoke test
- [ ] CI green on all 4 backend jobs + frontend CI

### 1b Parallelization Map

```
Time →
──────────────────────────────────────────────────────────

Wave 1 (parallel, no deps):
  Agent A (sonnet): [Habit CRUD + Repository + TimeWindow ]
  Agent B (sonnet): [Dashboard + Logging endpoints        ]
  Agent C (sonnet): [SvelteKit routes + auth + API client ]

Wave 2 (needs Wave 1 API):
  Agent D (sonnet): [Dashboard UI + Design tokens + a11y  ]

Wave 3 (integration, needs everything):
  Agent E (sonnet): [Integration tests + Infection + CI   ]
```

**Entity gaps to address in Wave 1:**
- HabitLog needs `source` column (HabitLogSource enum) → migration
- Habit entity already has the fields we need (icon serves as emoji)

## Phase 2 — Usable MVP

**Status**: IN PROGRESS
**Already done in Phase 1b**: Dashboard UI, tap logging, dark mode CSS tokens, settings page (theme/locale), HouseholdVoter, join endpoint

### 2.1 — Wave 1: PWA + History + Household UI (parallel)

**Agent A (sonnet): PWA setup**
- [ ] `frontend/static/manifest.json` — name, icons, theme_color, display: standalone, shortcuts (top 3 habits placeholder)
- [ ] Install `@vite-pwa/sveltekit`, configure in `vite.config.ts`
- [ ] `frontend/src/service-worker.ts` — Workbox asset caching, offline detection
- [ ] Install prompt handling (beforeinstallprompt event)

**Agent B (sonnet): History view + Offline queue**
- [ ] `frontend/src/routes/(app)/habits/[id]/+page.svelte` — paginated log history (calls GET /api/v1/habits/{id}/history)
- [ ] Long-press on habit card → navigate to history (touch event handler)
- [ ] Offline queue in `src/lib/api/offline.ts`: store failed POSTs in IndexedDB/localStorage, flush on `online` event
- [ ] Update API client to use offline queue for POST /habits/{id}/log

**Agent C (sonnet): Household UI + Mercure**
- [ ] Settings page: display household invite code, copy-to-clipboard button
- [ ] Settings page: "Join household" input (calls POST /api/v1/household/join)
- [ ] Mercure SSE subscription: subscribe to household topic on dashboard load
- [ ] Backend: publish Mercure update when HabitLog is created/deleted
- [ ] Frontend: update dashboard state from Mercure events (no page refresh)

### 2.2 — Wave 2: Integration + CI

- [ ] E2E test: PWA manifest is served correctly
- [ ] Integration test: Mercure publishes on habit log
- [ ] Verify: all backend + frontend CI green
- [ ] Verify: `bun run check` + `bun run build` + ESLint pass

### 2 Parallelization Map

```
Time →
──────────────────────────────────────────────────────

Wave 1 (parallel):
  Agent A (sonnet): [PWA manifest + service worker  ]
  Agent B (sonnet): [History view + offline queue    ]
  Agent C (sonnet): [Household UI + Mercure SSE      ]

Wave 2 (integration):
  Main:             [Tests + CI green                ]
```

## Phase 3 — Notifications (Web Push only)

- [ ] VAPID key pair, `minishlink/web-push` integration
- [ ] Push subscription registration + lifecycle
- [ ] Service Worker push handler
- [ ] Cron + Messenger Worker (per-user timezone check)
- [ ] NotificationLog + deduplication
- [ ] Notification tap → app opens + logs
- [ ] Cleanup command
- [ ] Unit + Integration tests, Infection MSI ≥ 80%

## Phase 4 — Intelligence

- [ ] Nightly time window analysis command
- [ ] MAD-based algorithm (timezone-aware)
- [ ] Weekday/Weekend detection
- [ ] UI: learned vs. manual window display
- [ ] Extensive unit tests for TimeWindowLearner

## Phase 5 — Statistics & Analytics

- [ ] Basic stats: Streak, Completion Rate, avg time (live SQL)
- [ ] Stats endpoint per habit
- [ ] Trend calculation (current vs previous 30d)
- [ ] Per-user distribution
- [ ] Household dashboard
- [ ] Nightly `app:compute-stats` for materialized views
- [ ] Heatmaps (weekday + time grid, SVG)
- [ ] Charts (layerchart or pancake)

## Phase 6 — Deployment & Ops

- [ ] OpenTofu: provision Hetzner VPS
- [ ] GlitchTip + App compose stacks
- [ ] Sentry SDK → GlitchTip
- [ ] PostgreSQL backup (nightly pg_dump → Object Storage)
- [ ] Backup restore test
- [ ] Let's Encrypt via Caddy
- [ ] Manual deployment flow
- [ ] Smoke tests on production
- [ ] Lighthouse ≥ 90

## Phase 7 — Native App & Widgets

- [ ] **Stufe 2**: Multi-transport push (Strategy Pattern) + Capacitor app
- [ ] ntfy server, APNs integration
- [ ] TestFlight / Play Console
- [ ] **Stufe 3**: Native widgets via capacitor-widget-bridge
- [ ] iOS Widget Extension (SwiftUI)
- [ ] Android AppWidgetProvider (Kotlin)

## Phase 8 — CI/CD & Automation

- [ ] GitHub Actions CD: push → build → GHCR → SSH deploy
- [ ] Migrations as separate CD step
- [ ] Rollback strategy
- [ ] Automated Lighthouse + a11y in CI

---

## Decisions Log

| Date | Decision | Rationale |
|---|---|---|
| 2026-03-21 | Start with Phase 0 (public template) | Template is reusable, SmartHabit forks from it |
| 2026-03-21 | Wave-based parallelization for Phase 0 | Independent file groups can be written concurrently |
| 2026-03-21 | CI runs inside Docker Compose (not bare runner) | `ubuntu-latest` uses system PHP — different php.ini, no FrankenPHP worker mode, no PgBouncer. Bare-runner CI hides environment-specific bugs. Single sequential job using `docker compose exec -T`. |
| 2026-03-21 | phpunit.xml.dist: `force="true"` on DATABASE_URL pointing to `database:5432` | Tests must bypass PgBouncer (transaction mode only proxies `app`, not `app_test`). `force="true"` ensures this regardless of container env. |
