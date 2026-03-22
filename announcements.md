# Template Announcements

## X / Twitter

```
I open-sourced my Symfony 8 + SvelteKit full-stack template 🔧

PHP 8.4 · FrankenPHP worker mode · 10 PHPStan extensions at level max · Mutation testing · Docker Compose CI (same env as local) · OpenTofu infra · Claude Code integration

MIT licensed, ready to fork:
https://github.com/tony-stark-eth/template-symfony-sveltekit

#PHP #Symfony #SvelteKit #OpenSource #FrankenPHP
```

---

## Reddit r/PHP

**Title:** I built an opinionated Symfony 8 + SvelteKit full-stack template with 10 PHPStan extensions at level max

```
Hey r/PHP,

I just released a full-stack project template that packages everything I wish I had when starting new projects: PHP 8.4 + Symfony 8 backend with a SvelteKit 2 frontend, all running on FrankenPHP in Docker.

**What makes it different from other starters:**

- **PHPStan level max with 10 extensions** — strict-rules, deprecation-rules, shipmonk-rules, voku-rules, cognitive-complexity, type-coverage (100%), phpat architecture tests, doctrine, symfony, phpunit
- **Mutation testing** via Infection with MSI thresholds
- **FrankenPHP worker mode** — Symfony stays in memory between requests, Caddy handles TLS/HTTP3
- **PgBouncer** transaction pooling (essential for worker mode at scale)
- **CI runs inside Docker Compose** — same PHP binary, same extensions, same php.ini as local. No more "works on my machine" CI failures
- **4 parallel CI jobs** with BuildKit + Composer caching (~1min total)
- **Same-origin architecture** — Caddy serves both API and SvelteKit under one domain, zero CORS
- **OpenTofu modules** for Hetzner deployment
- **Claude Code integration** — .claude/ guidelines, auto code review on PRs, biweekly dependency updates

The quality stack is strict by design — ECS, PHPStan, Rector, PHPUnit path coverage, Infection, phpat architecture tests. Everything enforced in CI from commit zero.

MIT licensed, designed as a GitHub template — click "Use this template" and you have a production-ready setup in minutes.

https://github.com/tony-stark-eth/template-symfony-sveltekit

Happy to answer questions or take feedback!
```

---

## Reddit r/symfony

**Title:** Full-stack Symfony 8 + SvelteKit template with FrankenPHP, PgBouncer, and 10 PHPStan extensions

```
Released a Symfony 8 project template that goes beyond the basics:

- FrankenPHP worker mode (Symfony stays in memory)
- PgBouncer transaction pooling between app and Postgres
- Messenger worker with direct DB connection (LISTEN/NOTIFY)
- PHPStan level max + 10 extensions (strict-rules, shipmonk, voku, cognitive-complexity, type-coverage 100%, phpat)
- Rector (PHP 8.4 + Symfony sets), ECS (PSR-12 + strict)
- PHPUnit 13 with Xdebug coverage + Infection mutation testing
- Doctrine migrations auto-run on container start
- JWT auth scaffold (lexik/jwt-authentication-bundle)
- Rate limiting ready (symfony/rate-limiter)

Frontend is SvelteKit 2 + Svelte 5 + Bun + Tailwind 4, served by Caddy under the same domain (no CORS).

CI runs inside the same Docker Compose stack as local dev — catches environment-specific bugs that bare GitHub runners miss. 4 parallel jobs, ~1min with warm cache.

Built on patterns from dunglas/symfony-docker but extended with connection pooling, async workers, frontend integration, and OpenTofu infrastructure modules.

https://github.com/tony-stark-eth/template-symfony-sveltekit

MIT licensed. Feedback welcome!
```

---

## Reddit r/sveltejs

**Title:** SvelteKit 2 + Symfony 8 full-stack template — same-origin, Docker, TypeScript strict

```
Built a full-stack template that pairs SvelteKit 2 with a Symfony 8 API backend, all in one Docker Compose stack:

**Frontend:**
- SvelteKit 2 + Svelte 5 + Bun + Tailwind 4
- TypeScript strict (noUncheckedIndexedAccess, etc.)
- adapter-static (SPA mode)
- ESLint + Svelte Check + Playwright E2E
- PWA-ready (manifest, service worker scaffold)

**Why SvelteKit + Symfony?**
- Same-origin architecture — Caddy serves both the SvelteKit build and the API under one domain. No CORS, no proxy headaches.
- Vite dev server proxies /api to the PHP backend in development
- Production: static SvelteKit build served by Caddy alongside the API

**Backend (for context):**
- PHP 8.4 + Symfony 8 on FrankenPHP (worker mode)
- PostgreSQL 17 + PgBouncer
- PHPStan level max, mutation testing, architecture tests

CI runs inside Docker Compose with 4 parallel jobs. MIT licensed.

https://github.com/tony-stark-eth/template-symfony-sveltekit
```

---

## Reddit r/selfhosted

**Title:** Self-hosted full-stack template: Symfony 8 + SvelteKit + FrankenPHP + OpenTofu for Hetzner

