# Phase 0 — GitHub Repository Template

## Goal

A **public** GitHub Template Repository (MIT License) for full-stack projects with PHP 8.4 + Symfony 8 backend and SvelteKit frontend. Contains the complete quality stack, Docker setup, CI/CD, Claude Code integration and coding guidelines. No domain-specific logic — only infrastructure and tooling.

The template is open source and maintained as a reference setup for the community. Private projects (like SmartHabit) are created via `Use this template` → private repo.

## Specifications

The detailed specification is split into thematic files. Each file is self-contained and includes all the information Claude Code needs to build the respective part.

| File | Content | Lines |
|---|---|---|
| [`phase0/docker.md`](phase0/docker.md) | Dockerfile (Multi-Stage), compose.yaml, compose.override.yaml, compose.prod.yaml, init.sql | ~160 |
| [`phase0/backend.md`](phase0/backend.md) | composer.json, phpstan.neon, rector.php, ecs.php, infection.json5, phpunit.xml.dist, HealthController, Architecture Tests | ~143 |
| [`phase0/frontend.md`](phase0/frontend.md) | package.json, tsconfig.json, API Client, Landing Page, PWA Skeleton | ~66 |
| [`phase0/infrastructure.md`](phase0/infrastructure.md) | OpenTofu: versions.tf, main.tf, variables.tf, 4 modules (server, network, volume, dns), cloud-init.yml, .gitignore | ~352 |
| [`phase0/ci.md`](phase0/ci.md) | GitHub Actions: Backend CI (3 parallel jobs + tests), Frontend CI, Claude Update/Review/@mention, Dependabot + Auto-Merge | ~440 |
| [`phase0/dx.md`](phase0/dx.md) | Makefile, CaptainHook (Git Hooks), .editorconfig, .gitattributes, .gitignore, .env.example | ~334 |
| [`phase0/claude-spec.md`](phase0/claude-spec.md) | CLAUDE.md template (~30 lines), .claude/ directory (4 guidelines: coding-php, coding-frontend, testing, architecture) | ~294 |
| [`phase0/open-source.md`](phase0/open-source.md) | LICENSE (MIT), CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md, CHANGELOG.md, Issue Templates, README.md, **GitHub Settings & Discoverability** | ~410 |

## Repository Structure

```
template-symfony-sveltekit/
├── .claude/                          # Claude Code Guidelines (4 files)
├── .github/
│   ├── workflows/                    # 6 Workflows (CI, Frontend CI, Claude ×3, Dependabot)
│   ├── ISSUE_TEMPLATE/               # Bug Report + Feature Request (YAML)
│   ├── dependabot.yml
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── CODEOWNERS
├── docker/                           # FrankenPHP Dockerfile, PostgreSQL init, PgBouncer config
├── backend/                          # Symfony 8: src/, tests/, config files, captainhook.json
├── frontend/                         # SvelteKit 2 + Svelte 5 + Bun + Tailwind 4
├── infrastructure/                   # OpenTofu Skeleton (Hetzner + Cloudflare)
├── compose.yaml + override + prod    # Docker Compose (dev + prod)
├── Makefile                          # All shortcuts
├── .dockerignore                     # Protects Docker build context
├── .editorconfig                     # Cross-IDE formatting
├── .gitattributes                    # LF, diff=php, lockfile -diff
├── .gitignore                        # Complete
├── .env.example                      # All ENV vars documented
├── CLAUDE.md                         # Entry point for Claude Code
├── README.md + CONTRIBUTING.md       # Open-source docs
├── CODE_OF_CONDUCT.md + SECURITY.md  # Community standards
├── CHANGELOG.md                      # Keep a Changelog format
└── LICENSE                           # MIT
```

## Template Usage for SmartHabit

The template is **public** (MIT, open source). SmartHabit will be a **private repo**.

```bash
gh repo create smarthabit-tracker --template [owner]/template-symfony-sveltekit --private
```

After creation:

1. `.env.example` → `.env.local` with real values
2. Set `ANTHROPIC_API_KEY` as GitHub Repository Secret
3. Create domain folders: `src/Habit/`, `src/Notification/`, `src/Auth/`, `src/Household/`, `src/Stats/`
4. Add phpat `DomainIsolationTest` (SmartHabit-specific)
5. Frontend: Adjust design tokens for Neo Utility
6. Extend `CLAUDE.md` with SmartHabit context
7. Extend `.claude/architecture.md` with entities, API endpoints, push architecture
8. New file `.claude/domain.md` for SmartHabit-specific domain knowledge
9. Remove: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, Issue Templates (not needed for private repo)
10. Activate `claude-update.yml` only after Phase 1b (too little code before that for meaningful updates)

## Note: Single Source of Truth

The specs in `phase0/` and `docs/` overlap intentionally (e.g. PHPStan config in `phase0/backend.md`, `docs/testing.md` and `phase0/claude-spec.md`). **After Phase 0 the code is the truth**, not the docs. The files `phpstan.neon`, `rector.php`, `ecs.php` etc. in the repo are the source of truth — the Markdown docs are the plan that led there. Reconcile the `docs/` files once against the actual code after Phase 0; after that the config lives only in code.

## Definition of Done

The template is complete when:

- [ ] `docker compose --profile dev up -d` starts all services without errors
- [ ] PHP container healthcheck (`/api/v1/health`) is green
- [ ] `make quality` runs completely (ECS, PHPStan, Rector, PHPUnit, Infection)
- [ ] HealthController test (integration) is green against real PostgreSQL
- [ ] Architecture tests (phpat) run in PHPStan
- [ ] GitHub Actions CI (Backend + Frontend) is green on a push
- [ ] Backend CI: ECS, PHPStan, Rector run as **parallel jobs**
- [ ] Frontend: `bun run check` + `bun run build` are green
- [ ] Git hooks installed manually via `make hooks` (CaptainHook plugin-composer disabled in Docker)
- [ ] Template is marked as GitHub Template Repository
- [ ] Repo is **public** with **MIT License**
- [ ] README with badges, Quick Start, After-Forking Checklist
- [ ] CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md present
- [ ] Issue Templates as YAML forms
- [ ] `.editorconfig`, `.gitattributes`, `.gitignore` complete
- [ ] CLAUDE.md lean (~30 lines), `.claude/` with 4 guidelines
- [ ] Claude workflows configured (update, review, @mention)
- [ ] All quality config files enforce the documented rules
- [ ] `.env.example` contains no real secrets
- [ ] **GitHub Settings**: Topics set, description filled in, template flag active, Discussions enabled
- [ ] **Social Preview Image** (1280x640px) uploaded
- [ ] **v1.0.0 Release** created with release notes
