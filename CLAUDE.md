# SmartHabit Tracker

Minimalistische Household-App für wiederkehrende Alltagsaufgaben mit adaptiven Notifications.

## Quick Start

```
docker compose --profile dev up -d
cd backend && composer install
cd frontend && bun install
make quality
```

## Projekt

Full-Stack Mono-Repo: `backend/` (Symfony 8 API) + `frontend/` (SvelteKit PWA).

## Guidelines

Generische Coding- und Architektur-Regeln (aus dem Repo Template):

- `.claude/coding-php.md` — PHP Coding Guidelines
- `.claude/coding-frontend.md` — Svelte / TypeScript Guidelines
- `.claude/testing.md` — Testing, PHPStan (10 Extensions), CI Pipeline, Enforcement-Matrix
- `.claude/architecture.md` — Docker, DB, Ordnerstruktur, Patterns

## Verbote

- Kein React — Svelte 5 oder plain JS
- Kein `DateTime` — nur `DateTimeImmutable`
- Kein `var_dump`/`dump`/`dd`
- Kein `ignoreErrors` in phpstan.neon
- Kein `empty()`
- Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`

## SmartHabit-Kontext

- **Design: Neo Utility** — Sora + JetBrains Mono, Progress-Bar, Tags, Light/Dark Mode
- **Kein Firebase** — Web Push (RFC 8030), ntfy (self-hosted), APNs direkt
- **Household Isolation** — Security Voter prüft jeden API-Zugriff auf Household-Scope
- **DSGVO ab Tag 1** — Consent-Tracking, Export, Löschung, Aufbewahrungsfristen
- **Zeitfenster als Lokalzeit** — PostgreSQL `TIME`, DST-safe
- **Domain-Ordner**: `src/Habit/`, `src/Notification/`, `src/Auth/`, `src/Household/`, `src/Stats/`, `src/Shared/`

## Dokumentation

Projektspezifische Spezifikationen in `docs/`:

| Datei | Inhalt |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | Docker, Timezone, Datenmodell, API Endpoints |
| [`docs/coding-guidelines.md`](docs/coding-guidelines.md) | Erweiterte Guidelines (SmartHabit-spezifisch) |
| [`docs/frontend.md`](docs/frontend.md) | SvelteKit PWA, Design-System (Neo Utility), i18n |
| [`docs/notifications.md`](docs/notifications.md) | Push-Architektur (Web Push, ntfy, APNs) |
| [`docs/security.md`](docs/security.md) | DSGVO, Auth-Flows, Rate Limiting, E-Mail |
| [`docs/testing.md`](docs/testing.md) | PHPStan-Config, Test-Strategie, CI Pipeline |
| [`docs/infrastructure.md`](docs/infrastructure.md) | Hetzner Deployment, GlitchTip, Backups |
| [`docs/native.md`](docs/native.md) | Capacitor, Widgets, PWA Shortcuts |
| [`docs/statistics.md`](docs/statistics.md) | Stats, Analytics, Heatmaps |
| [`docs/phases.md`](docs/phases.md) | Phasenplan (Phase 0 + 9 Phasen) |
| [`docs/phase0-template.md`](docs/phase0-template.md) | GitHub Repo Template Spezifikation (Index → `docs/phase0/`) |
| [`docs/mockups.html`](docs/mockups.html) | User-Flow Wireframes (4 Flows, Phone-Frames) |
| [`docs/designs.html`](docs/designs.html) | Design-Vorschläge (Neo Utility gewählt) |
| [`docs/FULL_PLAN.md`](docs/FULL_PLAN.md) | Kompletter monolithischer Plan (Referenz) |
