# SmartHabit Tracker — Findings

## Phase 0 Research

### Current State (2026-03-21)
- Project directory contains only `docs/`, `CLAUDE.md`, and `smarthabit-docs.zip`
- No code, no Docker files, no config — true greenfield
- All 8 Phase 0 sub-specs are complete and detailed in `docs/phase0/`
- Specs contain exact file contents (copy-paste ready for most files)

### Key Architecture Decisions (from docs)
- **FrankenPHP** replaces PHP-FPM + nginx (Worker Mode = Symfony stays in memory)
- **PgBouncer** Transaction Mode: web requests go through PgBouncer, Messenger Worker connects directly to DB (LISTEN/NOTIFY incompatible with transaction pooling)
- **Same-origin architecture**: no CORS needed — Caddy serves both API and frontend under one domain
- **adapter-static**: SPA mode, no SSR — correct for app-behind-login but no SEO on public pages
- **PCOV** over Xdebug for coverage (~5x faster)
- **PHPUnit 13**: sealed test doubles, createStub/createMock separation

### Potential Issues to Watch
1. **PgBouncer + Prepared Statements**: `PGBOUNCER_SERVER_RESET_QUERY=DISCARD ALL` is mandatory — without it, prepared statement errors occur in transaction mode
2. **FrankenPHP Worker Mode caveats**: Doctrine Identity Map must be cleared per-request, no request data in static properties, memory leak accumulation. The `FrankenPHPRuntime` handles most of this.
3. **Supercronic** for cron in dev container — needs to be included in Dockerfile dev stage
4. **CaptainHook git-directory**: captainhook.json lives in `backend/` but `git-directory` must point to `../.git` (repo root)
5. **Infection only on unit suite**: integration tests are too slow for mutation testing

### Fixes Discovered During Build (update spec if reused)
1. **FrankenPHP image tag**: Spec says `dunglas/frankenphp:latest-php8.4` — does NOT exist. Correct tag is `dunglas/frankenphp:php8.4`.
2. **bitnami/pgbouncer:1.23**: Does NOT exist on Docker Hub. Use `edoburu/pgbouncer:latest` instead. Env var names differ: use `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `POOL_MODE`, `SERVER_RESET_QUERY`, `AUTH_TYPE=plain`. Port is 5432 (not 6432 like bitnami).
3. **CODE_OF_CONDUCT.md content filter**: Contributor Covenant full text triggers content filter. Use a shorter, equivalent variant.
4. **runtime/frankenphp-symfony**: Remove — not needed for FrankenPHP worker mode and breaks Symfony 8 install (only supports ^7.0).
5. **doctrine/doctrine-bundle**: Use `^3.2` (not `^2.13`) for Symfony 8 support.
6. **symfony/monolog-bundle** and **symfony/security-bundle**: Must be added explicitly (not auto-discovered by flex in this setup).
7. **symfony/yaml**: Add as `require` (not dev) — needed for YAML config loading even when using PHP configs.
8. **YAML → PHP configs**: All Symfony configs must be PHP, not YAML. Use `ContainerConfigurator::extension()` array pattern; use `$container->env()` for env-specific overrides. The Symfony typed config API (FrameworkConfig etc.) has undocumented method naming issues.
9. **PgBouncer port 5432**: edoburu/pgbouncer listens on 5432, not 6432. Update DATABASE_URL.
10. **phpstan/extension-installer**: Does not generate GeneratedConfig.php reliably inside Docker (CaptainHook git-dir error interrupts post-install-cmd). Solution: list all extension .neon files explicitly in phpstan.neon includes.
11. **Rector set constants**: `PHPUNIT_130` → `PHPUNIT_120`, `SYMFONY_80` → `SYMFONY_74` (Symfony 8 rector sets don't exist yet in installed rector version).
12. **phpat 0.12.x API**: `shouldBeInNamespace()` removed. Use `should()->extend()`, `shouldNot()->dependOn()` chained API.
13. **phpunit.xml.dist DATABASE_URL**: Use base DB name `app` (not `app_test`). Doctrine adds `_test` suffix via `dbname_suffix`, resulting in `app_test`.
14. **PHPUnit APP_ENV**: Add `force="true"` to `<env name="APP_ENV" value="test"/>` in phpunit.xml.dist.
15. **Frontend missing packages**: `@eslint/js`, `globals`, `typescript-eslint`, `@types/node` all missing from package.json — add explicitly.
16. **tsconfig skipLibCheck**: Add `"skipLibCheck": true` to avoid errors in vite/rollup `.d.ts` type incompatibilities.
17. **FRANKENPHP_CONFIG="run"**: Invalid — remove from base Dockerfile stage. Only set `worker ./public/index.php` in prod stage.

### CI Fixes Applied Post-v1.0.0 (2026-03-22, squashed into one commit)

18. **CI runs inside Docker Compose**: Bare `ubuntu-latest` runner hid environment-specific bugs (different PHP binary, no FrankenPHP worker mode, no PgBouncer). Fix: single sequential job using `docker compose exec -T` with BuildKit layer cache + Composer package cache. 52s total CI time.
19. **CaptainHook in Docker**: `captainhook/plugin-composer` is a Composer PLUGIN (not script). `--no-scripts` never stopped it. Plugin fails because `.git` is not mounted. Fix: `allow-plugins: false` for the plugin + `force-install: false` in extra.
20. **DATABASE_URL + PgBouncer + dbname_suffix**: phpunit.xml.dist must `force="true"` DATABASE_URL to `database:5432/app` (direct Postgres). PgBouncer only proxies `app`, not `app_test` (created by dbname_suffix). Migrations also pass explicit DATABASE_URL.
21. **PCOV incompatible with FrankenPHP (ZTS)**: PCOV only supports NTS PHP. FrankenPHP uses ZTS for worker mode. Fix: use `XDEBUG_MODE=coverage` for PHPUnit/Infection.
22. **PHPUnit 13 coverage schema**: `<include>`/`<exclude>` moved from `<coverage>` to `<source>` element. Old location caused "No filter is configured" warning which failed via `failOnWarning="true"`.
23. **Infection with no mutable sources**: Template only has `Kernel.php` + `Controller` (both excluded). Infection's "No source file found" is a hard error. Fix: catch specific error and exit 0.
24. **Minor**: `.env` serverVersion=16→17, `--allow-no-migration` for empty migrations dir, `cache:warmup` for phpstan containerXmlPath, `image: app-php:dev` in compose.yaml for deterministic tagging.

### Spec Overlaps
The docs explicitly note: "After Phase 0, the code is the truth, not the docs." Some specs overlap intentionally (e.g., PHPStan config appears in `phase0/backend.md`, `docs/testing.md`, and `phase0/claude-spec.md`). The code files are the source of truth once written.

### Template vs SmartHabit Separation
Phase 0 creates a **generic** template with no domain logic. SmartHabit-specific additions come in Phase 1a:
- Domain folders (`src/Habit/`, etc.)
- SmartHabit phpat rules (DomainIsolationTest)
- Neo Utility design tokens
- Extended CLAUDE.md with project context
- Remove CONTRIBUTING.md, CODE_OF_CONDUCT.md, issue templates (not needed for private repo)
