# OpenObserve Migration Plan

> **Goal**: Replace GlitchTip (4 containers) with OpenObserve (1 container) for unified observability.
> **Branch**: `feat/openobserve-migration`
> **Findings**: [findings.md](findings.md)

---

## Architecture Decision: Two-Layer Approach

OpenObserve is NOT a drop-in Sentry replacement. It's a log/metric/trace aggregator.
Sentry SDKs cannot talk to it. This means we don't just swap a DSN — we swap the entire instrumentation layer.

**Chosen approach**:
- **Backend**: OpenTelemetry PHP SDK → OTLP → OpenObserve (traces + structured logs)
- **Frontend**: `@openobserve/browser-logs` + `@openobserve/browser-rum` → OpenObserve (errors + RUM)
- **Infrastructure logs**: Caddy stays on disk for fail2ban; PHP logs go via OTel
- **Alerting**: OpenObserve email alerts (via existing Resend SMTP) replace GlitchTip notifications

---

## Pitfalls & Risks

### P1 — CRITICAL: OTel env var timing with FrankenPHP
**Risk**: `OTEL_*` vars in `.env`/`.env.local` are invisible to the SDK (loads before Symfony DotEnv).
**Mitigation**: All `OTEL_*` vars go in `compose.prod.yaml` `environment:` block. Document this clearly.

### P2 — CRITICAL: Worker mode span flushing
**Risk**: FrankenPHP worker mode keeps the process alive. Spans batch up and only flush on process death.
**Mitigation**: Configure `forceFlush()` after each request via a Symfony kernel.terminate listener.

### P3 — HIGH: No automatic error grouping
**Risk**: GlitchTip/Sentry groups errors by fingerprint (exception class + message + stack trace). OpenObserve shows raw log/trace entries.
**Mitigation**: Create structured error log format with `exception.type`, `exception.message`, `exception.stacktrace` fields. Use OpenObserve's log search to filter. Accept this is less polished than Sentry for now.

### P4 — MEDIUM: OpenObserve memory usage
**Risk**: Default config is cache-hungry. On CX23 (4 GB total, shared with app), could cause OOM.
**Mitigation**: Tune `ZO_MEM_TABLE_MAX_SIZE=64`, `ZO_MEMORY_CACHE_MAX_SIZE=128`, deploy with `memory: 512M` limit. Monitor.

### P5 — MEDIUM: C extension in Docker build
**Risk**: The `opentelemetry` PECL extension must compile against ZTS PHP headers in the FrankenPHP image.
**Mitigation**: Use `php-extension-installer install opentelemetry` in Dockerfile. Test in CI before deploying.

### P6 — LOW: Browser SDK credentials visible
**Risk**: OpenObserve auth token is visible in the JS bundle (same as Sentry DSN today).
**Mitigation**: Route browser telemetry through a Caddy proxy path (e.g., `/o2/`) to keep OpenObserve unexposed. Use a dedicated ingestion-only token if supported.

### P7 — LOW: Symfony bundle maturity
**Risk**: `open-telemetry/symfony-sdk-bundle` is v0.2.0 (sub-1.0). Breaking changes possible.
**Mitigation**: Pin version. The underlying SDK and auto-instrumentation packages are stable (v1.x). The bundle is thin DI wiring — easy to replace if abandoned.

---

## Phase 1: OpenObserve Deployment `[ ]`
**Goal**: Get OpenObserve running alongside GlitchTip (parallel operation).

### Changes
- [ ] Create `compose.openobserve.yaml` (single container, port 5080, /mnt/data/openobserve volume)
- [ ] Add memory tuning env vars for CX23
- [ ] Add OpenObserve to cloud-init (conditional start, same pattern as GlitchTip)
- [ ] Add Caddy reverse proxy path `/o2/*` → OpenObserve (keeps it behind auth/same-origin)
- [ ] Update Hetzner firewall: add port 5080 temporarily for initial setup, then remove after Caddy proxy works
- [ ] Test: access UI, create org, verify data persistence after restart

### Files
- `compose.openobserve.yaml` (new)
- `infrastructure/modules/server/cloud-init.yml`
- `docker/frankenphp/Caddyfile`

---

## Phase 2: Backend — PHP OpenTelemetry `[ ]`
**Goal**: Replace sentry-symfony with OpenTelemetry instrumentation.

### Changes
- [ ] Install C extension in Dockerfile (`php-extension-installer install opentelemetry`)
- [ ] `composer remove sentry/sentry-symfony`
- [ ] `composer require open-telemetry/sdk open-telemetry/exporter-otlp open-telemetry/opentelemetry-auto-symfony open-telemetry/opentelemetry-auto-doctrine open-telemetry/symfony-sdk-bundle open-telemetry/opentelemetry-auto-psr3`
- [ ] Remove `backend/config/packages/sentry.php`
- [ ] Remove SentryBundle from `backend/config/bundles.php`
- [ ] Update `backend/config/packages/monolog.php` — remove sentry handler, keep stderr
- [ ] Add OTel env vars to `compose.yaml` (dev) and `compose.prod.yaml` (prod)
- [ ] Create `ExceptionTraceSubscriber` — kernel.exception listener that records exception on active span
- [ ] Create `SpanFlushSubscriber` — kernel.terminate listener that calls `forceFlush()` (P2 mitigation)
- [ ] Update `.env.example` — remove `SENTRY_DSN`, add `OTEL_*` reference
- [ ] Run full test suite — ensure no regressions
- [ ] Verify traces appear in OpenObserve UI

