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
docker tag "${REPO}:${TAG}" app-php:prod

# --wait blocks until health check passes (entrypoint handles migrations)
docker compose ${COMPOSE_FILES} up -d --no-build --wait --wait-timeout 120

echo "Rollback successful"
