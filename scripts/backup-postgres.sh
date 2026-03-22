#!/usr/bin/env bash
set -euo pipefail

# PostgreSQL backup script — run via cron nightly
# Writes compressed SQL dump to /mnt/data/backups/

BACKUP_DIR="${BACKUP_DIR:-/mnt/data/backups}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS="${RETENTION_DAYS:-7}"

mkdir -p "$BACKUP_DIR"

echo "[$(date -Is)] Starting PostgreSQL backup..."

docker compose exec -T database pg_dump -U app app | gzip > "${BACKUP_DIR}/smarthabit_${TIMESTAMP}.sql.gz"

echo "[$(date -Is)] Backup saved: smarthabit_${TIMESTAMP}.sql.gz"

# Cleanup old backups
find "$BACKUP_DIR" -name "smarthabit_*.sql.gz" -mtime +${RETENTION_DAYS} -delete

echo "[$(date -Is)] Cleanup complete (kept last ${RETENTION_DAYS} days)"
