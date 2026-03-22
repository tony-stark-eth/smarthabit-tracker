# Deployment Guide

## Prerequisites

- Hetzner Cloud account + API token (in `.env.local` as `HETZNER_API_KEY`)
- SSH key at `~/.ssh/id_ed25519` (or `id_rsa`)
- OpenTofu installed (`tofu --version`)
- GitHub CLI authenticated (`gh auth status`)
- Domain ready to point to the server IP

## Quick Start (Automated)

```bash
./scripts/first-deploy.sh <domain> <project-name> [github-repo]

# Example:
./scripts/first-deploy.sh smart-habit.tony-stark.xyz smarthabit tony-stark-eth/smarthabit-tracker
```

This single command handles everything: SSH key upload, server provisioning, Docker setup, secret generation, JWT keys, app build, GlitchTip setup, and GitHub secrets. After it completes, point your domain A record to the server IP.

## Manual Deployment (Step by Step)

### 1. Provision Infrastructure

```bash
cd infrastructure
cp terraform.tfvars.example terraform.tfvars
# Set: project_name, ssh_key_ids (from Hetzner Console → SSH Keys)
export TF_VAR_hcloud_token="your-hetzner-api-token"
tofu init
tofu apply
# Note the server_ipv4 output
```

This creates: CX23 VPS (2 vCPU, 4 GB), 20 GB volume, private network, firewall (22/80/443/8000).

### 2. Wait for Docker

```bash
# cloud-init may report 'error' due to volume mount — ignore it, check Docker:
ssh root@<server-ip> "docker --version"
```

### 3. Clone and Configure

```bash
ssh root@<server-ip>
git clone https://github.com/<repo>.git /opt/smarthabit
cd /opt/smarthabit

# Generate secrets
APP_SECRET=$(openssl rand -hex 32)
POSTGRES_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)
MERCURE_SECRET=$(openssl rand -hex 32)
JWT_PASSPHRASE=$(openssl rand -hex 16)

# Create .env (compose variable interpolation)
cat > .env << EOF
POSTGRES_PASSWORD=$POSTGRES_PASSWORD
EOF

# Create .env.local (Symfony app config)
cat > .env.local << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$APP_SECRET
DATABASE_URL=postgresql://app:$POSTGRES_PASSWORD@pgbouncer:5432/app?serverVersion=17&sslmode=disable&charset=utf8
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=true
MERCURE_URL=https://php/.well-known/mercure
MERCURE_PUBLIC_URL=/.well-known/mercure
MERCURE_JWT_SECRET=$MERCURE_SECRET
JWT_PASSPHRASE=$JWT_PASSPHRASE
JWT_TOKEN_TTL=3600
SENTRY_DSN=
SERVER_NAME=your-domain.com
POSTGRES_PASSWORD=$POSTGRES_PASSWORD
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
NTFY_SERVER_URL=http://ntfy:80
APNS_TEAM_ID=
APNS_KEY_ID=
APNS_PRIVATE_KEY_PATH=
APNS_BUNDLE_ID=
APNS_ENVIRONMENT=production
EOF

# Generate JWT keys
mkdir -p backend/config/jwt
openssl genpkey -algorithm RSA -out backend/config/jwt/private.pem -aes256 -pass pass:$JWT_PASSPHRASE
openssl rsa -in backend/config/jwt/private.pem -pubout -out backend/config/jwt/public.pem -passin pass:$JWT_PASSPHRASE
chown -R 1001:1001 backend/config/jwt
```

### 4. Build and Start

```bash
docker compose -f compose.yaml -f compose.prod.yaml build php
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

### 5. Fix Caddy TLS Permissions

The rootless container (uid 1001) needs write access to `/data` and `/config`:

```bash
docker exec -u root smarthabit-php-1 sh -c 'mkdir -p /data/caddy /config/caddy && chown -R 1001:1001 /data /config'
docker compose -f compose.yaml -f compose.prod.yaml restart php
```

Caddy auto-provisions Let's Encrypt TLS once the domain DNS resolves.

### 6. GlitchTip (Error Tracking)

```bash
# Add GlitchTip secrets to .env
cat >> .env << EOF
GLITCHTIP_SECRET_KEY=$(openssl rand -hex 32)
GLITCHTIP_DOMAIN=http://<server-ip>:8000
GLITCHTIP_FROM_EMAIL=noreply@your-domain.com
EOF

# Start GlitchTip
docker compose -f compose.glitchtip.yaml up -d

# Run migrations (required on first start!)
docker compose -f compose.glitchtip.yaml exec -T glitchtip ./manage.py migrate --no-input

