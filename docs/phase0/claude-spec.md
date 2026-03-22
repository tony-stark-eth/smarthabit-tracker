# CLAUDE.md & .claude/ Directory Specification

The CLAUDE.md is intentionally lean — only quick start, project type, and references. The actual rules live in `.claude/` and are read automatically by Claude Code.

#### `CLAUDE.md` — Entry Point (~30 lines)

```markdown
# Claude Code Instructions

## Quick Start

```
docker compose --profile dev up -d
cd backend && composer install
cd frontend && bun install
make quality
```

## Project

Full-stack mono-repo: `backend/` (Symfony 8 API) + `frontend/` (SvelteKit PWA).

## Guidelines

Coding and architecture rules in `.claude/`:

- `.claude/coding-php.md` — PHP Coding Guidelines
- `.claude/coding-frontend.md` — Svelte / TypeScript Guidelines
- `.claude/testing.md` — Testing, PHPStan, CI Pipeline
- `.claude/architecture.md` — Docker, DB, Directory Structure, Patterns

## Prohibitions

- No React — Svelte 5 or plain JS
- No `DateTime` — only `DateTimeImmutable`
- No `var_dump`/`dump`/`dd`
- No `ignoreErrors` in phpstan.neon
- No `empty()`
- Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`
```

#### `.claude/coding-php.md` — PHP Coding Guidelines

Generic, no project-specific references. Content:

```markdown
# PHP Coding Guidelines

## Basics

- `declare(strict_types=1)` — first line, every PHP file, no exceptions
- `final readonly class` as default — only break open when extension is needed
- `DateTimeImmutable` always, `DateTime` is forbidden (PHPStan enforced)
- Constructor injection only — no service locator, no property injection

## Methods

- Max 20 lines per method
- Max 3 parameters — more → DTO or Value Object
- Cognitive complexity max 8 (enforced via PHPStan)
- One abstraction level per method
- Method names are verbs: `findActiveHabits()`, not `habits()`

## Classes

- Max ~150 lines (excluding imports/docblocks)
- Cognitive complexity max 50 per class (enforced via PHPStan)
- Max 5 dependencies in the constructor
- `find*` may return null, `get*` throws an exception

## Patterns

- Value Objects for domain concepts (no primitive obsession)
- Early returns — max nesting depth 2
- Composition over inheritance
- Immutability by default
- Enums instead of magic values
- Specific exceptions, never swallowed

## Directory Structure

Domain-based, not framework-based:

```
src/
├── Feature/           ← per domain concept
│   ├── Controller/
│   ├── Entity/
│   ├── Repository/
│   ├── Service/
│   ├── Event/
│   ├── Exception/
│   └── ValueObject/
└── Shared/            ← cross-domain
```

Not: `src/Entity/`, `src/Service/`, `src/Controller/` as flat directories.

## Naming Conventions

- Controller: `{Feature}Controller`
- Service: `{Action}Service` or `{Feature}Handler`
- Exception: `{What}Exception` — must live in `Exception/` namespace (phpat enforced)
- Value Object: name describes the value, e.g. `Email`, `TimeWindow`, `InviteCode`
- Test: `{ClassUnderTest}Test`
- Enums: PascalCase singular, e.g. `PushType`, `HabitFrequency`
```

#### `.claude/coding-frontend.md` — Frontend Guidelines

```markdown
# Frontend Coding Guidelines

## TypeScript

- strict mode, no `any`
- `noUncheckedIndexedAccess: true`
- `noImplicitOverride: true`
- `exactOptionalPropertyTypes: true`

## Svelte 5

- Runes only: `$state()`, `$derived()`, `$effect()` — no legacy (`$:`, `let x = 0`)
- Max ~100 lines per component (template + script + style)
- Props with defaults and types, no `$$props` or `$$restProps`
- No business logic in components → extract into `$lib/` modules
- No direct `fetch` in components → use API wrappers from `$lib/api/`

## Styling

- Tailwind 4 utility classes
- CSS Custom Properties for design tokens (colors, fonts, spacing)
- Dark mode via CSS Custom Properties, not via Tailwind `dark:`
- Mobile-first

## i18n

- All visible strings as translation keys
- No hardcoded strings in components
- Files: `$lib/i18n/de.ts`, `$lib/i18n/en.ts`

## Accessibility

- ARIA labels on interactive elements
- Keyboard navigation
- Contrast WCAG AA minimum
- Focus management for modals/dialogs
```

#### `.claude/testing.md` — Testing Guidelines

```markdown
# Testing & Code Quality

## Composer Dev Dependencies

14 packages — see `backend/composer.json`

## PHPStan — Level max + 10 Extensions

Configuration in `backend/phpstan.neon`. Extensions:

| Extension | What it checks |
|---|---|
| `phpstan-strict-rules` | `===`, no `empty()`, strict `in_array()` |
| `phpstan-deprecation-rules` | Deprecated code (PHP, Symfony, Doctrine) |
| `shipmonk/phpstan-rules` | ~40 rules: enum safety, forgotten exceptions, custom bans |
| `voku/phpstan-rules` | Operator type compatibility, assignment-in-condition |
| `tomasvotruba/cognitive-complexity` | function: 8, class: 50 |
| `tomasvotruba/type-coverage` | return: 100, param: 100, property: 100, constant: 100, declare: 100 |
| `phpat/phpat` | Architecture tests (layer, naming) |
| `phpstan-symfony` | Container-aware, route parameters |
| `phpstan-doctrine` | Entity mapping, repository returns |
| `phpstan-phpunit` | Mock type inference, assert types |

