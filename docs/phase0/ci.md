# GitHub Actions & CI/CD

#### `.github/workflows/ci.yml` — Backend CI

**Design principle**: CI runs inside the same Docker Compose stack as local development.
A bare `ubuntu-latest` runner uses a different PHP binary, php.ini, and no FrankenPHP worker mode —
hiding environment-specific bugs. With `docker compose exec`, CI and local are identical.

**Notes**:
- The inline YAML spec below shows a single sequential job for simplicity. The actual implementation uses **4 parallel Docker Compose jobs** (ECS, PHPStan, Rector, Tests) for faster CI runs.
- `--no-scripts` on `composer install` prevents `captainhook/plugin-composer` from running `captainhook install` in CI.
- Tests bypass PgBouncer and connect directly to Postgres (`database:5432`). PgBouncer transaction mode only
  proxies the `app` DB; `app_test` (via `dbname_suffix`) is unreachable through it.
- `phpunit.xml.dist` enforces `DATABASE_URL=database:5432/app` via `force="true"` for all PHPUnit/Infection runs.
- `app_test` is created by `docker/postgres/init.sql` on first container startup (no `doctrine:database:create` needed).

```yaml
name: Backend CI

on:
  push:
    branches: [main]
    paths:
      - 'backend/**'
      - 'docker/**'
      - 'compose*.yaml'
      - '.github/workflows/ci.yml'
  pull_request:
    branches: [main]
    paths:
      - 'backend/**'
      - 'docker/**'
      - 'compose*.yaml'
      - '.github/workflows/ci.yml'

jobs:
  backend:
    runs-on: ${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}
    timeout-minutes: 30

    steps:
      - uses: actions/checkout@v4

      - name: Build PHP dev image
        run: docker compose build php

      - name: Start services (database + pgbouncer + php)
        run: docker compose up -d database pgbouncer php

      - name: Wait for PostgreSQL
        run: |
          for i in $(seq 1 30); do
            docker compose exec -T database pg_isready -U app && break
            sleep 2
          done

      - name: Wait for Composer install (docker-entrypoint.sh)
        run: |
          for i in $(seq 1 60); do
            docker compose exec -T php test -d /app/vendor && break
            sleep 3
          done

      - name: Reinstall Composer deps with --no-scripts
        run: docker compose exec -T php composer install --no-interaction --prefer-dist --no-scripts

      - name: ECS (coding standard)
        run: docker compose exec -T php vendor/bin/ecs check

      - name: Warm Symfony cache (generates container XML for PHPStan)
        run: docker compose exec -T php php bin/console cache:warmup

      - name: PHPStan (static analysis)
        run: docker compose exec -T php vendor/bin/phpstan analyse --memory-limit=512M

      - name: Rector (dry-run)
        run: docker compose exec -T php vendor/bin/rector process --dry-run

      - name: Migrate test database
        run: |
          docker compose exec -T \
            -e APP_ENV=test \
            -e DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/app?serverVersion=17&sslmode=disable" \
            php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

      - name: PHPUnit (Unit + Integration)
        run: |
          docker compose exec -T \
            -e APP_ENV=test \
            -e XDEBUG_MODE=coverage \
            php vendor/bin/phpunit \
              --coverage-clover=var/coverage/clover.xml \
              --coverage-xml=var/coverage/coverage-xml \
              --log-junit=var/coverage/junit.xml

      - name: Infection (mutation testing)
        run: |
          docker compose exec -T \
            -e APP_ENV=test \
            -e XDEBUG_MODE=coverage \
            php vendor/bin/infection \
              --threads=4 --coverage=var/coverage \
              --skip-initial-tests --min-msi=80 --min-covered-msi=90
```

#### `.github/workflows/ci-frontend.yml` — Frontend CI

```yaml
name: Frontend CI

on:
  push:
    branches: [main]
    paths: ['frontend/**', '.github/workflows/ci-frontend.yml']
  pull_request:
    branches: [main]
    paths: ['frontend/**', '.github/workflows/ci-frontend.yml']

jobs:
  lint-and-build:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: frontend
    steps:
      - uses: actions/checkout@v4
      - uses: oven-sh/setup-bun@v2
        with:
          bun-version: latest
      - run: bun install --frozen-lockfile
      - name: ESLint
        run: bun run lint
      - name: Svelte Check (TypeScript)
        run: bun run check
      - name: Build
        run: bun run build
```