### Files
- `docker/frankenphp/Dockerfile`
- `backend/composer.json` + `composer.lock`
- `backend/config/packages/sentry.php` (delete)
- `backend/config/bundles.php`
- `backend/config/packages/monolog.php`
- `backend/src/Shared/EventSubscriber/ExceptionTraceSubscriber.php` (new)
- `backend/src/Shared/EventSubscriber/SpanFlushSubscriber.php` (new)
- `compose.yaml`, `compose.prod.yaml`
- `.env.example`, `backend/.env`

### OTel env vars (compose.prod.yaml)
```yaml
environment:
  - OTEL_PHP_AUTOLOAD_ENABLED=true
  - OTEL_SERVICE_NAME=smart-habit-api
  - OTEL_EXPORTER_OTLP_ENDPOINT=http://openobserve:5081
  - OTEL_EXPORTER_OTLP_PROTOCOL=grpc
  - OTEL_PROPAGATORS=tracecontext,baggage
  - OTEL_TRACES_SAMPLER=always_on
  - OTEL_EXPORTER_OTLP_HEADERS=Authorization=Basic <base64>
```

---

## Phase 3: Frontend — Browser SDK `[ ]`
**Goal**: Replace @sentry/svelte with OpenObserve browser SDK.

### Changes
- [ ] `bun remove @sentry/svelte`
- [ ] `bun add @openobserve/browser-logs @openobserve/browser-rum`
- [ ] Rewrite `frontend/src/hooks.client.ts` — init OpenObserve SDK, capture errors
- [ ] Remove `VITE_SENTRY_DSN` from Dockerfile and cd.yml
- [ ] Add `VITE_O2_ENDPOINT` and `VITE_O2_ORG` build args (or hardcode if same-origin)
- [ ] Add Caddy proxy path for browser telemetry ingestion (same-origin, no CORS)
- [ ] Test: trigger JS error, verify it appears in OpenObserve

### Files
- `frontend/package.json`
- `frontend/src/hooks.client.ts`
- `docker/frankenphp/Dockerfile`
- `docker/frankenphp/Caddyfile`
- `.github/workflows/cd.yml`

---

## Phase 4: Deployment & Cutover `[ ]`
**Goal**: Deploy to production, verify, then remove GlitchTip.

### Changes
- [ ] Update `scripts/first-deploy.sh` — replace GlitchTip setup with OpenObserve setup
- [ ] Configure OpenObserve email alerts (error-level logs → Resend SMTP)
- [ ] Deploy to production (parallel: both GlitchTip and OpenObserve running)
- [ ] Verify: backend traces, frontend errors, alerting
- [ ] Remove GlitchTip: `docker compose -f compose.glitchtip.yaml down -v`
- [ ] Delete `compose.glitchtip.yaml`
- [ ] Remove GlitchTip from cloud-init
- [ ] Update Hetzner firewall — remove port 8000
- [ ] Remove `VITE_SENTRY_DSN` GitHub secret

### Files
- `scripts/first-deploy.sh`
- `compose.glitchtip.yaml` (delete)
- `infrastructure/modules/server/cloud-init.yml`
- `infrastructure/modules/network/main.tf` (remove port 8000)

---

## Phase 5: Documentation & Template Backport `[ ]`
**Goal**: Update docs and backport to template repo.

### Changes
- [ ] Update `docs/deployment.md` — OpenObserve setup replaces GlitchTip section
- [ ] Update `docs/infrastructure.md` — monitoring section
- [ ] Update `CLAUDE.md` — reflect new observability stack
- [ ] Backport all changes to `template-symfony-sveltekit` repo
- [ ] Verify template works for fresh deployment

### Files
- `docs/deployment.md`, `docs/infrastructure.md`
- `CLAUDE.md`, `README.md`
- Template repo: all corresponding files

---

## Resource Comparison

| | GlitchTip (current) | OpenObserve (target) |
|---|---|---|
| Containers | 4 (web, worker, postgres, redis) | 1 |
| RAM usage | ~600 MB total | ~256–512 MB (tuned) |
| Disk | PostgreSQL data | Parquet files in /data |
| Port exposed | 8000 (open to world!) | None (behind Caddy proxy) |
| Error grouping | Automatic (Sentry-style) | Manual (log search) |
| Traces | No | Yes (full distributed tracing) |
| Log aggregation | No | Yes |
| Alerting | Email | Email + Webhook |