# Create admin user
docker compose -f compose.glitchtip.yaml exec -T glitchtip ./manage.py createsuperuser --noinput --email admin@your-domain.com
docker compose -f compose.glitchtip.yaml exec -T glitchtip ./manage.py shell -c "
from apps.users.models import User
user = User.objects.get(email='admin@your-domain.com')
user.set_password('your-password')
user.save()
"

# Create org + project via Django shell, get DSN
docker compose -f compose.glitchtip.yaml exec -T glitchtip ./manage.py shell -c "
from apps.organizations_ext.models import Organization, OrganizationUser
from apps.projects.models import Project, ProjectKey
from apps.teams.models import Team
from apps.users.models import User

user = User.objects.get(email='admin@your-domain.com')
org = Organization.objects.create(name='MyProject', slug='myproject')
OrganizationUser.objects.create(organization=org, user=user, role=0)
team = Team.objects.create(slug='default', organization=org)
team.members.add(org.organization_users.first())

project = Project.objects.create(name='API', slug='api', organization=org, platform='php-symfony')
project.teams.add(team)
key = ProjectKey.objects.filter(project=project).first() or ProjectKey.objects.create(project=project)
print('DSN:', key.get_dsn())
"

# Set the DSN in .env.local
sed -i 's|^SENTRY_DSN=.*|SENTRY_DSN=http://key@ip:8000/1|' .env.local
docker compose -f compose.yaml -f compose.prod.yaml restart php
```

UI available at `http://<server-ip>:8000`.

### 7. GitHub Secrets

```bash
gh secret set DEPLOY_HOST --body "<server-ip>"
gh secret set DEPLOY_USER --body "root"
gh secret set DEPLOY_SSH_KEY < ~/.ssh/id_ed25519
gh secret set VITE_SENTRY_DSN --body "http://key@ip:8000/2"
gh api repos/<owner>/<repo>/environments/production -X PUT
```

### 8. Verify

```bash
curl -sf https://your-domain.com/api/v1/health
./scripts/smoke-test.sh
```

---

## Pitfalls & Gotchas

| Issue | Cause | Fix |
|---|---|---|
| `server type cx31 not found` | Hetzner deprecated old types | Use `cx23` (2 vCPU, 4GB) |
| `hashicorp/hcloud` provider error | Child modules resolve wrong provider | `versions.tf` in each module with `source = "hetznercloud/hcloud"` |
| `cloud-init status: error` | Volume mount race condition | Ignore — check `docker --version` instead |
| `dirname() undefined` in PHP | OPcache preload incompatible with FrankenPHP worker mode | Disable preload in prod ini |
| `password authentication failed` | compose.yaml `environment:` overrides `env_file:` | Set DATABASE_URL explicitly in compose.prod.yaml |
| Caddy TLS `permission denied` | Rootless container (uid 1001) can't write `/data` | `chown -R 1001:1001 /data /config` after first start |
| GlitchTip 500 on login | Missing `REDIS_URL`, wrong worker script | Set `REDIS_URL`, use `run-worker.sh` not `run-celery-with-beat.sh` |
| GlitchTip projects not visible | Projects not assigned to a team | Always create team + assign projects |

---

## Continuous Deployment (CD)

Every push to `main` triggers `.github/workflows/cd.yml`:

```
push to main → [CI Gate] → [Build & Push to GHCR] → [Deploy via SSH]
```

### Required GitHub Secrets

| Secret | Description |
|---|---|
| `DEPLOY_HOST` | Production server IP |
| `DEPLOY_USER` | SSH username (e.g. `root`) |
| `DEPLOY_SSH_KEY` | Private SSH key |
| `VITE_SENTRY_DSN` | Frontend Sentry DSN (baked into JS at build time) |

`GITHUB_TOKEN` is provided automatically.

### Environment Protection

1. **Settings → Environments → New environment** → name `production`
2. Enable **Required reviewers** (optional)
3. Add **Deployment branches** rule: `main` only

---

## Backups

Manual PostgreSQL backup:
```bash
./scripts/backup-postgres.sh
```

Restore:
```bash
./scripts/restore-postgres.sh /mnt/data/backups/smarthabit_YYYYMMDD_HHMMSS.sql.gz
```

---

## Rollback

### Via Git
Push a revert commit to `main` — CD pipeline rebuilds and deploys.

### Via image tag
```bash
./scripts/rollback.sh              # Roll back to previous :latest
./scripts/rollback.sh <commit-sha> # Roll back to specific SHA
```

### Emergency (no GHCR)
```bash
cd /opt/smarthabit
git fetch origin && git checkout <known-good-sha>
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```