#### `.github/workflows/dependabot-automerge.yml`

Auto-merge for Dependabot: patches always, minor updates for quality tools (PHPStan extensions, etc.) as well — if CI is green:

```yaml
name: Dependabot Auto-Merge

on: pull_request

permissions:
  contents: write
  pull-requests: write

jobs:
  auto-merge:
    runs-on: ubuntu-latest
    if: github.actor == 'dependabot[bot]'
    steps:
      - uses: dependabot/fetch-metadata@v2
        id: metadata

      # Patches: always auto-merge
      - if: steps.metadata.outputs.update-type == 'version-update:semver-patch'
        run: gh pr merge --auto --squash "$PR_URL"
        env:
          PR_URL: ${{ github.event.pull_request.html_url }}
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}

      # Minor: only for quality tools (PHPStan, Rector, ECS, Infection, etc.)
      - if: >
          steps.metadata.outputs.update-type == 'version-update:semver-minor' &&
          contains(steps.metadata.outputs.dependency-group, 'phpstan')
        run: gh pr merge --auto --squash "$PR_URL"
        env:
          PR_URL: ${{ github.event.pull_request.html_url }}
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

#### `.github/dependabot.yml`

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /backend
    schedule:
      interval: weekly
    open-pull-requests-limit: 10
    groups:
      phpstan:
        patterns: ["phpstan/*", "tomasvotruba/*", "shipmonk/*", "voku/*", "phpat/*"]
      symfony:
        patterns: ["symfony/*"]
  - package-ecosystem: npm
    directory: /frontend
    schedule:
      interval: weekly
    open-pull-requests-limit: 10
  - package-ecosystem: docker
    directory: /docker/frankenphp
    schedule:
      interval: monthly
  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: monthly
```

#### `.github/workflows/claude-update.yml` — Biweekly Dependency Update

Dependabot only does version bumps in `composer.json`/`package.json`. This pipeline uses Claude Code to handle the rest: adapt Rector rules to new versions, PHPStan config changes, fix breaking changes, resolve deprecation warnings, SvelteKit migrations. Runs every 2 weeks as a cron job.

```yaml
name: Claude Dependency Update

on:
  schedule:
    - cron: '0 6 1,15 * *'     # 1st and 15th of every month, 06:00 UTC
  workflow_dispatch:             # Manually triggerable

permissions:
  contents: write
  pull-requests: write
  issues: write

jobs:
  update-dependencies:
    runs-on: ubuntu-latest
    # Alternative: runs-on: ${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}
    timeout-minutes: 30
    services:
      postgres:
        image: postgres:17-alpine
        env:
          POSTGRES_USER: app
          POSTGRES_PASSWORD: test
          POSTGRES_DB: app_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd="pg_isready -U app"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=5
    env:
      DATABASE_URL: postgresql://app:test@localhost:5432/app_test?serverVersion=17&sslmode=disable
      APP_ENV: test
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: intl, pdo_pgsql
          coverage: xdebug

      - uses: oven-sh/setup-bun@v2
        with:
          bun-version: latest

      - uses: anthropics/claude-code-action@v1
        with:
          anthropic_api_key: ${{ secrets.ANTHROPIC_API_KEY }}
          prompt: |
            You are a dependency update agent for a PHP 8.4 + Symfony 8 + SvelteKit project.
            Read CLAUDE.md and .claude/ for the project conventions.

            Perform the following steps:

            ## 1. Backend Updates (backend/)
            - `cd backend && composer update --no-interaction`
            - Check if `composer outdated --direct` still shows outdated packages
            - Run `vendor/bin/rector process` to migrate code to new API versions
            - Run `vendor/bin/ecs check --fix` to fix code style
            - Run `vendor/bin/phpstan analyse` — if errors occur:
              - Analyze whether they are breaking changes in updated packages
              - Fix the errors (NOT with ignoreErrors, but in the code)
              - If PHPStan extension config has changed, update phpstan.neon
            - Run `vendor/bin/phpunit` — if tests fail:
              - Analyze whether they are API changes in updated dependencies
              - Fix the tests

            ## 2. Frontend Updates (frontend/)
            - `cd frontend && bun update`
            - `bun run check` — fix TypeScript errors if needed
            - `bun run build` — fix build errors if needed
            - Check if SvelteKit has breaking changes (read migration guide on major bump)

            ## 3. Quality Assurance
            - Ensure ALL of the following checks pass:
              - `cd backend && vendor/bin/ecs check`
              - `cd backend && vendor/bin/phpstan analyse`
              - `cd backend && vendor/bin/rector process --dry-run`
              - `cd backend && vendor/bin/phpunit`
              - `cd frontend && bun run check`
              - `cd frontend && bun run build`

            ## 4. Result
            - If changes are present: create a branch `chore/dependency-update-YYYY-MM-DD`
            - Commit all changes with Conventional Commit messages:
              - `chore(deps): update backend dependencies`
              - `chore(deps): update frontend dependencies`
              - `fix: resolve breaking changes from X upgrade` (if needed)
            - Create a PR with a summary:
              - Which packages were updated (backend + frontend)
              - Which breaking changes occurred and how they were resolved
              - Which Rector transformations were applied
              - Whether all quality checks are green

            ## Important
            - Do NOT add new `@phpstan-ignore` without justification
            - Do NOT lower quality thresholds (MSI, coverage, cognitive complexity)
            - If an update cannot be fixed: do NOT update the package, document why in the PR description
            - For major version bumps of Symfony, SvelteKit, or Doctrine: be especially careful, consult the migration guide

          claude_args: "--max-turns 25 --model sonnet"
```

