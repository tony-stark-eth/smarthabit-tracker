# Docker Setup

#### `docker/frankenphp/Dockerfile`

Multi-stage build based on `dunglas/symfony-docker`:

- **Stage `frankenphp_base`**: `dunglas/frankenphp:php8.4`, Extensions: `pdo_pgsql`, `intl`, `zip`, `opcache`, `apcu` (via `install-php-extensions`). Composer install via `COPY --from=composer:latest`.
- **Stage `frankenphp_dev`**: Xdebug optional (`PHP_XDEBUG_MODE` ENV), Supercronic for cron jobs, Symfony CLI for debug tools.
- **Stage `frankenphp_prod`**: Rootless, Debian Slim, runtime artifacts only. `FRANKENPHP_CONFIG="worker ./public/index.php"`. `APP_ENV=prod`, `APP_DEBUG=0`. OPcache preload. ~290MB image.

> **Note**: `ext-pcov` has been removed from the Dockerfile. FrankenPHP uses ZTS (Zend Thread Safety), and PCOV only supports NTS (Non-Thread Safe) builds. Use Xdebug with `XDEBUG_MODE=coverage` instead.
> **Note**: `runtime/frankenphp-symfony` has been removed — it is not needed and breaks Symfony 8.

#### `docker/frankenphp/Caddyfile`

Caddy routing for same-origin architecture — frontend and API under one domain, no CORS:

```
{$SERVER_NAME:localhost} {
    log

    # API → Symfony/FrankenPHP
    handle /api/* {
        php_server
    }

    # Mercure Hub (SSE)
    handle /.well-known/mercure {
        mercure {
            transport_url {$MERCURE_TRANSPORT_URL:bolt:///data/mercure.db}
            publisher_jwt {env.MERCURE_JWT_SECRET}
            subscriber_jwt {env.MERCURE_JWT_SECRET}
        }
    }

    # Everything else → SvelteKit Static Build (SPA Fallback)
    handle {
        root * /app/frontend/build
        try_files {path} /index.html
        file_server
    }
}
```

**Dev**: Vite dev server runs on port 5173 with its own proxy. Caddy serves only the API in dev.
**Prod**: Caddy serves API + frontend static build under one domain. `bun run build` output is in `/app/frontend/build`.

#### `compose.yaml`

```yaml
services:
  php:
    build:
      context: .
      dockerfile: docker/frankenphp/Dockerfile
      target: frankenphp_dev
    volumes:
      - ./backend:/app
    environment:
      - DATABASE_URL=postgresql://app:!ChangeMe!@pgbouncer:5432/app?serverVersion=17&sslmode=disable&charset=utf8
      - MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
      - MERCURE_URL=https://php/.well-known/mercure
      - MERCURE_PUBLIC_URL=https://localhost/.well-known/mercure
      - MERCURE_JWT_SECRET=!ChangeThisMercureHubJWTSecretKey!
    depends_on:
      database:
        condition: service_healthy

  database:
    image: postgres:17-alpine
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: "!ChangeMe!"
      POSTGRES_DB: app
    volumes:
      - db_data:/var/lib/postgresql/data
      - ./docker/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql
    healthcheck:
      test: ["CMD", "pg_isready", "-U", "app"]
      interval: 5s
      timeout: 5s
      retries: 5

  pgbouncer:
    image: edoburu/pgbouncer:latest
    environment:
      POSTGRESQL_HOST: database
      POSTGRESQL_PORT: 5432
      POSTGRESQL_USERNAME: app
      POSTGRESQL_PASSWORD: "!ChangeMe!"
      POSTGRESQL_DATABASE: app
      PGBOUNCER_POOL_MODE: transaction
      PGBOUNCER_MAX_CLIENT_CONN: 100
      PGBOUNCER_DEFAULT_POOL_SIZE: 20
      PGBOUNCER_SERVER_RESET_QUERY: "DISCARD ALL"   # Required for Transaction Mode + Doctrine
    depends_on:
      database:
        condition: service_healthy

  messenger-worker:
    build:
      context: .
      dockerfile: docker/frankenphp/Dockerfile
      target: frankenphp_dev
    command: php bin/console messenger:consume async --time-limit=3600
    volumes:
      - ./backend:/app
    environment:
      # DIRECTLY to DB, NOT via PgBouncer (LISTEN/NOTIFY)
      - DATABASE_URL=postgresql://app:!ChangeMe!@database:5432/app?serverVersion=17&sslmode=disable&charset=utf8
    depends_on:
      database:
        condition: service_healthy
    restart: unless-stopped

  bun:
    image: oven/bun:1.3-alpine
    working_dir: /app
    volumes:
      - ./frontend:/app
    command: bun run dev --host 0.0.0.0
    ports:
      - "5173:5173"
    profiles:
      - dev

volumes:
  db_data:
```

#### `compose.override.yaml` — Dev Overrides

Automatically loaded by Docker Compose when present. Contains dev-specific ports, volumes, and debug settings:

```yaml
services:
  php:
    ports:
      - "443:443"        # HTTPS (Caddy auto-TLS)
      - "443:443/udp"    # HTTP/3
      - "80:80"          # HTTP → Redirect to HTTPS
    volumes:
      - ./backend:/app
      - php_cache:/app/var
    environment:
      - APP_ENV=dev
      - APP_DEBUG=1
      - XDEBUG_MODE=${XDEBUG_MODE:-off}
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/api/v1/health"]
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 30s

volumes:
  php_cache:
```

#### `compose.prod.yaml` — Prod Overrides

Used via `docker compose -f compose.yaml -f compose.prod.yaml up -d`:

```yaml
services:
  php:
    build:
      target: frankenphp_prod    # Rootless, Debian Slim, Worker Mode
    environment:
      - APP_ENV=prod
      - APP_DEBUG=0
      - FRANKENPHP_CONFIG=worker ./public/index.php
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/api/v1/health"]
      interval: 30s
      timeout: 5s
      retries: 3

  messenger-worker:
    build:
      target: frankenphp_prod
    environment:
      - APP_ENV=prod
      - APP_DEBUG=0
    restart: unless-stopped

  # No Bun dev server in prod — frontend is built and served by Caddy
```

#### `docker/postgres/init.sql`

```sql
-- Test database for integration tests
CREATE DATABASE app_test;
GRANT ALL PRIVILEGES ON DATABASE app_test TO app;
```
