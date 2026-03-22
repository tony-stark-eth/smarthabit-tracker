# SmartHabit Tracker — Project Plan

## Tech Stack (Current Versions)

| Component | Version | Notes |
|---|---|---|
| **PHP** | 8.4 | Property hooks, asymmetric visibility |
| **Symfony** | 8.0.7 | Invokable commands, PHP array config, Attribute-based validation |
| **Runtime** | FrankenPHP (Caddy) | Worker Mode, auto-HTTPS, HTTP/2+3 — no nginx, no PHP-FPM |
| **Doctrine ORM** | 3.6.x | via `symfony/orm-pack` v2.7 |
| **Database** | PostgreSQL 17 | Better than MySQL for time window queries (range types, window functions) |
| **Frontend** | Svelte 5 + SvelteKit 2.x | PWA via `@vite-pwa/sveltekit`, Svelte 5 Runes, Bun as runtime |
| **Push (PWA)** | `minishlink/web-push` | W3C Web Push Standard (RFC 8030), no Firebase, no Google account |
| **Push (Native)** | APNs (iOS) + ntfy (Android, self-hosted) | No Firebase. ntfy on Hetzner VPS, APNs directly via Symfony Notifier |
| **Testing** | PHPUnit 13.x + Infection 0.32.x | Path Coverage, sealed test doubles, Mutation Testing |
| **Static Analysis** | PHPStan (max level), Rector, ECS | No compromises on code quality |
| **Auth** | `lexik/jwt-authentication-bundle` | Access + Refresh Token Flow |
| **Queue** | Symfony Messenger | Doctrine transport (no Redis needed for MVP) |
| **Connection Pooling** | PgBouncer 1.23 | Transaction mode, between FrankenPHP and PostgreSQL |
| **i18n** | paraglide-sveltekit + Symfony Translator | Compile-time i18n in frontend, YAML-based in backend |
| **Native (later)** | Capacitor 6.x | SvelteKit → iOS/Android App Store, native widgets |
| **E-Mail** | Symfony Mailer + Brevo | 300 mails/day free, GDPR-compliant (EU), official Symfony Bridge |
| **Monitoring** | GlitchTip 6.x (self-hosted) | Sentry-compatible SDKs, 512MB RAM, MIT license |
| **Real-Time** | Mercure (in Caddy/FrankenPHP) | SSE for live updates in household, already included in template |
| **Deployment** | Hetzner VPS + OpenTofu | Infrastructure as Code, CD via GitHub Actions (later) |
| **Docker** | Compose v2 | Multi-stage builds for PHP + Bun |
| **API Format** | Plain JSON | Symfony Serializer, no API Platform |

## Docker Setup

Based on `dunglas/symfony-docker` — the official Symfony Docker template with FrankenPHP. Four services (+ Messenger Worker), one `compose.yaml`:

```
services:
  php:              # FrankenPHP (Caddy + PHP 8.4) — web + worker in one
  database:         # PostgreSQL 17
  pgbouncer:        # Connection Pooling → database:5432
  bun:              # Bun 1.3.x — SvelteKit dev server (dev profile only)
```

### Why FrankenPHP Instead of PHP-FPM + nginx

