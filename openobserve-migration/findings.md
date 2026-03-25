# OpenObserve Migration — Research Findings

## 1. OpenObserve Deployment

- **Image**: `gallery.ecr.aws/zinclabs/openobserve:latest` (OSS, AGPL-3.0)
- **Single container** — all-in-one (no Redis, no Celery, no separate DB)
- **Ports**: 5080 (HTTP UI + API), 5081 (gRPC/OTLP)
- **Volume**: `/data` — SQLite metadata + Parquet data files
- **RAM**: 1–2 GB minimum for light use; defaults are cache-hungry, needs tuning
- **Auth**: Built-in username/password (SSO is enterprise-only)
- **Retention**: Configurable per-stream, default 30 days (`ZO_COMPACT_DATA_RETENTION_DAYS`)
- **Alerting**: Email (SMTP) + Webhook (Slack, ntfy, etc.) — configured via UI
- **No Sentry protocol support** — cannot accept data from Sentry SDKs

### Key env vars
| Variable | Purpose |
|---|---|
| `ZO_ROOT_USER_EMAIL` | Admin email (first boot) |
| `ZO_ROOT_USER_PASSWORD` | Admin password (first boot) |
| `ZO_DATA_DIR` | Data storage path (`/data`) |
| `ZO_SMTP_ENABLED` | Enable email alerts |
| `ZO_SMTP_HOST/PORT/USER_NAME/PASSWORD/FROM_EMAIL` | SMTP config |
| `ZO_MEM_TABLE_MAX_SIZE` | Tune down for low-RAM servers |
| `ZO_MEMORY_CACHE_MAX_SIZE` | Tune down for low-RAM servers |

## 2. PHP OpenTelemetry SDK

### Packages needed
| Package | Version | Purpose |
|---|---|---|
| `open-telemetry/sdk` | stable | Core SDK |
| `open-telemetry/exporter-otlp` | stable | OTLP exporter |
| `open-telemetry/opentelemetry-auto-symfony` | v1.2.0 | Auto-instrument HttpKernel, HttpClient, Messenger |
| `open-telemetry/opentelemetry-auto-doctrine` | v0.4.0 | Auto-instrument Doctrine DBAL |
| `open-telemetry/symfony-sdk-bundle` | v0.2.0 | DI container wiring |
| `open-telemetry/opentelemetry-auto-psr3` | | Bridge Monolog → OTel logs |

### C extension required for auto-instrumentation
- PECL `opentelemetry` v1.2.1 — PHP 8.4 compatible
- ZTS/FrankenPHP compatible (confirmed via wolfi-php builds)
- Install via `php-extension-installer` in Dockerfile

### FrankenPHP pitfalls (CRITICAL)
1. **`.env` vars invisible to SDK** — OTel SDK initializes via Composer autoload `files`, before Symfony DotEnv loads. All `OTEL_*` vars MUST be in Docker `environment:`, not `.env`
2. **Worker mode span flushing** — Spans only flush on process shutdown. Must call `forceFlush()` after each request or configure `worker_mode.flush_after_each_iteration`
3. **Protocol mismatch** — FrankenPHP's Caddy uses gRPC internally; PHP SDK defaults to HTTP/protobuf. Use consistent protocol

### Error tracking model
- OTel records exceptions as **span events** (not standalone Sentry-style events)
- No automatic error grouping/fingerprinting/deduplication
- Must create custom EventSubscriber to `recordException()` on spans
- PSR-3 bridge (`opentelemetry-auto-psr3`) can forward Monolog to OTel logs

## 3. Frontend (SvelteKit PWA)

### Two options

**Option A: OpenTelemetry JS SDK**
- Packages: `@opentelemetry/sdk-trace-web`, `@opentelemetry/exporter-trace-otlp-http`, `@opentelemetry/context-zone`, `@opentelemetry/instrumentation-fetch`
- **No auto error capture** — must wire `window.onerror` + `unhandledrejection` manually
- **No Svelte integration** — SvelteKit explicitly deferred client-side OTel
- Bundle: ~60 KB gzipped (full auto-instrumentation) vs Sentry's ~30 KB
- CORS: same-origin via Caddy proxy path = no issues

**Option B: OpenObserve's own browser SDK** (simpler)
- Package: `@openobserve/browser-logs`, `@openobserve/browser-rum`
- Proprietary SDK, designed specifically for OpenObserve
- Simpler setup, handles error capture
- Less ecosystem support than OTel

### Frontend-backend correlation
- W3C `traceparent` header propagation works with both options
- `@opentelemetry/instrumentation-fetch` auto-injects headers
- Caddy needs no config change (same-origin)

## 4. Current Sentry/GlitchTip Inventory (12 touchpoints)

### Must change
1. `backend/config/packages/sentry.php` — entire file
2. `backend/config/bundles.php` — remove SentryBundle
3. `backend/config/packages/monolog.php` — remove sentry handler
4. `backend/composer.json` — remove `sentry/sentry-symfony`
5. `frontend/package.json` — remove `@sentry/svelte`
6. `frontend/src/hooks.client.ts` — entire file (Sentry init + error capture)
7. `compose.glitchtip.yaml` — delete or replace (4 containers)
8. `docker/frankenphp/Dockerfile` — remove `VITE_SENTRY_DSN` build arg
9. `.github/workflows/cd.yml` — remove `VITE_SENTRY_DSN` build arg
10. `scripts/first-deploy.sh` — rewrite GlitchTip setup section (~80 lines)
11. `infrastructure/modules/server/cloud-init.yml` — remove GlitchTip auto-start

### Update references
12. `.env.example`, `backend/.env` — `SENTRY_DSN` → OTel vars
13. Docs: `deployment.md`, `infrastructure.md`, `phases.md`, `CLAUDE.md`, `README.md`
14. GitHub secrets: `VITE_SENTRY_DSN` → remove or replace

## 5. Log Shipping Strategy

OpenObserve has no native Docker log driver. Options:
- **OTel Collector sidecar** — tail Docker JSON logs, export via OTLP (heaviest, most flexible)
- **Fluent-bit sidecar** — lighter, outputs to OpenObserve HTTP API
- **Direct app logging** — PHP sends structured logs via OTLP SDK; Caddy logs already on disk for fail2ban, can also forward via Fluent-bit
- **For our use case**: Direct OTLP from PHP + OpenObserve's browser SDK is simplest. Caddy logs stay on disk for fail2ban.
