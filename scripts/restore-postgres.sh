#!/usr/bin/env bash
set -euo pipefail

# Restore PostgreSQL from a backup file
# Usage: ./scripts/restore-postgres.sh backups/smarthabit_20260322_030000.sql.gz

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <backup-file.sql.gz>"
    echo "Available backups:"
    ls -la /mnt/data/backups/smarthabit_*.sql.gz 2>/dev/null || echo "  (none found)"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: File not found: $BACKUP_FILE"
    exit 1
fi

echo "[$(date -Is)] Restoring from: $BACKUP_FILE"
echo "WARNING: This will drop and recreate the database. Press Ctrl+C to cancel."
read -r -p "Continue? [y/N] " confirm
if [[ ! "$confirm" =~ ^[yY]$ ]]; then
    echo "Cancelled."
    exit 0
fi

docker compose exec -T database dropdb -U app --if-exists app
docker compose exec -T database createdb -U app app
gunzip -c "$BACKUP_FILE" | docker compose exec -T database psql -U app app

echo "[$(date -Is)] Restore complete."
