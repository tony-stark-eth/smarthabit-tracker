# Infrastructure, Monitoring & Deployment
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
    image: postgres:17-alpine

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

Docker healthcheck on the `php` container: `curl -f http://localhost/api/v1/health/ready`. If the cron container dies, notifications are missed — configure GlitchTip alerts for this.

### Structured Logging

Monolog with JSON formatter instead of plain text. Logs go to `stdout` (Docker standard), Docker logging driver collects them. No ELK stack needed in the MVP — `docker compose logs -f php` is sufficient. Important: log notification dispatch and push errors with structured fields (`habit_id`, `user_id`, `push_type`, `status`).

## Real-Time Updates (Mercure)

### Problem

Tom logs "dog walked" → Lisa only sees it after a manual refresh. For a household app where multiple people track simultaneously, this is bad.

### Solution: Mercure (Already in the Stack)

The `dunglas/symfony-docker` template has Mercure built in as a Caddy module. Mercure is a Server-Sent Events (SSE) hub that runs inside Caddy — no additional service, no WebSocket server, no Socket.io.

### Data Flow

```
Lisa logs "dog walked"
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
      // Update dashboard store
    };
  });
</script>
```

### Auth for Mercure

JWT-based (Mercure has its own JWT config). The user gets a Mercure JWT that is only allowed to subscribe to topics of their household. The Caddy config in the template already has `MERCURE_JWT_SECRET` as an environment variable.

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

### Directory Structure on the Server

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

### Deployment Flow (Manual for Now)

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
- **Color Contrasts**: WCAG 2.1 AA (at least 4.5:1 for text, 3:1 for large text). Neo Utility's blue (#3366FF) on white = 3.8:1 — slightly below AA for normal text, needs to be adjusted to #2952CC
- **Dark Mode Contrasts**: Check separately — light text on dark background has different contrast issues
- **Reduced Motion**: Respect `prefers-reduced-motion` media query, disable animations
- **Screen Reader**: Habit status as `aria-live="polite"` region, so status changes are announced
- **Touch Targets**: Minimum 44x44px for all tappable elements (Apple HIG + WCAG)

### Tooling

- **axe DevTools** (browser extension): automated a11y checks during development
- **Lighthouse**: a11y score as part of CI (target: >= 90)
- **Manual Tests**: VoiceOver (iOS/Mac) + TalkBack (Android) at least once per phase
