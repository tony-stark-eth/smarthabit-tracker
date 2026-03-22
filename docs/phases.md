# Phase Plan & Decisions
## Phase Plan

### Phase 0 — Repo Template (5-7 days)

Public GitHub Template Repository (MIT License) for PHP 8.4 + Symfony 8 + SvelteKit full-stack projects. Contains the complete quality stack, Docker setup, CI/CD and Claude Code integration — no domain-specific logic. SmartHabit will be created as a private fork from it.

Full specification: [`phase0-template.md`](phase0-template.md)

**Core Deliverables:**

- `docker/` — FrankenPHP Multi-Stage Dockerfile, PostgreSQL 17, PgBouncer, Bun Dev Server
- `compose.yaml` + `compose.override.yaml` + `compose.prod.yaml`
- `backend/` — Symfony 8 Skeleton with HealthController + Smoke Test
- `backend/phpstan.neon` — Level max + 10 Extensions (strict-rules, deprecation-rules, shipmonk, voku, cognitive-complexity, type-coverage, phpat, phpunit, symfony, doctrine)
- `backend/rector.php`, `ecs.php`, `infection.json5`, `phpunit.xml.dist`
- `backend/tests/Architecture/` — phpat rules (Layer + Naming)
- `frontend/` — SvelteKit 2 + Svelte 5 + Bun + TypeScript strict + Tailwind 4 + PWA Skeleton
- `infrastructure/` — OpenTofu Skeleton: Hetzner VPS, Network, Volume, Cloudflare DNS (Module, no apply)
- `.github/workflows/ci.yml` — Backend CI (ECS → PHPStan → Rector → PHPUnit → Infection)
- `.github/workflows/ci-frontend.yml` — Frontend CI (lint → check → build)
- `.github/workflows/claude-update.yml` — Biweekly: Claude Code updates dependencies + fixes breaking changes
- `.github/workflows/claude.yml` — Interactive: @claude mentions in Issues/PRs
- `.github/workflows/claude-review.yml` — Automatic code review on every PR
- `.github/dependabot.yml` + Auto-Merge Workflow for patch updates
- `.github/ISSUE_TEMPLATE/` — Bug Report + Feature Request as YAML forms
- `.claude/` — 4 Guidelines (coding-php, coding-frontend, testing, architecture)
- `CLAUDE.md` — lean entry point (~30 lines), references `.claude/`
- `Makefile` — All shortcuts (quality, test, build, db-migrate etc.)
- Open Source: `LICENSE` (MIT), `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `CHANGELOG.md`
- `.env.example` — All ENV vars documented (no real secrets)
- Runner: Default GitHub-hosted, self-hosted as option via `vars.RUNNER_LABEL`

**Definition of Done:**
- `docker compose --profile dev up -d` starts without errors
- `make quality` passes all 6 checks
- HealthController integration test green against real PostgreSQL
- GitHub Actions Backend + Frontend CI green (ECS/PHPStan/Rector as parallel jobs)
- Git hooks installed manually via `make hooks` (CaptainHook plugin-composer disabled in Docker)
- Repo is **public**, MIT License, template flag set
- GitHub Topics set, Description filled in, Social Preview Image uploaded
- Discussions enabled, Wiki + Projects disabled
- README with badges, Quick Start, After-Forking Checklist
- Open-source files complete (CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, CHANGELOG)
- v1.0.0 Release created

### Phase 1a — Project Setup & Auth (2 weeks)

Builds on the Repo Template from Phase 0 (`Use this template` → `smarthabit-tracker`).

- ~~Docker Setup~~ ~~Quality Tooling~~ ~~CI Pipeline~~ — **already included in template**
- **i18n from day 1**: paraglide-sveltekit setup (de/en), Symfony Translator (messages.de.yaml/en.yaml)
- Create domain folders: `src/Habit/`, `src/Notification/`, `src/Auth/`, `src/Household/`, `src/Stats/`, `src/Shared/`
- Extend phpat `DomainIsolationTest` with SmartHabit-specific rules
- Doctrine Entities + Migrations (incl. all fields: locale, theme, email_verified_at, consent_at, consent_version, deleted_at, updated_at)
- Auth: Register, Login, JWT (Access + Refresh Token)
- **Rate Limiting** on auth endpoints (symfony/rate-limiter)
- **Email Verification** + **Password Reset** flow (Symfony Mailer + Brevo)
- **GDPR**: Privacy policy, consent at registration, export + deletion endpoints
- **Household Isolation Voter**: unit-tested, active from day 1
- Integration tests for Auth (incl. rate limiting, verification, password reset)

### Phase 1b — Core Features & Frontend (2 weeks)

- CRUD for Habits (incl. soft-delete) + unit tests for domain services + first Infection round
- One-tap logging
- Dashboard endpoint + health check endpoints (`/api/v1/health`, `/api/v1/health/ready`)
- SvelteKit foundation + auth flow + timezone setting + language switcher
- **Accessibility**: ARIA labels, keyboard nav, contrast check from first UI element
- Integration tests for Habit CRUD + Dashboard + GDPR endpoints

### Phase 2 — Usable MVP (2 weeks)

- PWA Setup (Manifest, Service Worker, Install)
- **PWA Shortcuts** in manifest for quick-log of top habits (long-press on icon)
- Dashboard UI with tap-logging
- **Mercure Real-Time**: Live updates when household member logs (SSE, already in Caddy)
- History View
- Household System (invite code, join) + **Household invitation via email** (optional)
- Offline queue for logs
- **Dark Mode** toggle (Auto/Light/Dark, CSS Custom Properties)

### Phase 3 — Notifications (2 weeks)

**Web Push (PWA) only** — ntfy (Android) and APNs (iOS) come in Phase 7 (Native). One transport instead of three reduces complexity significantly and still covers the primary use case (browser notifications on Desktop + Android Chrome + iOS Safari 16.4+).

- **Web Push Setup**: Generate VAPID key pair, integrate `minishlink/web-push`
- Push subscription registration + lifecycle in frontend (Service Worker Push API)
- Service Worker push handler (show notification, tap → open app)
- Cron + Messenger Worker (per-user timezone check, Web Push dispatch)
- NotificationLog + deduplication
- Notification tap → app opens and logs
- Push subscription cleanup command
- Unit tests: TimeWindowChecker, PushSubscriptionManager, NotifyHandler, HabitCompletionChecker
- Integration tests: CheckHabitsHandler, NotifyHabitHandler (stubs for WebPush), email tests, Mercure tests
- Raise Infection MSI threshold to >= 80%

**Deliberately excluded**: Multi-transport NotifyHandler (Strategy Pattern). In Phase 3 there is only one transport (Web Push). The Strategy Pattern will be introduced in Phase 7 when ntfy/APNs are added — no over-engineering for a single transport.

### Phase 4 — Intelligence (1-2 weeks)

- Nightly command for time window analysis
- MAD-based algorithm (timezone-aware)
- Weekday/weekend detection
- UI: Display learned vs. manual window
- Unit tests: TimeWindowLearner (most extensive test suite — outliers, edge cases, min. data points)
- Covered Code MSI >= 90% for TimeWindowLearner

### Phase 5 — Statistics & Analytics (2-3 weeks)

- Basic stats: streak, completion rate, average time (on-the-fly PostgreSQL queries)
- Stats endpoint per habit: `GET /api/v1/habits/{id}/stats`
- Trend calculation: compare current vs. previous 30 days
- Distribution by user: who completes which habit how often?
- Household dashboard: `GET /api/v1/stats/household` (total completion, ranking, most active user)
- Nightly `app:compute-stats` command for materialized views (heatmaps, aggregations)
- Weekday heatmap + time-of-day heatmap (SVG grid in frontend)
- Charts via lightweight Svelte library (layerchart or pancake)
- Unit tests: streak calculation edge cases (timezone boundaries, gaps, frequency handling)

### Phase 6 — Deployment & Ops (1-2 weeks)

- **OpenTofu**: Provision Hetzner VPS (CX31/CX41), firewall, DNS, volume
- Deploy app + **GlitchTip** Compose stacks on server
- **Sentry SDK** (→ GlitchTip) integrated in PHP + frontend (DSN points to errors.smarthabit.de)
- **PostgreSQL Backup**: Nightly pg_dump → Hetzner Object Storage via rclone
- **Backup restore test**: `make db-restore-test` — prove once that restore works. A backup that has never been tested is not a backup.
- Let's Encrypt via Caddy (automatic)
- First manual deployment flow: `git pull → docker compose build → up -d → migrate`
- Smoke tests on production
- Configure GlitchTip alerts (error spikes, cron failures)
- **Lighthouse Audit**: Performance + a11y score >= 90

### Phase 7 — Native App & Widgets (staged, 3-4 weeks)

**Stage 1 — PWA Shortcuts** already done in Phase 2 (manifest entries).

**Stage 2 — Multi-Transport Push + Capacitor App (1-2 weeks)**:
- **NotifyHandler refactoring**: Introduce Strategy Pattern — Web Push (exists from Phase 3) + ntfy (Android) + APNs (iOS). `match($subscription['type'])` dispatches to the correct transport.
- Set up ntfy server on Hetzner (ntfy.smarthabit.de, `binwiederhier/ntfy:latest`)
- APNs integration via `symfony/notifier` APNs transport
- Capacitor integration: SvelteKit build → native iOS/Android shell
- TestFlight / Play Console internal testing
- App Store + Play Store submission
- Verify all UI texts for complete de/en translation before store release

**Stage 3 — Native Widgets via `capacitor-widget-bridge` (1-2 weeks)**:
- Integrate `capacitor-widget-bridge` for SharedStorage bridge
- Write iOS Widget Extension (~50-80 lines SwiftUI): habit list from UserDefaults
- Write Android AppWidgetProvider (~80-100 lines Kotlin + XML): habit list from SharedPreferences
- Data flow: App writes dashboard data to SharedStorage → Widget reads → tap opens app at habit
- Widget sizes: Small/Medium/Large (iOS), 2x1 / 4x2 (Android)
- Iteration afterwards: direct tap-to-log from widget without opening app (App Intents / PendingIntent)

### Phase 8 — CI/CD & Automation (1 week)

- **GitHub Actions CD**: Push to main → build image → push to GHCR → SSH deploy on Hetzner
- Migrations as separate CD step
- Rollback strategy: previous image tag
- Staging environment on separate Hetzner VPS (optional, CX21 is sufficient)
- Automated Lighthouse + a11y checks in CI

## Decisions Made

| Question | Decision | Rationale |
|---|---|---|
| API Platform? | **No** | Overhead for MVP. Plain Controller + Serializer. |
| Auth strategy? | **JWT** via `lexik/jwt-authentication-bundle` | Standard for PWA/SPA, works offline. Access Token (15min) + Refresh Token (30d). |
| Database? | **PostgreSQL 17 + PgBouncer** | Range types, window functions, robust connection pooling. |
| PHP Runtime? | **FrankenPHP** via `dunglas/symfony-docker` | Worker Mode (Symfony stays in memory), Caddy built-in (auto-HTTPS, HTTP/3), one container instead of PHP-FPM + nginx. Rootless prod image 290MB. |
| JS Runtime? | **Bun 1.3.x** instead of Node.js | ~25x faster `install`, ~10x faster startup. Drop-in compatible with npm packages. |
| Asset pipeline? | **Bun + Vite (via SvelteKit)** — no Symfony AssetMapper | Frontend is a standalone SPA, no Twig. AssetMapper would be overhead without benefit. |
| Push (PWA)? | **`minishlink/web-push`** (W3C Standard) | No Firebase, no Google account. VAPID auth directly to browser endpoints. |
| Push (Native)? | **APNs direct + ntfy self-hosted** | APNs for iOS (only way), ntfy on Hetzner for Android. Completely US-free. |
| Push subscription cleanup? | **Three-stage** | Immediately on push error (410 Gone), nightly for stale subscriptions, explicitly on logout. |
| Timezones? | **UTC storage + user timezone** | Everything stored in UTC. Time windows as local time, notification check converts per user. DST-safe. |
| Time window storage? | **Local time (TIME without TZ)** | Human concept, DST changes don't require recalculation. |
| PHPUnit? | **Version 13** (Feb 2026) | Sealed test doubles, `createStub`/`createMock` separation, `withParameterSetsInOrder`. Requires PHP 8.4. |
| Coverage tool? | **Xdebug** with `XDEBUG_MODE=coverage` | FrankenPHP uses ZTS (Zend Thread Safety); PCOV is NTS-only. Xdebug supports ZTS and provides reliable coverage. |
| Mutation testing scope? | **Unit suite only** | Integration tests are too slow for Infection, cause timeouts. Domain logic is the critical part. |
| MSI threshold? | **80% MSI, 90% Covered MSI** | Realistic from Phase 3, below that CI doesn't block but warns. |
| Mocking strategy? | **`createStub()` > `createMock()`** | PHPUnit 13 enforces the separation. Mocks with `seal()` only for external services (WebPush, ntfy, APNs, Brevo). |
| i18n Frontend? | **paraglide-sveltekit** | Compile-time, zero runtime overhead, type-safe keys. No `$t()` at runtime. |
| Design direction? | **Neo Utility** + Dark Mode | Functional, data-driven, Sora + JetBrains Mono, progress bar, tags. Dark Mode via CSS Custom Properties + `prefers-color-scheme`. |
| Color system? | **CSS Custom Properties** with light/dark swap | User preference in DB (`theme: auto/light/dark`), no separate stylesheet. |
| i18n Backend? | **Symfony Translator** | Standard component, YAML-based, integrates with Validator + Notifier. |
| Statistics calculation? | **Hybrid: on-the-fly + materialized views** | Basic stats (streak, rate) computed live, heatmaps/aggregations precomputed nightly. |
| Native app? | **Capacitor 6.x** (Phase 7, Stage 2) | Same SvelteKit code, native shell for app stores. |
| Widgets? | **Staged**: PWA Shortcuts → Capacitor + `capacitor-widget-bridge` → native SwiftUI/Kotlin | Stage 1 costs 30min, Stage 3 ~50-80 lines native code per platform. No custom plugin needed. |
| Email provider? | **Brevo** (Free Tier) via `symfony/brevo-mailer` | 300 mails/day free, EU-based (GDPR-compliant), official Symfony Bridge. |
| Error tracking? | **GlitchTip 6.x** self-hosted | 512MB RAM, MIT license, Sentry SDK compatible. Migration to Sentry Cloud anytime via DSN swap. |
| Real-time? | **Mercure** (SSE, built into Caddy) | Already in `dunglas/symfony-docker` template, no extra service. Live updates for household logs. |
| Hosting? | **Hetzner Cloud VPS** + OpenTofu | IaC, affordable, EU data center (GDPR), good peering. |
| CD? | **GitHub Actions** (Phase 8) | Manual deploy first, CD comes after stabilization. |
| Backups? | **pg_dump nightly** → Hetzner Object Storage | 7 daily + 4 weekly rotation, monthly restore test. |
| GDPR? | **From day 1**: consent, export, deletion, retention periods | Retrofitting is a nightmare. Blocker for app store. |
| Rate limiting? | **Symfony Rate Limiter + Caddy** | Two layers: application-level (5/min login) + reverse proxy level. |
