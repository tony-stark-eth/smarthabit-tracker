#!/usr/bin/env bash
# Rollback to the previous deployment
#
# Usage: ./scripts/rollback.sh [image-tag]
# If no tag specified, rolls back to the previous :latest

set -euo pipefail

REPO="ghcr.io/tony-stark-eth/smarthabit-tracker/app"
TAG="${1:-latest}"
COMPOSE_FILES="-f compose.yaml -f compose.prod.yaml"

echo "Rolling back to ${REPO}:${TAG}..."

docker pull "${REPO}:${TAG}"
docker tag "${REPO}:${TAG}" "${REPO}:current"

docker compose ${COMPOSE_FILES} up -d --no-build

sleep 10
if curl -sf http://localhost/api/v1/health > /dev/null; then
    echo "Rollback successful"
else
    echo "ERROR: Health check failed after rollback"
    exit 1
fi