**Warning: The PR from this pipeline is NEVER auto-merged.** Always review manually — Claude can make technically correct but semantically wrong fixes, especially with API changes. `--max-turns 25` (rather than higher) prevents Claude from getting stuck in loops.

**Recommendation**: Enable this pipeline only after Phase 1b, when enough code and tests exist for Claude to use as context. On a nearly empty template repo, it is pointless.

#### `.github/workflows/claude.yml` — Interactive @claude Mentions

Enables `@claude` in issues and PR comments. Claude reads CLAUDE.md + `.claude/` conventions and can answer questions or implement code changes.

```yaml
name: Claude Code

on:
  issue_comment:
    types: [created]
  pull_request_review_comment:
    types: [created]
  issues:
    types: [opened, assigned]

permissions:
  contents: write
  pull-requests: write
  issues: write

jobs:
  claude:
    runs-on: ubuntu-latest
    # Alternative: runs-on: ${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}
    timeout-minutes: 15
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 1

      - uses: anthropics/claude-code-action@v1
        with:
          anthropic_api_key: ${{ secrets.ANTHROPIC_API_KEY }}
          claude_args: "--max-turns 20 --model sonnet"
```

#### `.github/workflows/claude-review.yml` — Automatic Code Review

Runs automatically on every PR. Claude reviews the diff, checks against the coding guidelines, and posts findings as review comments.

```yaml
name: Claude Code Review

on:
  pull_request:
    types: [opened, synchronize]

permissions:
  contents: read
  pull-requests: write

jobs:
  review:
    runs-on: ubuntu-latest
    # Not for Dependabot PRs (those are auto-merged)
    if: github.actor != 'dependabot[bot]'
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - uses: anthropics/claude-code-action@v1
        with:
          anthropic_api_key: ${{ secrets.ANTHROPIC_API_KEY }}
          prompt: |
            Review this pull request. Read .claude/ for the coding conventions.

            Check in particular:
            1. Coding guidelines: declare(strict_types=1), final readonly class, Value Objects, early returns, max 20 lines/method, max 3 parameters
            2. Missing test? Every new code path needs a test.
            3. Naming: verbs for methods, Exception suffix, enums for status fields
            4. Domain isolation: no framework imports in domain services
            5. TypeScript: strict, no any, Svelte 5 Runes (not legacy $:)
            6. i18n: no hardcoded user-facing strings
            7. Security: no secrets in code, no unsafe input handling

            Post your findings as review comments on the relevant lines.
            Be constructive — explain why something is problematic and how to do it better.
            Ignore purely cosmetic issues that ECS/Rector would auto-fix.

          claude_args: "--max-turns 10 --model sonnet"
```

#### `.github/PULL_REQUEST_TEMPLATE.md`

```markdown
## What changed?

<!-- Brief description -->

## Type

- [ ] Feature
- [ ] Bugfix
- [ ] Refactoring
- [ ] Dependency update
- [ ] Docs

## Checklist

- [ ] Tests written/updated
- [ ] PHPStan + ECS + Rector green locally (`make quality`)
- [ ] No new `@phpstan-ignore` without justification
- [ ] Strings externalized (i18n)
- [ ] Migrations included (if DB changes)
```