Bleeding Edge Flags active: `checkUninitializedProperties`, `checkImplicitMixed`,
`checkBenevolentUnionTypes`, `reportPossiblyNonexistentGeneralArrayOffset`

## PHPUnit 13

- Path Coverage via Xdebug with `XDEBUG_MODE=coverage` (FrankenPHP = ZTS, PCOV = NTS only)
- Two suites: `unit` (tests/Unit), `integration` (tests/Integration)
- `#[CoversClass(...)]` on every test class
- `createStub()` instead of `createMock()` where no invocation verification is needed
- Data providers for edge cases

## Infection (Mutation Testing)

- Runs only against the unit suite
- MSI >= 80%, Covered MSI >= 90%
- `infection-fast` for changed files only (CI optimization)

## Rector

- PHP 8.4 + Symfony 8 + Doctrine + PHPUnit sets
- CI: `--dry-run` (blocks on diff), local: auto-fix

## ECS

- PSR-12 + common + strict + cleanCode sets

## CI Pipeline (Order)

```
1. ECS Check          — Coding standard (fastest check first)
2. PHPStan            — Static analysis + all 10 extensions
3. Rector --dry-run   — Outdated patterns?
4. PHPUnit Unit       — with path coverage
5. PHPUnit Integration — against PostgreSQL (service container in CI)
6. Infection           — Mutation testing against unit suite
```

Steps 1-3 run in parallel (no DB needed). Steps 4-6 sequentially.

## Architecture Tests (phpat)

Tests in `tests/Architecture/`, run via PHPStan (not PHPUnit):
- `LayerDependencyTest` — services must not depend on controllers
- `NamingConventionTest` — exceptions in Exception namespace

## Enforcement Matrix

| Rule | Tool | Threshold | CI blocks? |
|---|---|---|---|
| Cognitive Complexity / Method | `tomasvotruba/cognitive-complexity` | max 8 | Yes |
| Cognitive Complexity / Class | `tomasvotruba/cognitive-complexity` | max 50 | Yes |
| Type Coverage | `tomasvotruba/type-coverage` | 100% | Yes |
| `declare(strict_types=1)` | `tomasvotruba/type-coverage` | 100% | Yes |
| Layer Dependencies | `phpat/phpat` | Architecture test | Yes |
| Naming Conventions | `phpat/phpat` + `shipmonk` | Architecture test | Yes |
| Strict Comparisons | `phpstan-strict-rules` | strict | Yes |
| Deprecated Code | `phpstan-deprecation-rules` | 0 | Yes |
| Debug Functions | `shipmonk` (`forbidCustomFunctions`) | 0 | Yes |
| `DateTime` forbidden | `shipmonk` (`forbidCustomFunctions`) | 0 | Yes |
| PHPStan Level | `phpstan/phpstan` | max | Yes |
| Coding Standard | `symplify/easy-coding-standard` | PSR-12 + strict | Yes |
| Code Modernity | `rector/rector` | PHP 8.4 + Symfony 8 | Yes (dry-run) |
| Path Coverage | PHPUnit 13 + Xdebug (`XDEBUG_MODE=coverage`) | >= 80% | Yes |
| Mutation Score | `infection/infection` | MSI >= 80% | Yes |
| Covered Code MSI | `infection/infection` | >= 90% | Yes |
```

#### `.claude/architecture.md` — Architecture Conventions

```markdown
# Architecture

## Project Type

Full-stack mono-repo: `backend/` (PHP API) + `frontend/` (SvelteKit PWA).
Communication exclusively via REST API (`/api/v1/`).

## Docker

- **FrankenPHP** (Caddy + PHP 8.4) — one container for web + worker
- **PostgreSQL 17** — primary DB
- **PgBouncer** — Transaction Mode for web requests
- **Messenger Worker** — connects DIRECTLY to the DB, NOT via PgBouncer (LISTEN/NOTIFY)
- **Bun** — SvelteKit dev server (dev profile only)

## Conventions

- Everything UTC in the DB — except explicit local time fields (PostgreSQL `TIME`)
- Async: mails and push via Symfony Messenger, never in the HTTP request
- API: plain controller + Symfony Serializer, no API Platform
- Auth: JWT (access token 15min + refresh token 30d)
- i18n: paraglide-sveltekit (frontend) + Symfony Translator (backend), de + en

## Makefile Targets

`make help` shows all available targets. Most important:

- `make up` / `make down` — start/stop Docker
- `make quality` — all backend checks (ECS, PHPStan, Rector, Test, Infection)
- `make test` / `make test-unit` / `make test-integration` — tests
- `make db-migrate` / `make db-diff` / `make db-reset` — database

## ENV Variables

Documented in `.env.example`. Important:
- `DATABASE_URL` → PgBouncer (web requests)
- `MESSENGER_DATABASE_URL` → direct PostgreSQL (worker)
- Separate URLs because PgBouncer does not support LISTEN/NOTIFY
```

### Template Extension for SmartHabit

When the template is forked for SmartHabit, project-specific additions are made:

- `CLAUDE.md` gets a `## Project Context` section with SmartHabit-specific knowledge
- `.claude/coding-php.md` stays unchanged (generic)
- `.claude/architecture.md` gets SmartHabit entities, API endpoints, push architecture
- `.claude/testing.md` gets SmartHabit-specific phpat rules (DomainIsolationTest)
- New file `.claude/domain.md` with habits, notifications, households, GDPR, etc.
