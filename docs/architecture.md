# Architecture
## Docker Setup

Based on `dunglas/symfony-docker` — the official Symfony Docker template with FrankenPHP. Four services (+ Messenger Worker), one `compose.yaml`:

```
services:
  php:              # FrankenPHP (Caddy + PHP 8.4) — Web + Worker in one
  database:         # PostgreSQL 17
  pgbouncer:        # Connection Pooling → database:5432
  bun:              # Bun 1.3.x — SvelteKit Dev Server (dev profile only)
```

### Why FrankenPHP instead of PHP-FPM + nginx

FrankenPHP replaces PHP-FPM **and** nginx in a single binary. Caddy is built in (auto-HTTPS via Let's Encrypt, HTTP/2, HTTP/3). The key advantage is **Worker Mode**: Symfony boots once, stays in memory, and handles requests without re-bootstrapping. This eliminates the biggest performance overhead in PHP.

Specifically eliminated: `php-fpm` container, `nginx` container, nginx config, FPM pool tuning, FastCGI socket communication. Instead, one container does everything.

### `dunglas/symfony-docker` as base

The project is initially created via the template:

```bash
docker compose build --pull --no-cache
docker compose up
```

The Dockerfile follows the multi-stage pattern from the template:

```
# Stage: frankenphp_base
FROM dunglas/frankenphp:php8.4 AS frankenphp_base
  → ext-pdo_pgsql, ext-intl (via install-php-extensions)
  → Composer install

# Stage: frankenphp_dev
  → Xdebug for step-debugging and coverage
  → Hot-Reload via Caddy file_server + watch

# Stage: frankenphp_prod (rootless, Debian Slim — 290MB instead of 704MB)
  → Only runtime artifacts, no Composer, no dev tooling
  → Worker Mode: FRANKENPHP_CONFIG="worker ./public/index.php"
```

Since March 2026 the template uses rootless Debian Slim images in production — 60% smaller than before.

> **Note on code coverage**: FrankenPHP ships ZTS (Zend Thread Safety) PHP builds.
> PCOV only supports NTS (Non-Thread-Safe) PHP, so it cannot be used here.
> Coverage is collected via Xdebug with `XDEBUG_MODE=coverage` instead.

### Service Details

**php** — `dunglas/frankenphp:php8.4`:
- Worker Mode in production: PHP stays in memory, Symfony boots once
- Caddy handles TLS termination, HTTP/2, HTTP/3, static files
- Routes: `/api/*` → Symfony, everything else → SvelteKit build output (via `file_server`)
- For the Messenger Worker: second container with the same image, different command

**⚠️ Worker Mode Caveat**: In Worker Mode the Symfony Kernel stays in memory between requests. This means: Doctrine's Identity Map must be cleared after each request (`EntityManager::clear()`), static variables in services survive requests (do not store request-specific data in static properties), and memory leaks accumulate. The `dunglas/symfony-docker` template handles most of this automatically via `SwooleRuntime`/`FrankenPHPRuntime`, but custom services must take care not to cache request data as class properties.

**database** — `postgres:17-alpine`, volume for persistence, `POSTGRES_DB=app`

**pgbouncer** — `edoburu/pgbouncer:latest`:
- Transaction-mode pooling: each query gets a connection, returns it afterwards
- Especially important in Worker Mode: FrankenPHP workers hold persistent connections, PgBouncer prevents connection exhaustion
- `max_client_conn=200`, `default_pool_size=20` as starting values
- `DATABASE_URL=postgresql://user:pass@pgbouncer:5432/app?serverVersion=17`
- Important: Doctrine's `server_version` must still be set to PG 17, not PgBouncer
- **⚠️ Prepared Statements**: PgBouncer transaction mode does not support prepared statements (connection goes to another client after transaction). Fix: `PGBOUNCER_SERVER_RESET_QUERY=DISCARD ALL` as ENV in PgBouncer config. Without this, errors or performance overhead occur because PgBouncer has to re-parse statements every time.

**bun** — `oven/bun:1.3-alpine`, only active in `dev` profile:
- Mounts the `frontend/` directory, `bun run dev` with HMR
- Production build: `bun run build` → static files, served by Caddy via `file_server`
- No Symfony AssetMapper — frontend is completely standalone, built via Bun/Vite
- `bun install` instead of `npm install` (~25x faster)

### Frontend Build Pipeline (no Symfony AssetMapper)

The frontend is a **standalone SvelteKit project** in the `frontend/` directory, completely decoupled from Symfony. No AssetMapper, no Webpack Encore, no Symfony-side asset management.

```
Frontend Build: Bun + Vite (via SvelteKit)
├── Dev:  bun run dev → Vite Dev Server with HMR on port 5173
├── Build: bun run build → adapter-static → /frontend/build/
└── Deploy: Caddy file_server serves /frontend/build/ for all non-API routes
```

Why no AssetMapper: AssetMapper is designed for server-rendered Symfony apps (Twig templates). Our app is a SPA — Symfony only provides a JSON API, the frontend is a standalone build. Bun + Vite (via SvelteKit) is the direct approach without Symfony overhead.

Caddy config in the FrankenPHP container:
```
# Caddyfile excerpt
{$SERVER_NAME} {
    # API → Symfony/FrankenPHP
    handle /api/* {
        php_server
    }
    # Everything else → SvelteKit Static Build
    handle {
        root * /app/frontend/build
        try_files {path} /index.html
        file_server
    }
}
```

### Messenger Worker

Same FrankenPHP container, different entrypoint — as a separate service in `compose.yaml`:

```yaml
  messenger-worker:
    build:
      context: .
      target: frankenphp_dev  # or frankenphp_prod
    command: ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600"]
    restart: unless-stopped
    depends_on:
      - database
    environment:
      # IMPORTANT: Messenger Worker connects DIRECTLY to the DB, not via PgBouncer!
      # PgBouncer transaction mode does not support LISTEN/NOTIFY (Doctrine Messenger needs this)
      DATABASE_URL: postgresql://...@database:5432/app?serverVersion=17
```

Restart policy: `unless-stopped` — Symfony exits after 1h (`--time-limit`), Docker restarts it. Prevents memory leaks.

### Cron

In the `php` container via separate cron service or Supercronic (Go-based, Docker-friendly):
- Every 15min: `php bin/console app:check-habits`
- Nightly 03:00 UTC: `php bin/console app:learn-timewindows`
- Nightly 03:30 UTC: `php bin/console app:compute-stats` (Phase 5)
- Nightly 04:00 UTC: `php bin/console app:cleanup-push-subscriptions`
- Nightly 04:30 UTC: `php bin/console app:cleanup-old-logs` (GDPR retention periods)

Environment via `.env` + `.env.local` (Symfony standard), secrets in Docker Secrets or `.env.local` on the server.

## Timezone Strategy

Everything in UTC — with one deliberate exception for time windows. Each user sets their timezone in their profile (required field at registration).

**Timestamps** (logged_at, sent_at, created_at): Always UTC. PostgreSQL columns as `TIMESTAMPTZ`, Doctrine type `datetimetz_immutable`.

**Time windows** (time_window_start/end): Stored as **local time** (PostgreSQL `TIME` without timezone). "07:00 walk the dog" is a human concept — converting it to UTC would be wrong because the UTC representation would change during DST transitions. Instead, 07:00 stays as 07:00 in the database.

**Notification check** (the core of the timezone logic):
1. Cron runs every 15min, knows the current UTC time
2. Per habit → per user in the household:
   - Convert current UTC time → to `User.timezone` → yields user local time
   - Check user local time against `Habit.time_window_start/end` (simple range check)
   - "Today" is relative to the user's local time (for the "already done?" check)
3. DST transitions are not a problem: PHP's `DateTimeZone` handles this automatically

**Display in the frontend**: The API delivers UTC timestamps. The frontend converts with `Intl.DateTimeFormat` and the user timezone (from JWT payload or `/api/v1/user/me`).

**Multi-timezone household**: Lisa in Berlin and Tom in New York share "walk the dog" with window 07:00-09:00. Lisa gets the notification when it is 07:00 in Berlin, Tom when it is 07:00 in New York. The habit has only one time window — the per-user conversion in the check handles the rest.

## Logging

Structured logging via Monolog (Symfony standard). Important because FrankenPHP Worker Mode, Messenger workers, and cron jobs need their own log channels.

- **Channels**: `app` (default), `messenger` (worker queue), `notification` (push dispatch), `security` (auth events)
- **Format**: JSON in prod (`monolog.formatter.json`), line-based in dev
- **Output**: `stderr` in Docker (ends up in `docker compose logs`), no file logging in the container
- **Worker Mode note**: `error_log()` goes to the Caddy log, not Monolog. Always inject the logger service, never use `error_log()` directly.
- **Log levels**: `INFO` for business events (habit logged, notification sent), `WARNING` for degraded operations (push failed, retry), `ERROR` for unexpected errors. No `DEBUG` in prod.

Config in `config/packages/monolog.yaml`:
```yaml
when@prod:
    monolog:
        handlers:
            main:
                type: stream
                path: "php://stderr"
                level: info
                formatter: monolog.formatter.json
            notification:
                type: stream
                path: "php://stderr"
                level: info
                channels: ["notification"]
                formatter: monolog.formatter.json
```

## API Versioning

All endpoints under `/api/v1/`. Versioning via URL prefix, not via header — easier to debug, cache, and route.

When v2 becomes necessary: run `/api/v2/` in parallel with `/api/v1/`. Keep the old version for a migration period (e.g. 6 months), then deprecate. Separate controller classes per version, shared services. This does not need to be built now — the important thing is that the URL prefix `v1` is included from the start, so the option exists.

## Data Model (Doctrine Entities)

### Household

The core concept. All habits belong to a household, multiple users share a household.

```
Household
├── id: uuid (Symfony Uid Component)
├── name: string
├── invite_code: string (unique, 8 characters)
├── created_at: DateTimeImmutable (UTC)
├── updated_at: DateTimeImmutable (UTC)
```

### User

```
User
├── id: uuid
├── household_id: uuid → Household (ManyToOne)
├── display_name: string
├── email: string (unique)
├── password: string (hashed, Symfony PasswordHasher)
├── email_verified_at: DateTimeImmutable (nullable — null = not verified)
├── timezone: string (required, e.g. "Europe/Berlin" — IANA Timezone)
├── locale: string (default "de", allowed: "de", "en")
├── theme: string (default "auto", allowed: "auto", "light", "dark")
├── push_subscriptions: json (array of {endpoint, keys, device_name, last_seen, type} objects)
├── consent_at: DateTimeImmutable (timestamp of GDPR consent)
├── consent_version: string (privacy policy version at consent, e.g. "1.0")
├── created_at: DateTimeImmutable (UTC)
├── updated_at: DateTimeImmutable (UTC)
```

`timezone` is a required field at registration. The frontend suggests the browser timezone (`Intl.DateTimeFormat().resolvedOptions().timeZone`), user can override. `locale` is also taken from the browser (`navigator.language`) and determines the language of notifications and API responses. `push_subscriptions` as a structured JSON array: each subscription contains the Web Push endpoint + VAPID keys (PWA) or an ntfy topic (native Android) or an APNs device token (native iOS). The `type` field distinguishes: `web_push`, `ntfy`, `apns`.

### Habit

```
Habit
├── id: uuid
├── household_id: uuid → Household (ManyToOne)
├── name: string
├── emoji: string
├── sort_order: int
├── notification_message: string (translation key or custom text, e.g. "habit.notification.dog_walk")
├── time_window_start: time (e.g. 07:00 — local time, NOT UTC)
├── time_window_end: time (e.g. 09:00 — local time, NOT UTC)
├── time_window_mode: enum(manual, auto)
├── frequency: enum(daily, weekdays, weekends, custom)
├── frequency_days: json (null or [1,2,3,4,5] for custom)
├── is_active: bool
├── created_at: DateTimeImmutable (UTC)
├── updated_at: DateTimeImmutable (UTC)
├── deleted_at: DateTimeImmutable (nullable — soft delete)
```

Time windows are deliberately stored as local time (pure `TIME` type without timezone in PG). Reason: "07:00 walk the dog" is a human concept, not a UTC value. The notification check converts each user's current UTC time to their local time and compares against this window. This way DST works automatically without recalculation.

### HabitLog

```
HabitLog
├── id: uuid
├── habit_id: uuid → Habit (ManyToOne)
├── user_id: uuid → User (ManyToOne)
├── logged_at: DateTimeImmutable (UTC, frontend converts for display)
├── source: enum(manual, notification) — where did the log come from?
```

### NotificationLog

For debugging and to prevent duplicate notifications:

```
NotificationLog
├── id: uuid
├── habit_id: uuid → Habit
├── user_id: uuid → User
├── sent_at: DateTimeImmutable (UTC)
├── status: enum(sent, failed, clicked)
├── push_message_id: string (nullable)
├── error_reason: string (nullable — for push error details)
├── push_type: string (web_push, ntfy, apns)
```

## API Endpoints (Symfony Controller)

All routes under `/api/v1`, JSON responses, JWT auth via `lexik/jwt-authentication-bundle`.

### Auth & User

```
POST   /api/v1/register          — Create new user + household (timezone, locale required)
POST   /api/v1/login             — JWT access token + refresh token
POST   /api/v1/token/refresh     — Refresh token → new access token
POST   /api/v1/household/join    — Join a household with invite_code
GET    /api/v1/user/me           — Profile incl. timezone, locale, theme, household, push_subscriptions
PUT    /api/v1/user/me           — Edit profile (display_name, timezone, locale, theme)
POST   /api/v1/user/push-subscription    — Register push subscription (Web Push, ntfy, APNs)
DELETE /api/v1/user/push-subscription    — Remove push subscription (logout/device change)
POST   /api/v1/password/forgot           — Request password reset (email with token)
POST   /api/v1/password/reset            — Reset password (token + new password)
PUT    /api/v1/user/password             — Change password (old + new password, logged in)
```

### Habits

```
GET    /api/v1/habits                — All habits of the household + daily status
POST   /api/v1/habits                — Create new habit
PUT    /api/v1/habits/{id}           — Edit habit (name, time window, etc.)
DELETE /api/v1/habits/{id}           — Delete habit (soft delete)
PATCH  /api/v1/habits/reorder        — Change sort order
```

### Logging

```
POST   /api/v1/habits/{id}/log       — One-tap log (body: only source)
DELETE /api/v1/habits/{id}/log/{logId} — Undo log
GET    /api/v1/habits/{id}/history    — Paginated history (who, what, when)
```

### Dashboard

```
GET    /api/v1/dashboard             — Daily overview: all habits with last log
```

The dashboard response is what the app loads on open — a single query that delivers the current status per habit for today.

### Statistics (Phase 5)

```
GET    /api/v1/habits/{id}/stats     — Streak, completion rate, trend, average time, user distribution
GET    /api/v1/stats/household       — Overall completion, ranking, heatmaps, most active user
```

### GDPR & Account

```
GET    /api/v1/user/export           — Data export (Art. 20 GDPR): all user data as JSON
DELETE /api/v1/user/me               — Account deletion (Art. 17 GDPR): cascade
GET    /api/v1/privacy               — Current privacy policy (version + text)
```

### Health (no auth required)

```
GET    /api/v1/health                — DB connection + Messenger queue status
GET    /api/v1/health/ready          — For Docker healthcheck (just "up?")
```
