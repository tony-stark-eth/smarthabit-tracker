# SmartHabit Tracker — Progress Log

## Session 1 — 2026-03-21

### What happened
- Read all project documentation: CLAUDE.md, 8 Phase 0 sub-specs, architecture, phases, coding guidelines, testing, frontend docs
- Analyzed the entire 9-phase plan
- Created persistent planning files: `task_plan.md`, `findings.md`, `progress.md`
- Identified parallelization opportunities for Phase 0 (4 waves)

### Key findings
- Project is pure greenfield (only docs exist)
- Phase 0 specs are copy-paste ready — very detailed
- 4 of 8 Phase 0 groups are fully independent (Infrastructure, DX, Claude, Open Source)
- Docker must precede Backend testing, but Frontend is independent

### Parallelization strategy
- **Wave 1**: Infrastructure + DX + Claude Spec + Open Source (all independent)
- **Wave 2**: Docker + Backend + Frontend (Docker first, then B+F parallel)
- **Wave 3**: CI Workflows (needs B+F structure)
- **Wave 4**: Integration verification

### Status
- [x] Planning complete
- [x] Execution complete

### Next steps
- Phase 1a: `gh repo create tony-stark-eth/smarthabit-tracker --template tony-stark-eth/template-symfony-sveltekit --private`

---

## Session 2 — 2026-03-21

### What happened
- Executed Phase 0 end-to-end: built template repo from scratch
- Fixed 17 issues discovered during build (documented in findings.md)
- Key deviations from spec: edoburu/pgbouncer (not bitnami), no runtime/frankenphp-symfony, PHP configs only, explicit PHPStan includes, Rector set versions (no SYMFONY_80/PHPUNIT_130 yet)
- All backend quality checks green: ECS, PHPStan (level max + 10 ext), Rector, PHPUnit 4/4
- All frontend checks green: Svelte Check, ESLint, Build
- E2E 3/3: Playwright verifies frontend renders, API health, DB connectivity
- Added docker-entrypoint.sh for auto-install (dunglas pattern)
- Internal Caddy HTTP listener on :8080 for Vite proxy (avoids TLS cert mismatch)
- Published: https://github.com/tony-stark-eth/template-symfony-sveltekit (v1.0.0)
- GitHub: template flag ✅, topics set ✅, release created ✅

### User instructions received (apply to all future phases)
- All configs in PHP only — no YAML (use swiss-knife if converting)
- Always update docs/planning files when fixing things during execution

---

## Session 3 — 2026-03-22

### What happened
- Fixed backend CI pipeline (14 fix commits, squashed into one)
- Rewrote CI to run inside Docker Compose (same environment as local dev)
- Added BuildKit + Composer caching (52s total CI time with warm cache)
- Resolved: CaptainHook plugin, PCOV/ZTS incompatibility, PHPUnit 13 schema, PgBouncer+dbname_suffix, Infection no-source
- Squashed all CI fixes into one commit, force-pushed, retagged v1.0.0
- Updated findings.md, task_plan.md, docs/phase0/ci.md

### Key discoveries (apply to all future phases)
- **FrankenPHP = ZTS PHP** → PCOV will never work, always use XDEBUG_MODE=coverage
- **captainhook/plugin-composer** is a Composer PLUGIN, not a script → --no-scripts is useless, use allow-plugins: false
- **PgBouncer only proxies named DBs** → tests must bypass it (force DATABASE_URL to database:5432)
- **PHPUnit 13**: <include>/<exclude> under <source>, not <coverage>

### Status
- [x] CI pipeline fully green
- [x] v1.0.0 retagged with squashed commit

### Next steps
- Phase 1a: fork template → private smarthabit-tracker repo

---

## Session 4 — 2026-03-22

### What happened
- Translated all docs from German to English (architecture, testing, coding-guidelines, phases, phase0-template, security, frontend, notifications, infrastructure, statistics, native, FULL_PLAN, phase0/*.md)
- Fixed all stale references (PCOV→Xdebug, bitnami→edoburu, port 6432→5432, image tags, runtime/frankenphp-symfony)
- Compared with dunglas/symfony-docker — adopted: DB readiness check, auto-migrations, setuid removal, tty:true
- Final review: fixed German in .env.example, aligned backend/.env with .env.example, created config/jwt/.gitkeep
- GitHub settings: enabled Discussions, disabled Wiki + Projects
- Squashed into clean 2-commit history, retagged v1.0.0, CI green
- Drafted announcements for Reddit/X/Dev.to/Slack/Discord

### Status
- [x] All docs translated to English
- [x] All stale references fixed
- [x] Docker hardening (Phase 0.10) complete
- [x] Final review clean
- [x] GitHub settings correct
- [x] Announcements drafted

### Next steps
- Phase 1a: fork template into this directory, scaffold domain, build auth