FrankenPHP replaces PHP-FPM **and** nginx in a single binary. Caddy is built in (auto-HTTPS via Let's Encrypt, HTTP/2, HTTP/3). The key advantage is **Worker Mode**: Symfony boots once, stays in memory, and handles requests without re-bootstrapping. This eliminates the biggest performance overhead in PHP.

Specifically eliminated: `php-fpm` container, `nginx` container, nginx config, FPM pool tuning, FastCGI socket communication. Instead, one container does everything.

### `dunglas/symfony-docker` as Base

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
  → Xdebug with XDEBUG_MODE=coverage
  → Hot-Reload via Caddy file_server + watch

# Stage: frankenphp_prod (rootless, Debian Slim — 290MB instead of 704MB)
  → Only runtime artifacts, no Composer, no dev tooling
  → Worker Mode: FRANKENPHP_CONFIG="worker ./public/index.php"
```

Since March 2026 the template uses rootless Debian Slim images in production — 60% smaller than before.

### Service Details

**php** — `dunglas/frankenphp:php8.4`:
- Worker Mode in production: PHP stays in memory, Symfony boots once
- Caddy handles TLS termination, HTTP/2, HTTP/3, static files
- Routes: `/api/*` → Symfony, everything else → SvelteKit build output (via `file_server`)
- For the Messenger Worker: second container with the same image, different command

**database** — `postgres:17-alpine`, volume for persistence, `POSTGRES_DB=app`

**pgbouncer** — `edoburu/pgbouncer:latest`:
- Transaction-mode pooling: each query gets a connection, returns it afterwards
- Especially important in Worker Mode: FrankenPHP workers hold persistent connections, PgBouncer prevents connection exhaustion
- `max_client_conn=200`, `default_pool_size=20` as starting values
- `DATABASE_URL=postgresql://user:pass@pgbouncer:5432/app?serverVersion=17`
- Important: Doctrine's `server_version` must still be set to PG 17, not PgBouncer

**bun** — `oven/bun:1.3-alpine`, only active in `dev` profile:
- Mounts the `frontend/` directory, `bun run dev` with HMR
- Production build: `bun run build` → static files, served by Caddy via `file_server`
- No Symfony AssetMapper — frontend is completely standalone, build via Bun/Vite
- `bun install` instead of `npm install` (~25x faster)

### Frontend Build Pipeline (No Symfony AssetMapper)

The frontend is a **standalone SvelteKit project** in the `frontend/` directory, completely decoupled from Symfony. No AssetMapper, no Webpack Encore, no Symfony-side asset management.

```
Frontend Build: Bun + Vite (via SvelteKit)
├── Dev:  bun run dev → Vite Dev Server with HMR on port 5173
├── Build: bun run build → adapter-static → /frontend/build/
└── Deploy: Caddy file_server serves /frontend/build/ for all non-API routes
```

Why no AssetMapper: AssetMapper is designed for server-rendered Symfony apps (Twig templates). Our app is an SPA — Symfony only provides a JSON API, the frontend is a standalone build. Bun + Vite (via SvelteKit) is the direct path without Symfony overhead.

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

Restart policy: `unless-stopped` — Symfony exits after 1h (`--time-limit`), Docker restarts. Prevents memory leaks.

### Cron

In the `php` container via a separate cron service or Supercronic (Go-based, Docker-friendly):
- Every 15min: `php bin/console app:check-habits`
- Nightly 03:00 UTC: `php bin/console app:learn-timewindows`
- Nightly 03:30 UTC: `php bin/console app:compute-stats` (Phase 5)
- Nightly 04:00 UTC: `php bin/console app:cleanup-push-subscriptions`
- Nightly 04:30 UTC: `php bin/console app:cleanup-old-logs` (GDPR retention periods)

Environment via `.env` + `.env.local` (Symfony standard), secrets in Docker Secrets or `.env.local` on server.

## Timezone Strategy

Everything in UTC — with one deliberate exception for time windows. Each user sets their timezone in the profile (required field during registration).

**Timestamps** (logged_at, sent_at, created_at): Always UTC. PostgreSQL columns as `TIMESTAMPTZ`, Doctrine type `datetimetz_immutable`.

**Time windows** (time_window_start/end): Stored as **local time** (PostgreSQL `TIME` without timezone). "07:00 walk the dog" is a human concept — converting it to UTC would be wrong because the UTC representation would change during DST transitions. Instead, 07:00 stays as 07:00 in the DB.

**Notification check** (the core of the timezone logic):
1. Cron runs every 15min, knows the current UTC time
2. Per habit → per user in the household:
   - Convert current UTC time → to `User.timezone` → yields user local time
   - Check user local time against `Habit.time_window_start/end` (simple range check)
   - "Today" is relative to the user's local time (for the "already done?" check)
3. DST transitions are not a problem: PHP's `DateTimeZone` handles this automatically

**Display in frontend**: API delivers UTC timestamps. The frontend converts with `Intl.DateTimeFormat` and the user's timezone (from JWT payload or `/api/v1/user/me`).

**Multi-timezone household**: Lisa in Berlin and Tom in New York share "walk the dog" with window 07:00-09:00. Lisa gets the notification when it's 07:00 in Berlin, Tom when it's 07:00 in New York. The habit has only one time window — the per-user conversion in the check handles the rest.

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
├── consent_version: string (version of privacy policy at consent, e.g. "1.0")
├── created_at: DateTimeImmutable (UTC)
├── updated_at: DateTimeImmutable (UTC)
```

`timezone` is a required field during registration. The frontend suggests the browser timezone (`Intl.DateTimeFormat().resolvedOptions().timeZone`), the user can override. `locale` is also taken from the browser (`navigator.language`) and determines the language of notifications and API responses. `push_subscriptions` as a structured JSON array: each subscription contains the Web Push endpoint + VAPID keys (PWA) or an ntfy topic (native Android) or an APNs device token (native iOS). The `type` field distinguishes: `web_push`, `ntfy`, `apns`.

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

Time windows are deliberately stored as local time (plain `TIME` type without timezone in PG). Reason: "07:00 walk the dog" is a human concept, not a UTC value. The notification check converts each user's current UTC time to their local time and compares against this window. This way DST works automatically without recalculation.

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
POST   /api/v1/login             — JWT Access Token + Refresh Token
POST   /api/v1/token/refresh     — Refresh Token → new Access Token
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
GET    /api/v1/dashboard             — Daily overview: all habits with latest log
```

The dashboard response is what the app loads on open — a single query that delivers today's status per habit.

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

## Frontend (SvelteKit PWA)

### Architecture

SvelteKit in **SPA mode** (`adapter-static`), deployed as static files behind FrankenPHP/Caddy. No SSR needed — the app is completely client-side after the initial load. Build entirely via Bun, no Symfony AssetMapper.

```
frontend/
├── src/
│   ├── lib/
│   │   ├── api/          — Fetch wrapper, JWT handling, offline queue
│   │   ├── stores/       — Svelte 5 Runes ($state, $derived) for global state
│   │   └── components/   — UI components
│   ├── routes/
│   │   ├── +layout.svelte     — Shell: auth check, nav
│   │   ├── (app)/
│   │   │   ├── +page.svelte         — Dashboard (main view)
│   │   │   ├── habits/[id]/+page.svelte  — History + Stats
│   │   │   └── settings/+page.svelte     — Household, time windows, account
│   │   └── (auth)/
│   │       ├── login/+page.svelte
│   │       └── register/+page.svelte
│   ├── service-worker.ts     — Web Push handler + offline cache
│   └── app.html
├── static/
│   └── manifest.json         — PWA Manifest
├── bun.lock                  — Bun lockfile (instead of package-lock.json)
├── svelte.config.js
└── vite.config.ts
```

### PWA Features

- **Service Worker**: `@vite-pwa/sveltekit` for automatic caching
- **Offline capability**: Logs are stored locally and synced when online
- **Install Prompt**: manifest.json with icons, theme color, start URL
- **Push**: W3C Web Push API in the service worker (no Firebase SDK, VAPID auth)

### UI Principle

Dashboard = a list of large tap targets. Each habit is a card:
- Emoji + Name
- Last log: "Today 07:32 by Lisa" or "Not yet done"
- One tap → POST /log → optimistic UI update → scale-pulse checkmark animation
- Long press → History

No hamburger menu, no overhead. Open → tap → done.

### Design Direction: Neo Utility + Dark Mode

**Core aesthetic**: Functional, clear, data-driven. The app should feel like a well-built tool — not a wellness app. Information density without chaos, clear hierarchy, everything scannable at a glance.

**Typography**:
- Headlines: `Sora` (sans-serif, geometric, modern) — 700 weight
- Body: `Sora` — 400/600 weight
- Metadata + monospace details: `JetBrains Mono` — timestamps, window times, stats
- No serifs, no playful lettering — this is a utility tool

**Color Palette Light Mode**:
```
--bg-primary:    #F4F5F7     (background)
--bg-card:       #FFFFFF     (cards)
--bg-header:     #FFFFFF     (header)
--border:        #E8EAEE     (dividers, card borders)
--text-primary:  #1A1D24     (headlines, habit names)
--text-secondary:#888D98     (subtitles, metadata)
--text-tertiary: #B0B4BC     (timestamps, nav inactive)
--accent:        #3366FF     (primary action, progress, active nav)
--success:       #22C55E     (done, checkmarks, done border)
--warning:       #E65100     (overdue time window)
--tag-blue-bg:   #F0F4FF     (time window tag background)
--tag-orange-bg: #FFF3E0     (overdue tag background)
```

**Color Palette Dark Mode** (toggle in settings, respects `prefers-color-scheme`):
```
--bg-primary:    #0A0A0A     (background)
--bg-card:       #141416     (cards)
--bg-header:     #0A0A0A     (header)
--border:        #1E1E22     (dividers)
--text-primary:  #E8E8E8     (headlines)
--text-secondary:#666A74     (subtitles)
--text-tertiary: #444650     (timestamps)
--accent:        #4D88FF     (primary action, slightly brighter than light)
--success:       #5ACA46     (done)
--warning:       #FF8A50     (overdue)
--tag-blue-bg:   #1A1E2E     (time window tag)
--tag-orange-bg: #2A1A0E     (overdue tag)
```

**Core Elements of the Neo Utility Design**:
- **Progress bar in header**: Shows daily progress (2 of 4 = 50%). Visual feedback without reading numbers
- **Colored tags/badges**: Time window as chip (`18:00–20:00` on blue background), overdue on orange
- **Left-border indicator**: Completed habits have a 3px green left border, open ones a gray one — scannable without reading text
- **Four nav items**: Today, History, Stats, Config — Stats as its own tab instead of hidden in settings
- **Monospace for data**: All times, dates, stats numbers in JetBrains Mono — technical, precise, readable

**Dark Mode Implementation**:
- CSS Custom Properties + `prefers-color-scheme` media query as default
- User can override in settings: Auto / Light / Dark
- Preference stored in `User.theme: enum(auto, light, dark)`
- Svelte: `$state` store that sets the CSS class on `<body>`
- No separate stylesheet — same components, only variable swap

**Micro-Interactions**:
- Tap on check button: short scale-pulse (0.9 → 1.1 → 1.0) + color transition to green
- Progress bar animates smoothly on update (CSS `transition: width 0.4s ease-out`)
- Cards appear staggered on first load (`animation-delay` per card)
- No confetti, no bouncing elements — the tool stays matter-of-fact

## Notification System

### Push Architecture (No Firebase)

Three push channels, all without US services:

**1. Web Push (PWA)** — W3C Standard (RFC 8030), via `minishlink/web-push`:
- Generate VAPID key pair: `openssl ecparam -genkey -name prime256v1 -out private.pem` (one-time)
- Public key in the frontend for `PushManager.subscribe()`, private key in the backend for `minishlink/web-push`
- Keys as environment variables: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` (not in the repo!)
- Browser asks user for permission → creates PushSubscription (endpoint + keys)
- Frontend sends subscription to `POST /api/v1/user/push-subscription`
- Backend sends encrypted payloads directly to the browser endpoint (VAPID auth)
- Works on Chrome, Firefox, Edge, Safari 16.4+ — without Firebase SDK
- Browsers route internally through their own push services (Google/Mozilla/Apple), but that is browser infrastructure, not Firebase

**2. ntfy (Native Android)** — self-hosted on the Hetzner VPS:
- Lightweight open-source push server (~10MB binary, minimal RAM)
- App subscribes to a user-specific ntfy topic (e.g. `smarthabit-user-{uuid}`)
- Backend publishes via simple HTTP POST: `POST https://ntfy.smarthabit.de/{topic}`
- Supports UnifiedPush protocol (open standard, no Google)
- Docker container alongside the app on the same VPS

**3. APNs (Native iOS)** — Apple Push Notification service directly:
- Only way for iOS push, but no Firebase in between needed
- `symfony/notifier` has a native APNs transport
- Direct HTTP/2 connection to api.push.apple.com with JWT auth

### Cron-Based Notification Check

A cron job dispatches a `CheckHabitsMessage` every 15 minutes:

```
Cron (every 15min)
  → bin/console app:check-habits
    → Load all active habits with household + users (JOIN)
    → Per habit, per user in the household:
      → Calculate user local time: UTC now → User.timezone
      → Is user local time within Habit.time_window_start/end?
      → Is today an active day? (check frequency + frequency_days)
      → Was it already done today (in user local time!)? (HabitLog check)
      → Was a notification already sent to this user today?
      → If no: dispatch NotifyHabitMessage to Messenger
        → Handler: push to all subscriptions of this user
          → Web Push subscriptions → minishlink/web-push
          → ntfy subscriptions → HTTP POST to ntfy server
          → APNs subscriptions → Symfony Notifier APNs transport
```

Through the per-user check, multi-timezone works correctly: Lisa in Berlin gets the notification at 07:00 CET, Tom in New York at 07:00 EST — even though it is the same habit.

### NotifyHandler (Multi-Transport)

```php
// Simplified logic
foreach ($user->getPushSubscriptions() as $subscription) {
    match ($subscription['type']) {
        'web_push' => $this->webPushService->send($subscription, $payload),
        'ntfy'     => $this->ntfyClient->publish($subscription['topic'], $payload),
        'apns'     => $this->apnsTransport->send($subscription['device_token'], $payload),
    };
}
```

Each transport has its own error handling. Failed subscriptions are logged in the NotificationLog with `error_reason` and `push_type`.

### Auto Time Windows (Learning)

A nightly command (`app:learn-timewindows`) analyzes the logs of the last 21 days:

```
1. Collect all logged_at (UTC) times for a habit
2. Per log: convert UTC → User.timezone, then minutes since midnight
3. Calculate median = typical local time
4. MAD (Median Absolute Deviation) = more robust than stddev against outliers
5. Window = Median +/- (1.5 x MAD), minimum 30min wide
6. Weekday/weekend split if pattern detected (>= 7 data points per group)
7. Update Habit.time_window_start/end (as local time), time_window_mode → auto
```

Why MAD instead of standard deviation: A single outlier (dog walked at 23:00 because guests were over) shifts stddev massively. MAD is robust against this.

User can switch back to manual at any time.

### ntfy Docker Setup

Runs as an additional service on the Hetzner VPS, own Compose or in the app stack:

```yaml
ntfy:
  image: binwiederhier/ntfy:latest
  command: serve
  restart: unless-stopped
  ports: ["127.0.0.1:2586:80"]  # internal only, Caddy proxies
  volumes:
    - ntfy-cache:/var/cache/ntfy
    - ./ntfy/server.yml:/etc/ntfy/server.yml
  environment:
    TZ: UTC
```

Caddy route: `ntfy.smarthabit.de` → `ntfy:80` with auto-HTTPS. Auth via token-based access control — only the app server may publish, users subscribe via their topics.

## Push Subscription Lifecycle

Push subscriptions are short-lived and differ per platform. Three mechanisms keep the list clean:

**1. Registration** — Frontend/app sends subscription on every start via `POST /api/v1/user/push-subscription`. Idempotent: existing subscription is updated (last_seen), new one is added.

```
push_subscriptions: [
  {
    "type": "web_push",
    "endpoint": "https://updates.push.services.mozilla.com/wpush/v2/...",
    "keys": { "p256dh": "...", "auth": "..." },
    "device_name": "Chrome Desktop",
    "last_seen": "2026-03-21T10:00:00Z"
  },
  {
    "type": "ntfy",
    "topic": "smarthabit-user-abc123",
    "device_name": "Android Lisa",
    "last_seen": "2026-03-20T08:00:00Z"
  },
  {
    "type": "apns",
    "device_token": "a1b2c3...",
    "device_name": "iPhone Lisa",
    "last_seen": "2026-03-21T09:00:00Z"
  }
]
```

**2. Error handling in NotifyHandler**:
- Web Push: HTTP 410 (Gone) → remove subscription. HTTP 429 → retry with backoff.
- ntfy: HTTP 5xx → retry. Topic does not exist → remove subscription.
- APNs: Status 410 (Unregistered) → remove subscription. Status 429 → retry.

**3. Nightly Cleanup** — `app:cleanup-push-subscriptions` (cron 04:00 UTC):
- Subscriptions with `last_seen` older than 30 days are removed
- Protects against forgotten devices (old phone, uninstalled app)
- Logs cleanup actions for debugging

**Logout** — `DELETE /api/v1/user/push-subscription` explicitly removes the subscription of the current device.

## Internationalization (i18n)

Bilingual from day 1: German + English. Retrofitting i18n is one of the most painful refactors — so we do it from the start.

### Frontend: paraglide-sveltekit

Paraglide (by Inlang) is the i18n standard for SvelteKit. Compiles translations at build time, zero runtime overhead, fully type-safe message keys.

```
frontend/
├── messages/
│   ├── de.json      — { "dashboard.greeting": "Guten Morgen 👋", ... }
│   └── en.json      — { "dashboard.greeting": "Good morning 👋", ... }
├── src/
│   ├── lib/i18n/    — paraglide Runtime (generiert)
│   └── ...
```

Usage in Svelte code:
```svelte
<script>
  import * as m from '$lib/i18n/messages';
</script>
<h1>{m.dashboard_greeting()}</h1>
```

Advantages over runtime i18n libraries: tree-shaking (unused messages are eliminated), TypeScript autocomplete for all keys, no JSON parsing at runtime.

**Language detection**: `Accept-Language` header → browser language as default. User can override in settings. Language preference is stored in the `User` entity (`locale: string`, default `de`).

### Backend: Symfony Translator

Symfony's built-in Translator for everything coming from the server:

```
translations/
├── messages.de.yaml   — notification_texts, validation_messages, error_messages
└── messages.en.yaml
```

Relevant for: notification texts ("Warst du heute schon mit dem Hund draussen?" / "Have you walked the dog today?"), validation errors (API responses), email templates (if needed later).

The `Habit.notification_message` field becomes a translation key instead of hard-coded text. The NotifyHandler resolves the key against the target user's language.

### User Entity Extension

The fields `locale`, `theme`, `consent_at`, `consent_version` and `email_verified_at` are already defined in the main data model (see Data Model section). API responses contain `locale` and `theme` in the JWT payload and in the `/api/v1/user/me` response. The frontend sets language and theme based on this.

## Statistics & Analytics

Statistics come as a standalone Phase 5, after the learning system (Phase 4) delivers data.

### API Endpoints

```
GET /api/v1/habits/{id}/stats          — Stats per habit
GET /api/v1/stats/household            — Household-wide overview
GET /api/v1/stats/user/{id}            — Stats per user (optional)
```

### Stats Per Habit

- **Streak**: Current and longest series of consecutive days (timezone-aware, based on user local time)
- **Completion Rate**: Percentage of days in the last month / quarter / year on which the habit was completed — relative to `frequency`/`frequency_days`
- **Average Time**: Median of log times (same logic as TimeWindowLearner)
- **Trend**: Is the habit being completed earlier/later than 30 days ago? Is the completion rate rising/falling?
- **Distribution by User**: Who completes the habit how often? (Percentage per household member)

### Household Dashboard

- **Overall Completion Rate**: All habits aggregated
- **Most Active User**: Who logs the most?
- **Habit Ranking**: Which habits are completed most reliably, which are often forgotten?
- **Weekday Heatmap**: On which days are habits forgotten most often?
- **Time Heatmap**: At which times is the most logging done? (Aggregated across all habits)

### Technical Implementation

Base stats (streak, completion rate) are calculated on-the-fly from `HabitLog` — with a few hundred logs per habit this is performant enough as a PostgreSQL query with window functions.

For the analytics dashboard (heatmaps, trends, distributions) a **Materialized View** or a separate `HabitStats` table filled nightly by the `app:compute-stats` command is worthwhile. This avoids expensive aggregations on every dashboard load.

Frontend: Charts via a lightweight Svelte chart library (e.g. `layerchart` or `pancake`). Heatmaps as SVG grid, no heavy chart framework needed.

## Native App & Widgets (Incremental)

### Strategy: PWA-first, Incremental Native Features

Three stages from simple to complex. Each stage delivers standalone value — you do not need to reach stage 3 for the effort to pay off.

### Stage 1 — PWA Shortcuts (Zero Native Code, From Phase 2)

No Capacitor needed. The PWA manifest gets `shortcuts` entries:

```json
{
  "shortcuts": [
    {
      "name": "Log walk the dog",
      "short_name": "Dog",
      "url": "/log/dog?source=shortcut",
      "icons": [{ "src": "/icons/dog-96.png", "sizes": "96x96" }]
    },
    {
      "name": "Log dishwasher",
      "short_name": "Dishes",
      "url": "/log/dishwasher?source=shortcut",
      "icons": [{ "src": "/icons/dish-96.png", "sizes": "96x96" }]
    }
  ]
}
```

Result: Long-press on the SmartHabit icon shows quick actions. Tap opens the app directly on the log route for the habit. Works on Android (Chrome) and iOS (Safari 16.4+). No App Store needed, no native code — 30 minutes of work.

Dynamic shortcuts (based on the user's actual habits) are not possible as a PWA — manifest shortcuts are static. But the top 3 habits as fixed shortcuts cover 80% of the need.

### Stage 2 — Capacitor App + Store Deployment (No Widget Code)

The same SvelteKit build (`adapter-static`) in a native WebView:

```bash
bun add @capacitor/core @capacitor/cli
bunx cap init SmartHabit com.smarthabit.app --web-dir frontend/build
bunx cap add ios
bunx cap add android
```

Build flow:
```
bun run build          → frontend/build/ (static files)
bunx cap sync          → copies build into native projects
bunx cap open ios      → opens Xcode
bunx cap open android  → opens Android Studio
```

The code stays identical — Capacitor adds native projects (`ios/`, `android/`). What this provides: App Store / Play Store presence, native push via APNs (iOS) and ntfy (Android), and the foundation for Stage 3.

**App Store Deployment**:
- iOS: Xcode Build → TestFlight → App Store Connect
- Android: Android Studio Build → Play Console → Internal Testing → Production
- Push Notifications: APNs directly for iOS, ntfy for Android — no Firebase, no `@capacitor/push-notifications` needed

### Stage 3 — Native Widgets via `capacitor-widget-bridge` (Minimal Native Code)

The community plugin `capacitor-widget-bridge` handles the entire data bridge between app and widget. No custom Capacitor plugin needed.

**How it works:**

```
┌─────────────────────────────────────────────┐
│  SvelteKit App (JS)                         │
│                                             │
│  // Write habit data to SharedStorage       │
│  WidgetBridge.setItem({                     │
│    group: 'group.com.smarthabit',           │
│    key: 'habits',                           │
│    value: JSON.stringify(todayHabits)       │
│  });                                        │
│  WidgetBridge.reloadAllTimelines();         │
│                                             │
└──────────────────┬──────────────────────────┘
                   │ SharedDefaults (iOS)
                   │ SharedPreferences (Android)
                   ▼
┌─────────────────────────────────────────────┐
│  Native Widget                              │
│                                             │
│  iOS:  ~50-80 lines SwiftUI                │
│        → reads UserDefaults(suiteName:)    │
│        → shows habit list + tap buttons    │
│                                             │
│  Android: ~80-100 lines Kotlin + XML       │
│        → reads SharedPreferences            │
│        → RemoteViews Layout                │
│                                             │
└─────────────────────────────────────────────┘
```

**What you need to write yourself** (the plugin handles the rest):
- iOS: a SwiftUI Widget Extension (~50-80 lines) — reads JSON from UserDefaults, renders as list
- Android: an AppWidgetProvider (~80-100 lines Kotlin) + an XML layout — reads JSON from SharedPreferences

**What the plugin handles**:
- `setItem()` / `getItem()` / `removeItem()` — share data between JS and native widget
- `reloadAllTimelines()` / `reloadTimelines()` — trigger widget refresh programmatically
- Works on both platforms with the same JS API

**Data flow**:
1. App opens → `GET /api/v1/dashboard` → write habit data to SharedStorage via `WidgetBridge.setItem()`
2. Widget reads cached data directly from SharedStorage (no API call from widget)
3. Tap on "Done" in widget → opens app with deep link → app logs via API
4. After logging: `WidgetBridge.reloadAllTimelines()` → widget refreshes

Tap-to-log directly from the widget without opening the app is technically possible (via App Intents on iOS, PendingIntent on Android), but one iteration more complex. For v1 this suffices: widget shows status, tap opens app at the right habit.

**Widget sizes**:
- iOS: Small (1-2 habits, status only), Medium (4 habits with tap targets), Large (all habits)
- Android: 2x1 (compact), 4x2 (full)

### Optional: iOS Live Activities

Not prioritized, but interesting for the future: `capacitor-live-activity` plugin for temporary lockscreen displays. Example: "Dog has been outside for 45min" as a timer on the lockscreen + Dynamic Island. Cool feature, but niche — only after Stage 3 when the foundation is in place.

## GDPR & Data Privacy

Mandatory for the EU market. The app stores personal data: email, behavioral patterns (when is what done), household composition, device tokens. Must be considered from the start — retrofitting is a nightmare.

### API Endpoints

```
GET    /api/v1/user/export       — Data export (Art. 20 GDPR): all user data as JSON download
DELETE /api/v1/user/me           — Account deletion (Art. 17 GDPR): cascade to all logs, notifications, push subscriptions
GET    /api/v1/privacy           — Current privacy policy as JSON (version + text)
```

### Deletion Cascade

Account deletion removes: User entity, all HabitLogs of the user, all NotificationLogs of the user, all push subscriptions. Habits remain (they belong to the household, not the user). When the last user of a household is deleted → cascade delete household + all habits + all logs.

### Retention Periods

- **HabitLogs**: 1 year, then automatically anonymized (user_id → null) or deleted. Nightly command `app:cleanup-old-logs`.
- **NotificationLogs**: 90 days, then deleted (purely for debugging).
- **Account data**: until deletion by the user.
- Configurable via environment variables: `LOG_RETENTION_DAYS=365`, `NOTIFICATION_LOG_RETENTION_DAYS=90`.

### Consent

- At registration: checkbox "Privacy policy read and accepted" (required, stored with timestamp + version of the privacy policy).
- Push notifications: separate consent in the frontend (browser permission dialog + explicit opt-in in the app flow).
- Cookie banner: not needed — PWA uses no third-party cookies, only first-party JWT + localStorage.

### Privacy Policy

Must contain: data controller, purpose of processing (habit tracking, notifications), legal basis (consent Art. 6(1)(a)), recipients (Brevo for email, ntfy self-hosted for push), third-country transfer (Apple APNs for iOS push — USA, Standard Contractual Clauses; browser push endpoints for Web Push), storage duration, rights (access, deletion, export, withdrawal), contact details. Versioned as Markdown in the repo, retrievable via API. Advantage of the self-hosted approach: push data for Android never leaves the own server (ntfy on Hetzner DE).

## Security Hardening

### Rate Limiting

Symfony Rate Limiter on auth endpoints — without this the login endpoint is a brute-force target.

```php
// config/packages/rate_limiter.php
'login' => [
    'policy' => 'sliding_window',
    'limit' => 5,
    'interval' => '1 minute',
],
'register' => [
    'policy' => 'fixed_window',
    'limit' => 3,
    'interval' => '15 minutes',
],
'api_general' => [
    'policy' => 'sliding_window',
    'limit' => 60,
    'interval' => '1 minute',
],
```

Additionally: Caddy has built-in rate limiting (`rate_limit` directive) as a second layer before PHP.

### Extended Auth Flows

- **Email Verification**: After registration → verification email with token link. Account is restricted until verified (can log, but not invite). Symfony has `SymfonyCasts\Bundle\VerifyEmail\VerifyEmailBundle`.
- **Password Reset**: `POST /api/v1/password/forgot` → token via email → `POST /api/v1/password/reset` with token + new password. Token is valid for 1h, single use.
- **Password Change**: `PUT /api/v1/user/password` with old + new password (for logged-in users).

### Additional Measures

- **CORS**: Restrictive — own domain only, no wildcards.
- **CSP Headers**: Caddy config, `Content-Security-Policy` with strict `script-src`.
- **Input Validation**: Symfony Validator with attributes on all DTOs. No raw user input in queries.
- **Household Isolation**: Middleware/Voter that verifies every API access only concerns data of the user's own household. Unit-tested.
- **JWT Blacklist**: On password change/reset all existing refresh tokens are invalidated.

## Email (Symfony Mailer + Brevo)

### Why Brevo

Free (300 mails/day free tier), EU-based (GDPR-compliant), official Symfony Mailer Bridge (`symfony/brevo-mailer`). No own mail server, no SMTP setup, no deliverability problem. For a household app with few users, 300 mails/day is more than enough.

### Setup

```bash
composer require symfony/brevo-mailer
```

```
# .env
MAILER_DSN=brevo+api://API_KEY@default
```

### Mail Types

- **Verification email**: After registration, token link for confirmation
- **Password reset**: Token link, valid for 1h
- **Household invitation**: Optional — invite via email instead of just invite code
- **Welcome email**: After successful verification

### Async via Messenger

All emails go through Symfony Messenger (async). The Mailer is already Messenger-aware — `MAILER_DSN` + `messenger.yaml` config is enough. No custom handler needed. Emails never block the request.

### Templates

Symfony Mailer + Twig for email templates (yes, Twig — only for emails, not for the app). Bilingual via Symfony Translator: template uses `{{ 'email.verification.subject'|trans({}, 'messages', user.locale) }}`.

## Monitoring & Error Tracking (GlitchTip)

### Why GlitchTip Instead of Sentry

Self-hosted, MIT license, needs only 512MB RAM instead of Sentry's 16GB+. Uses the same Sentry SDKs — `sentry/sentry-symfony` for PHP, `@sentry/svelte` for the frontend. If you want to migrate to Sentry Cloud later, you only change the DSN.

### Docker Setup

GlitchTip runs as a separate Docker Compose stack on the same Hetzner VPS (or a second small VPS):

```yaml
services:
  glitchtip-web:
    image: glitchtip/glitchtip:v6
    depends_on: [glitchtip-db, glitchtip-redis]
    environment:
      DATABASE_URL: postgres://glitchtip:pass@glitchtip-db:5432/glitchtip
      VALKEY_URL: redis://glitchtip-redis:6379/0
      SECRET_KEY: ...
      GLITCHTIP_DOMAIN: https://errors.smarthabit.de
      DEFAULT_FROM_EMAIL: errors@smarthabit.de
      GLITCHTIP_MAX_EVENT_LIFE_DAYS: 90
    ports: ["8000:8000"]

  glitchtip-worker:
    image: glitchtip/glitchtip:v6
    command: bin/run-celery-with-beat.sh
    # same env as web

  glitchtip-db:
    image: postgres:16-alpine

  glitchtip-redis:
    image: redis:7-alpine
```

### Integration

```bash
# PHP
composer require sentry/sentry-symfony

# Frontend
bun add @sentry/svelte
```

```
# .env
SENTRY_DSN=https://key@errors.smarthabit.de/1
```

Symfony bundle auto-configures exception handling. In the frontend: `Sentry.init()` in `+layout.svelte`. Both report to the same GlitchTip instance.

### Health Checks

```
GET /api/v1/health         — DB connection + Messenger queue status
GET /api/v1/health/ready   — For Docker healthcheck (just "up?")
```

Docker healthcheck on the `php` container: `curl -f http://localhost/api/v1/health/ready`. If the cron container dies, notifications are missing — configure GlitchTip alerts for this.

### Structured Logging

Monolog with JSON formatter instead of plain text. Logs go to `stdout` (Docker standard), Docker logging driver collects them. No ELK stack needed for the MVP — `docker compose logs -f php` is enough. Important: log notification dispatch and push errors with structured fields (`habit_id`, `user_id`, `push_type`, `status`).

## Real-Time Updates (Mercure)

### Problem

Tom logs "walk the dog" → Lisa only sees it after a manual refresh. For a household app where multiple people track simultaneously this is bad.

### Solution: Mercure (Already in the Stack)

The `dunglas/symfony-docker` template has Mercure as a Caddy module already built in. Mercure is a Server-Sent-Events (SSE) hub that runs in Caddy — no additional service, no WebSocket server, no Socket.io.

### Data Flow

```
Lisa logs "walk the dog"
  → POST /api/v1/habits/{id}/log
  → Controller saves log
  → Controller publishes Mercure update:
      Topic: /households/{householdId}/habits
      Data: { habitId, userId, loggedAt, userName }
  → Tom's browser has SSE subscription on this topic
  → Dashboard updates automatically
```

### Implementation

Backend (Symfony):
```php
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

$hub->publish(new Update(
    '/households/' . $habit->getHousehold()->getId() . '/habits',
    json_encode(['type' => 'habit_logged', 'data' => $logData])
));
```

Frontend (Svelte):
```svelte
<script>
  import { onMount } from 'svelte';
  onMount(() => {
    const es = new EventSource(`${MERCURE_URL}?topic=/households/${householdId}/habits`);
    es.onmessage = (event) => {
      const data = JSON.parse(event.data);
      // Update Dashboard Store
    };
  });
</script>
```

### Auth for Mercure

JWT-based (Mercure has its own JWT config). The user gets a Mercure JWT that can only subscribe to topics of their household. The Caddy config in the template already has `MERCURE_JWT_SECRET` as an environment variable.

## Deployment (Hetzner VPS + OpenTofu)

### Infrastructure

- **Hetzner Cloud VPS**: CX31 or CX41 (4-8 vCPU, 8-16GB RAM) — sufficient for app + GlitchTip + PostgreSQL
- **OpenTofu**: Infrastructure as Code for VPS provisioning, firewall rules, DNS, volumes
- **OS**: Ubuntu 24.04 LTS, Docker + Docker Compose pre-installed

### OpenTofu Resources

```
hcloud_server        — VPS with SSH key
hcloud_volume        — Persistent storage for PostgreSQL + GlitchTip DB
hcloud_firewall      — Only 22 (SSH), 80 (HTTP), 443 (HTTPS) open
hcloud_rdns          — Reverse DNS for mail delivery
cloudflare_record    — DNS A record (or Hetzner DNS)
```

### Directory Structure on Server

```
/opt/smarthabit/
├── compose.yaml              — App stack (FrankenPHP, PgBouncer, PostgreSQL, Messenger)
├── compose.glitchtip.yaml    — Monitoring stack (GlitchTip, Redis, own PG)
├── .env                      — Secrets (not in the repo!)
├── backups/                  — PostgreSQL dumps
└── Caddyfile                 — Overrides if needed
```

### Backup Strategy

- **PostgreSQL**: Nightly `pg_dump` via cron → compressed to `/opt/smarthabit/backups/`
- **Rotation**: Keep last 7 daily + last 4 weekly backups
- **Offsite**: Backups synced to Hetzner Object Storage (S3-compatible) via `rclone`
- **Testing**: Monthly restore test in a separate container

### Deployment Flow (Manual Initially)

```bash
# Local
git push origin main

# On the server
ssh smarthabit
cd /opt/smarthabit
git pull
docker compose build --pull
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### CD via GitHub Actions (Later)

Planned for later, not for the MVP. When the time comes:
- GitHub Action: Build → push image to GitHub Container Registry → SSH to Hetzner → `docker compose pull && up -d`
- Migrations as a separate step after deployment
- Rollback: pull previous image tag

## Accessibility (a11y)

Relevant for App Store release, and generally the right thing to do.

### Minimum Requirements

- **ARIA Labels**: All interactive elements (check buttons, nav items, cards) with `aria-label` or `aria-labelledby`
- **Keyboard Navigation**: Tab order through all habits, Enter/Space to log, Escape to close modals
- **Color Contrasts**: WCAG 2.1 AA (minimum 4.5:1 for text, 3:1 for large text). Neo Utility's blue (#3366FF) on white = 3.8:1 — slightly below AA for normal text, must be adjusted to #2952CC
- **Dark Mode Contrasts**: Check separately — light text on dark background has different contrast issues
- **Reduced Motion**: Respect `prefers-reduced-motion` media query, disable animations
- **Screen Reader**: Habit status as `aria-live="polite"` region so status changes are announced
- **Touch Targets**: Minimum 44x44px for all tappable elements (Apple HIG + WCAG)

### Tooling

- **axe DevTools** (Browser Extension): Automated a11y checks during development
- **Lighthouse**: a11y score as part of CI (target: >= 90)
- **Manual Tests**: VoiceOver (iOS/Mac) + TalkBack (Android) at least once per phase

## Testing & Code Quality

Quality is not a Phase 4 feature. The tooling is in place from commit 1, CI blocks on violations.

### Composer Dev Dependencies

```
phpunit/phpunit: ^13.0
infection/infection: ^0.32
phpstan/phpstan: ^2.1
phpstan/phpstan-symfony: *
phpstan/phpstan-doctrine: *
phpstan/phpstan-strict-rules: *
rector/rector: *
symplify/easy-coding-standard: *
```

### PHPUnit 13 — Path Coverage + Sealed Test Doubles

PHPUnit 13 (Feb 2026, requires PHP 8.4+) with Xdebug (XDEBUG_MODE=coverage) for path coverage. Important new features in v13:

- **Sealed Test Doubles**: `$mock->seal()` prevents subsequent configuration — enforces complete setup phase before the test act
- **`createStub()` vs `createMock()`**: v13 enforces the distinction. `createMock()` only when invocation verification is needed (with `expects()`), otherwise `createStub()`
- **`withParameterSetsInOrder()` / `withParameterSetsInAnyOrder()`**: Replacement for the long-removed `withConsecutive()`

Configuration in `phpunit.xml.dist`:

```xml
<phpunit
    colors="true"
    failOnRisky="true"
    failOnWarning="true"
    executionOrder="random"
>
    <coverage pathCoverage="true">
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <exclude>
            <directory>src/Entity</directory>    <!-- Entities are DTOs -->
            <directory>src/Kernel.php</directory>
        </exclude>
        <report>
            <html outputDirectory="var/coverage"/>
            <clover outputFile="var/coverage/clover.xml"/>
        </report>
    </coverage>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

In the FrankenPHP Dockerfile, Xdebug is installed in the dev stage via `install-php-extensions xdebug`. Coverage is enabled with `XDEBUG_MODE=coverage`. Note: PCOV cannot be used because FrankenPHP uses ZTS (Zend Thread Safety) and PCOV only supports NTS builds.

### Unit Tests

Focus on domain logic, no IO. Everything that makes decisions is unit-tested:

**TimeWindowChecker** — core logic whether a habit is currently within its time window. Test cases: within window, outside, exact boundary, midnight overlap (23:00-01:00), different timezones, DST transitions.

**TimeWindowLearner** — MAD algorithm. Test cases: normal dataset, too few data points (<7 logs), outlier robustness, weekday/weekend split, minimum window width 30min.

**PushSubscriptionManager** — Add subscription (web_push, ntfy, apns), remove, duplicate check, cleanup for stale subscriptions, mix different subscription types.

**NotifyHandler** — Multi-transport dispatch logic. Test cases: user with mixed subscription types, error handling per transport (410 Gone → remove, 429 → retry), fallback when one transport fails while others work. Stubs for WebPushService, NtfyClient, ApnsTransport.

**HabitCompletionChecker** — "Was it already done today?" with timezone awareness. Test cases: log from yesterday 23:59 UTC that in user timezone is "today", and vice versa.

**InviteCodeGenerator** — Uniqueness, format, collision handling.

**HouseholdIsolationVoter** — Security Voter that checks whether a user may access resources of their household. Test cases: own household → allowed, other household → denied, no household → denied, admin override (if needed later).

**AccountDeletionService** — Deletion cascade. Test cases: user with logs → everything deleted, last user in household → household cascade, user with push subscriptions → all removed, user with NotificationLogs → all removed.

**DataExportService** — GDPR export. Test cases: export contains all user data, export contains all logs, export contains no data from other users, export format is valid JSON.

**RateLimiterConfig** — Not directly unit-testable, but the configuration is verified in integration tests.

Every unit test:
- No DB, no filesystem, no HTTP
- `createStub()` instead of `createMock()` where no invocation verification is needed (PHPUnit 13 enforces this)
- Real mocks only for external services (Web Push, ntfy, APNs) — with `seal()` for strict configuration
- `#[CoversClass(...)]` attribute on every test class
- Data providers for edge cases instead of duplicated tests

### Integration Tests

Test the collaboration of real components with a real PostgreSQL instance (Docker in CI):

**Repository Tests** — Doctrine repositories with real queries. Especially important for the dashboard query (JOIN across habits + logs + users), the time window check (PostgreSQL TIME comparisons), and the deletion cascade (GDPR account deletion).

**API Tests** — Symfony `WebTestCase` with real HTTP requests against the app:
- Auth flow: Register → verification email → login → JWT → protected endpoint
- Password reset: Forgot → token email → reset → old JWT invalid
- CRUD operations + validation (invalid timezone, missing required fields, i18n error messages)
- Household isolation: User A does not see habits of Household B (Security Voter)
- Rate limiting: 6th login attempt → 429 Too Many Requests
- GDPR: `GET /export` delivers complete data, `DELETE /user/me` cascade-deletes everything
- Push subscription CRUD: register, update, delete for all three types

**Messenger Tests** — Does CheckHabitsCommand produce the right messages? Does NotifyHandler dispatch to the right users? Are push errors handled correctly (subscription removed on 410, retry on 429)? Mock only the push clients themselves (WebPush, ntfy HTTP, APNs), everything else real.

**Mercure Tests** — Is a Mercure update published when a log is created? Does the update contain the right data? Is the topic household-scoped?

**Email Tests** — Symfony Mailer has built-in test tooling (`assertEmailCount()`, `assertEmailSent()`). Test: verification email after register, reset email after forgot, correct language based on user locale, links in emails are valid.

**Cleanup Command Tests** — `app:cleanup-push-subscriptions` removes stale subscriptions, keeps active ones. `app:cleanup-old-logs` anonymizes/deletes logs after retention period.

Test DB is built per `doctrine:schema:create` before each suite and reset after each test via transaction rollback (Symfony `ResetDatabase` trait or manual `beginTransaction`/`rollBack`).

### Mutation Testing — Infection

Infection runs exclusively against the unit test suite (not integration). Against integration tests it would be too slow and produce timeouts. Configuration in `infection.json5`:

```json5
{
    "$schema": "vendor/infection/infection/resources/schema.json",
    "source": {
        "directories": ["src"],
        "excludes": [
            "Entity",
            "Kernel.php",
            "Controller",     // Controller logic is thin, tested via integration
            "Command"         // Commands are thin, tested via integration
        ]
    },
    "logs": {
        "text": "var/infection/infection.log",
        "html": "var/infection/infection.html",
        "summary": "var/infection/summary.log"
    },
    "mutators": {
        "@default": true
    },
    "phpUnit": {
        "configDir": ".",
        "customPath": "vendor/bin/phpunit"
    },
    "testFramework": "phpunit",
    "testFrameworkOptions": "--testsuite=unit",
    "minMsi": 80,
    "minCoveredMsi": 90
}
```

Target values: MSI >= 80%, Covered Code MSI >= 90%. Initially the threshold may be lower, but it is raised with each phase. Escaped mutants are logged in CI and discussed in PR reviews.

### PHPStan — Level max

PHPStan at level max (level 10) from day 1. With the Symfony and Doctrine extensions most false positives are eliminated. Configuration in `phpstan.neon`:

```neon
parameters:
    level: max
    paths:
        - src
        - tests
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
    doctrine:
        objectManagerLoader: tests/object-manager.php
    ignoreErrors: []    # keep empty — fix errors, don't ignore them
```

No `ignoreErrors` as a dumping ground. If PHPStan flags something, it gets fixed or there is a `@phpstan-ignore` with justification directly in the code.

### Rector — Automatic Code Modernization

Rector keeps the code at PHP 8.4 / Symfony 8 level. Runs in CI as check (`--dry-run`), locally as auto-fix. Configuration in `rector.php`:

```php
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withSets([
        SetList::PHP_84,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SymfonySetList::SYMFONY_80,
        DoctrineSetList::DOCTRINE_ORM_214,
        PHPUnitSetList::PHPUNIT_130,
    ]);
```

### Easy Coding Standard (ECS)

ECS instead of PHP-CS-Fixer or PHP_CodeSniffer — one config, both tools. Configuration in `ecs.php`:

```php
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withPreparedSets(
        psr12: true,
        common: true,
        strict: true,
        cleanCode: true,
    );
```

### CI Pipeline (GitHub Actions or GitLab CI)

Every push / PR goes through:

```
1. ECS Check          — Coding standard (fastest check first)
2. PHPStan            — Static analysis
3. Rector --dry-run   — Outdated patterns?
4. PHPUnit Unit       — with path coverage
5. PHPUnit Integration — against PostgreSQL (service container in CI)
6. Infection           — Mutation testing against unit suite
```

Steps 1-3 run in parallel (no DB needed). Steps 4-6 sequentially (coverage → mutation needs coverage data). CI fails on: ECS violations, PHPStan errors, Rector diff, test failures, path coverage < 80%, MSI < 80%.

### Makefile / Composer Scripts

Local shortcuts so nobody has to remember the long commands:

```makefile
quality:        ## All checks locally
	@make ecs phpstan rector-check test infection

ecs:            ## Coding Standard (fix)
	vendor/bin/ecs check --fix

ecs-check:      ## Coding standard (check only)
	vendor/bin/ecs check

phpstan:        ## Static analysis
	vendor/bin/phpstan analyse

rector:         ## Rector Auto-Fix
	vendor/bin/rector process

rector-check:   ## Rector Dry-Run
	vendor/bin/rector process --dry-run

test:           ## PHPUnit (all suites)
	vendor/bin/phpunit

test-unit:      ## Unit tests only with coverage
	vendor/bin/phpunit --testsuite=unit --coverage-html=var/coverage

test-integration: ## Integration tests only
	vendor/bin/phpunit --testsuite=integration

infection:      ## Mutation Testing
	vendor/bin/infection --threads=4 --show-mutations

infection-fast: ## Infection only for changed files (CI optimization)
	vendor/bin/infection --threads=4 --git-diff-filter=AM --git-diff-base=origin/main
```

### Test Directory Structure

```
tests/
├── Unit/
│   ├── Service/
│   │   ├── TimeWindowCheckerTest.php
│   │   ├── TimeWindowLearnerTest.php
│   │   ├── PushSubscriptionManagerTest.php
│   │   ├── NotifyHandlerTest.php
│   │   ├── HabitCompletionCheckerTest.php
│   │   ├── AccountDeletionServiceTest.php
│   │   ├── DataExportServiceTest.php
│   │   └── HouseholdIsolationVoterTest.php
│   ├── Util/
│   │   └── InviteCodeGeneratorTest.php
│   └── ValueObject/
│       └── TimeWindowTest.php
├── Integration/
│   ├── Repository/
│   │   ├── HabitRepositoryTest.php
│   │   ├── HabitLogRepositoryTest.php
│   │   └── NotificationLogRepositoryTest.php
│   ├── Controller/
│   │   ├── AuthControllerTest.php
│   │   ├── PasswordResetControllerTest.php
│   │   ├── HabitControllerTest.php
│   │   ├── DashboardControllerTest.php
│   │   ├── LogControllerTest.php
│   │   ├── PushSubscriptionControllerTest.php
│   │   ├── UserExportControllerTest.php
│   │   ├── UserDeletionControllerTest.php
│   │   └── RateLimitingTest.php
│   ├── Messenger/
│   │   ├── CheckHabitsHandlerTest.php
│   │   └── NotifyHabitHandlerTest.php
│   ├── Mercure/
│   │   └── HabitLogMercurePublishTest.php
│   ├── Email/
│   │   ├── VerificationEmailTest.php
│   │   └── PasswordResetEmailTest.php
│   └── Command/
│       ├── CleanupPushSubscriptionsCommandTest.php
│       └── CleanupOldLogsCommandTest.php
├── Factory/              — Object Mothers / Factories for test data
│   ├── HabitFactory.php
│   ├── UserFactory.php
│   └── HouseholdFactory.php
└── bootstrap.php
```

## Phase Plan

### Phase 1a — Project Setup & Auth (2 Weeks)

- Docker setup via `dunglas/symfony-docker` template: FrankenPHP, PostgreSQL, PgBouncer, Bun (dev)
- Quality tooling from commit 1: PHPStan max, Rector, ECS, PHPUnit 13 config, Infection config
- Set up CI pipeline (all 6 steps)
- **i18n from day 1**: paraglide-sveltekit setup (de/en), Symfony Translator (messages.de.yaml/en.yaml)
- Symfony Skeleton + Doctrine entities + migrations (incl. all fields: locale, theme, email_verified_at, consent_at, consent_version, deleted_at, updated_at)
- Auth: Register, Login, JWT (Access + Refresh Token)
- **Rate Limiting** on auth endpoints (symfony/rate-limiter)
- **Email Verification** + **Password Reset** flow (Symfony Mailer + Brevo)
- **GDPR**: Privacy policy, consent at registration, export + deletion endpoints
- **Household Isolation Voter**: Unit-tested, active from day 1
- Integration tests for auth (incl. rate limiting, verification, password reset)

### Phase 1b — Core Features & Frontend (2 Weeks)

- CRUD for habits (incl. soft delete) + unit tests for domain services + first Infection run
- One-tap logging
- Dashboard endpoint + health check endpoints (`/api/v1/health`, `/api/v1/health/ready`)
- SvelteKit scaffold + auth flow + timezone setting + language switcher
- **Accessibility**: ARIA labels, keyboard nav, contrast check from the first UI element
- Integration tests for habit CRUD + dashboard + GDPR endpoints

### Phase 2 — Usable MVP (2 Weeks)

- PWA setup (manifest, service worker, install)
- **PWA Shortcuts** in manifest for quick-log of top habits (long-press on icon)
- Dashboard UI with tap logging
- **Mercure Real-Time**: Live updates when household member logs (SSE, already in Caddy)
- History view
- Household system (invite code, join) + **household invitation via email** (optional)
- Offline queue for logs
- **Dark Mode** toggle (Auto/Light/Dark, CSS Custom Properties)

### Phase 3 — Notifications (2 Weeks)

- **Web Push Setup**: Generate VAPID key pair, integrate `minishlink/web-push`
- Push subscription registration + lifecycle in the frontend (Service Worker Push API)
- Service Worker push handler (show notification, tap → open app)
- Cron + Messenger Worker (per-user timezone check, multi-transport dispatch)
- NotificationLog + deduplication
- Notification tap → app opens and logs
- Push subscription cleanup command
- Unit tests: TimeWindowChecker, PushSubscriptionManager, NotifyHandler (multi-transport), HabitCompletionChecker
- Integration tests: CheckHabitsHandler, NotifyHabitHandler (stubs for WebPush/ntfy/APNs), email tests, Mercure tests
- Raise Infection MSI threshold to >= 80%

### Phase 4 — Intelligence (1-2 Weeks)

- Nightly command for time window analysis
- MAD-based algorithm (timezone-aware)
- Weekday/weekend detection
- UI: display learned vs. manual window
- Unit tests: TimeWindowLearner (most comprehensive test suite — outliers, edge cases, min. data points)
- Covered Code MSI >= 90% for TimeWindowLearner

### Phase 5 — Statistics & Analytics (2-3 Weeks)

- Base stats: streak, completion rate, average time (on-the-fly PostgreSQL queries)
- Stats endpoint per habit: `GET /api/v1/habits/{id}/stats`
- Trend calculation: comparison current vs. previous 30 days
- Distribution by user: who completes which habit how often?
- Household dashboard: `GET /api/v1/stats/household` (overall completion, ranking, most active user)
- Nightly `app:compute-stats` command for materialized views (heatmaps, aggregations)
- Weekday heatmap + time heatmap (SVG grid in frontend)
- Charts via lightweight Svelte library (layerchart or pancake)
- Unit tests: streak calculation edge cases (timezone boundaries, gaps, frequency consideration)

### Phase 6 — Deployment & Ops (1-2 Weeks)

- **OpenTofu**: Provision Hetzner VPS (CX31/CX41), firewall, DNS, volume
- Deploy app + **GlitchTip** Compose stacks on the server
- **Sentry SDK** (→ GlitchTip) integrate in PHP + frontend (DSN points to errors.smarthabit.de)
- **ntfy server** set up on the VPS (ntfy.smarthabit.de) — preparation for Phase 7
- **PostgreSQL Backup**: Nightly pg_dump → Hetzner Object Storage via rclone
- Let's Encrypt via Caddy (automatic)
- First manual deployment flow: `git pull → docker compose build → up -d → migrate`
- Smoke tests on production
- Configure GlitchTip alerts (error spikes, cron failures)
- **Lighthouse Audit**: Performance + a11y score >= 90

### Phase 7 — Native App & Widgets (Incremental, 3-4 Weeks)

**Stage 1 — PWA Shortcuts** are already done in Phase 2 (manifest entries).

**Stage 2 — Capacitor App + Store Deployment (1-2 Weeks)**:
- Capacitor integration: SvelteKit build → native iOS/Android shell
- Push: APNs directly for iOS, ntfy (already set up in Phase 6) for Android — no Firebase
- TestFlight / Play Console internal testing
- App Store + Play Store submission
- Verify all UI texts for complete de/en translation before store release

**Stage 3 — Native Widgets via `capacitor-widget-bridge` (1-2 Weeks)**:
- Integrate `capacitor-widget-bridge` for SharedStorage bridge
- Write iOS Widget Extension (~50-80 lines SwiftUI): habit list from UserDefaults
- Write Android AppWidgetProvider (~80-100 lines Kotlin + XML): habit list from SharedPreferences
- Data flow: app writes dashboard data to SharedStorage → widget reads → tap opens app at habit
- Widget sizes: Small/Medium/Large (iOS), 2x1 / 4x2 (Android)
- Next iteration: direct tap-to-log from widget without opening app (App Intents / PendingIntent)

### Phase 8 — CI/CD & Automation (1 Week)

- **GitHub Actions CD**: Push to main → build image → push to GHCR → SSH deploy to Hetzner
- Migrations as a separate CD step
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
| Push (Native)? | **APNs directly + ntfy self-hosted** | APNs for iOS (only way), ntfy on Hetzner for Android. Completely US-free. |
| Push subscription cleanup? | **Three-stage** | Immediately on push error (410 Gone), nightly for stale subscriptions, explicitly on logout. |
| Timezones? | **UTC storage + user timezone** | Everything stored in UTC. Time windows as local time, notification check converts per user. DST-safe. |
| Time window storage? | **Local time (TIME without TZ)** | Human concept, DST transitions need no recalculation. |
| PHPUnit? | **Version 13** (Feb 2026) | Sealed test doubles, `createStub`/`createMock` separation, `withParameterSetsInOrder`. Requires PHP 8.4. |
| Coverage tool? | **Xdebug** with XDEBUG_MODE=coverage | FrankenPHP uses ZTS (Zend Thread Safety), PCOV only supports NTS. Xdebug provides path coverage + debugging. |
| Mutation testing scope? | **Unit suite only** | Integration tests are too slow for Infection, produce timeouts. Domain logic is the critical part. |
| MSI threshold? | **80% MSI, 90% Covered MSI** | Realistic from Phase 3, below that CI does not block but warns. |
| Mocking strategy? | **`createStub()` > `createMock()`** | PHPUnit 13 enforces the separation. Mocks with `seal()` only for external services (WebPush, ntfy, APNs, Brevo). |
| i18n Frontend? | **paraglide-sveltekit** | Compile-time, zero runtime overhead, type-safe keys. No `$t()` at runtime. |
| Design direction? | **Neo Utility** + Dark Mode | Functional, data-driven, Sora + JetBrains Mono, progress bar, tags. Dark Mode via CSS Custom Properties + `prefers-color-scheme`. |
| Color system? | **CSS Custom Properties** with Light/Dark swap | User preference in DB (`theme: auto/light/dark`), no separate stylesheet. |
| i18n Backend? | **Symfony Translator** | Standard component, YAML-based, integrates with Validator + Notifier. |
| Statistics calculation? | **Hybrid: on-the-fly + Materialized Views** | Base stats (streak, rate) calculated live, heatmaps/aggregations precomputed nightly. |
| Native app? | **Capacitor 6.x** (Phase 7, Stage 2) | Same SvelteKit code, native shell for app stores. |
| Widgets? | **Incremental**: PWA Shortcuts → Capacitor + `capacitor-widget-bridge` → native SwiftUI/Kotlin | Stage 1 costs 30min, Stage 3 ~50-80 lines native code per platform. No custom plugin needed. |
| Email provider? | **Brevo** (Free Tier) via `symfony/brevo-mailer` | 300 mails/day free, EU-based (GDPR), official Symfony Bridge. |
| Error tracking? | **GlitchTip 6.x** self-hosted | 512MB RAM, MIT license, Sentry SDK compatible. Migration to Sentry Cloud anytime via DSN swap. |
| Real-time? | **Mercure** (SSE, built into Caddy) | Already in `dunglas/symfony-docker` template, no extra service. Live updates for household logs. |
| Hosting? | **Hetzner Cloud VPS** + OpenTofu | IaC, affordable, EU data center (GDPR), good peering. |
| CD? | **GitHub Actions** (Phase 8) | Manual deploy first, CD comes after stabilization. |
| Backups? | **pg_dump nightly** → Hetzner Object Storage | 7 daily + 4 weekly rotation, monthly restore test. |
| GDPR? | **From day 1**: Consent, export, deletion, retention periods | Retrofitting is a nightmare. Blocker for App Store. |
| Rate limiting? | **Symfony Rate Limiter + Caddy** | Double layer: application level (5/min login) + reverse proxy level. |
