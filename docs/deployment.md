# Deployment Guide

## Prerequisites

- Hetzner Cloud account + API token
- Domain pointed to the server IP (Cloudflare or direct)
- OpenTofu installed locally

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

## 4. Build and Start

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

## Rollback

```bash
# If deployment breaks:
git log --oneline -5  # find the last working commit
git checkout <commit>
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```
