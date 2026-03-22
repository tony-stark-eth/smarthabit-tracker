# Deployment Guide

## Prerequisites

- Hetzner Cloud account + API token
- Domain pointed to the server IP (Cloudflare or direct)
- OpenTofu installed locally
- GitHub repository with `production` environment configured (see [Environment Protection](#environment-protection))

## 1. Provision Infrastructure

```bash
cd infrastructure
cp terraform.tfvars.example terraform.tfvars
# Edit terraform.tfvars with your Hetzner API token, SSH key, domain
tofu init
tofu plan
tofu apply
```

This creates: CX31 VPS (2 vCPU, 4 GB), 20 GB volume, private network, DNS records.

## 2. Connect to Server

```bash
ssh root@<server-ip>
```

Docker + Docker Compose are installed automatically via cloud-init.

## 3. Clone and Configure

```bash
git clone git@github.com:tony-stark-eth/smarthabit-tracker.git /opt/smarthabit
cd /opt/smarthabit
cp .env.example .env.local
# Edit .env.local:
# - APP_SECRET (generate with: openssl rand -hex 32)
# - DATABASE_URL (use the pgbouncer URL)
# - MAILER_DSN (Brevo when ready)
# - SENTRY_DSN (GlitchTip URL after setup)
# - VAPID keys (generate with: openssl ecparam -genkey -name prime256v1)
```

## 4. Build and Start (Initial / Manual)

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Caddy automatically provisions Let's Encrypt TLS certificates.

## 5. Verify

```bash
./scripts/smoke-test.sh
```

## 6. GlitchTip (Error Tracking)

```bash
docker compose -f compose.glitchtip.yaml up -d
# Open https://errors.smarthabit.de
# Create organization + project → get DSN
# Add DSN to .env.local as SENTRY_DSN
```

## 7. Backups

Automatic nightly via cron (Supercronic in the PHP container):
- 03:00 UTC: `app:learn-timewindows`
- 03:30 UTC: `app:compute-stats`
- 04:00 UTC: `app:cleanup-push-subscriptions`

Manual PostgreSQL backup:
```bash
./scripts/backup-postgres.sh
```

Restore:
```bash
./scripts/restore-postgres.sh /mnt/data/backups/smarthabit_YYYYMMDD_HHMMSS.sql.gz
```

---

## Continuous Deployment (CD)

Every push to `main` triggers `.github/workflows/cd.yml`, which runs three sequential jobs:

```
push to main
    │
    ▼
[CI Gate] — waits for Backend CI, Frontend CI, Playwright jobs to pass
    │
    ▼
[Build & Push] — builds frankenphp_prod Docker image, pushes to GHCR
    │           ghcr.io/tony-stark-eth/smarthabit-tracker/app:latest
    │           ghcr.io/tony-stark-eth/smarthabit-tracker/app:<sha>
    ▼
[Deploy] — SSH into production server, pull image, migrate DB, restart
```

### How it works on the server

1. Pull the new image by SHA (immutable reference)
2. Tag it as `:current`
3. Run `doctrine:migrations:migrate` in a throwaway container (before traffic switches)
4. `docker compose up -d --no-build` — recreates services using the `:current` image
5. Health check: `GET /api/v1/health` must return 200 within 10 s

If the health check fails, the workflow exits non-zero and GitHub marks the deployment failed. The previous containers keep running (they were only replaced, not stopped beforehand).

### Required GitHub Secrets

Configure these under **Settings → Secrets and variables → Actions**:

| Secret | Description |
|---|---|
| `DEPLOY_HOST` | Production server IP or hostname |
| `DEPLOY_USER` | SSH username (e.g. `deploy` or `root`) |
| `DEPLOY_SSH_KEY` | Private SSH key (the public key must be in `~/.ssh/authorized_keys` on the server) |
| `DATABASE_URL` | Production DATABASE_URL passed to the migration step |

`GITHUB_TOKEN` is provided automatically by GitHub Actions — no manual setup needed.

### Environment Protection

The `deploy` job targets the `production` environment. To require manual approval before deployments:

1. Go to **Settings → Environments → New environment** → name it `production`
2. Enable **Required reviewers** and add yourself (or a team)
3. Optionally add **Deployment branches** rule: `main` only

---

## Rollback

### Automatic (via Git)

Push a revert commit to `main` — the CD pipeline will build and deploy it automatically.

### Manual (image tag)

Use `scripts/rollback.sh` to redeploy any previously pushed image:

```bash
# Roll back to the previous :latest
./scripts/rollback.sh

# Roll back to a specific commit SHA
./scripts/rollback.sh abc1234def5678901234567890abcdef12345678
```

The script:
1. Pulls the target image from GHCR
2. Tags it as `:current`
3. Restarts services with `--no-build`
4. Runs a health check — exits non-zero on failure

### Emergency (rebuild from source)

If GHCR images are unavailable:

```bash
cd /opt/smarthabit
git fetch origin
git checkout <last-known-good-sha>
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```