```
Released a project template for self-hosted web apps:

- **FrankenPHP** — single binary serves PHP + static files + TLS (replaces nginx + PHP-FPM)
- **PostgreSQL 17** + PgBouncer connection pooling
- **Docker Compose** with dev/prod separation (compose.yaml + compose.override.yaml + compose.prod.yaml)
- **OpenTofu modules** for Hetzner Cloud (server, network, volume, DNS) — ready to `tofu apply`
- **Auto-migrations** on container start, DB readiness checks in entrypoint
- **Rootless production container** with setuid/setgid bit removal
- **CI runs inside Docker** — same environment as your server

Stack: PHP 8.4, Symfony 8, SvelteKit 2, Tailwind 4, Bun, Playwright E2E.

Designed for single-server Hetzner deployments. The template is a GitHub template — fork it, change the domain, `tofu apply`, done.

https://github.com/tony-stark-eth/template-symfony-sveltekit

MIT licensed.
```

---

## Symfony Slack / Svelte Discord (short message)

```
Just released a full-stack template: Symfony 8 + SvelteKit 2 + FrankenPHP + Docker

Highlights: PHPStan level max with 10 extensions, mutation testing, CI inside Docker Compose, PgBouncer, OpenTofu for Hetzner, Claude Code integration.

MIT licensed: https://github.com/tony-stark-eth/template-symfony-sveltekit

Feedback welcome!
```

---

## Dev.to / Hashnode Blog Post

**Title:** I Built an Opinionated Symfony 8 + SvelteKit Template — Here's Why Every Decision Matters

**Tags:** php, symfony, svelte, docker, opensource

```markdown
## The Problem

Every new project starts the same way: Docker setup, quality tooling, CI pipeline, frontend scaffold, deployment config. It takes days before you write your first line of domain code.

I built a template that eliminates that overhead — opinionated, strict, and production-ready from commit zero.

**GitHub:** https://github.com/tony-stark-eth/template-symfony-sveltekit

## The Stack

| Layer | Choice | Why |
|---|---|---|
| PHP Runtime | FrankenPHP | Worker mode keeps Symfony in memory. One binary replaces nginx + PHP-FPM |
| Backend | Symfony 8 + PHP 8.4 | Property hooks, asymmetric visibility, LTS until 2028 |
| Frontend | SvelteKit 2 + Svelte 5 | Runes, Bun, Tailwind 4, adapter-static |
| Database | PostgreSQL 17 + PgBouncer | Transaction pooling prevents connection exhaustion in worker mode |
| Infrastructure | OpenTofu | Hetzner Cloud modules (server, network, volume, DNS) |
| CI | GitHub Actions inside Docker Compose | Same environment as local — no "works on my machine" |

## The Quality Stack (This Is the Point)

The template ships with **10 PHPStan extensions at level max**:

1. `phpstan-strict-rules` — catches loose comparisons, missing return types
2. `phpstan-deprecation-rules` — flags deprecated API usage
3. `phpstan-symfony` — understands the DI container, validates service types
4. `phpstan-doctrine` — checks DQL, entity mappings, repository return types
5. `phpstan-phpunit` — catches common test mistakes
6. `shipmonk-rules` — 40+ extra strict rules (enum safety, readonly enforcement, banned functions)
7. `voku-rules` — type compatibility for operators, null-safety
8. `cognitive-complexity` — limits mental load per function (max 8) and class (max 50)
9. `type-coverage` — enforces 100% type coverage on params, returns, properties, constants
10. `phpat` — architecture tests (layer dependencies, naming conventions)

Plus: ECS (PSR-12 + strict), Rector (PHP 8.4 + Symfony 8 sets), PHPUnit 13 path coverage, Infection mutation testing.

## CI Inside Docker — A Key Decision

Most Symfony CI pipelines use `shivammathur/setup-php` on a bare Ubuntu runner. This creates a hidden gap: the CI PHP binary, php.ini, and extensions differ from the Docker image you deploy.

Our template runs CI inside the same Docker Compose stack:

- `docker compose run` for static analysis (no DB needed)
- `docker compose exec` for tests (full stack with PostgreSQL + PgBouncer)
- BuildKit layer caching + Composer package caching → ~1min total

When CI and local dev use the same container, "works on my machine" disappears.

## FrankenPHP + PgBouncer: Why Both

FrankenPHP's worker mode keeps PHP processes alive between requests. This is great for performance but creates a problem: each worker holds a persistent database connection. With 20 workers, you need 20 connections — and PostgreSQL's default `max_connections=100` fills up fast.

PgBouncer in transaction mode solves this: workers share a pool of 20 connections, releasing them after each transaction. The template configures this out of the box, including `SERVER_RESET_QUERY=DISCARD ALL` for session state safety.

The Messenger worker connects directly to PostgreSQL (not through PgBouncer) because LISTEN/NOTIFY — which Doctrine Messenger uses for queue detection — is incompatible with transaction pooling.

## Same-Origin Architecture

No CORS. Caddy serves both the API (`/api/*` → Symfony) and the SvelteKit build (everything else → static files) under one domain. In development, Vite proxies API calls to FrankenPHP.

This simplifies auth (cookies just work), eliminates preflight requests, and removes an entire class of deployment bugs.

## Get Started

```bash
# Use as GitHub template
gh repo create my-app --template tony-stark-eth/template-symfony-sveltekit --private
cd my-app

# Start everything
docker compose --profile dev up -d

# Run quality checks
make quality
```

MIT licensed. Feedback and PRs welcome.

https://github.com/tony-stark-eth/template-symfony-sveltekit
```
