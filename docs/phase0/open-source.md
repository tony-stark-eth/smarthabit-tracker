# Open-Source Files — License, Contributing, README

The template is a **public GitHub repository** under the MIT license. It serves as a reference and starting point for the community.

---

## GitHub Repository Settings (set manually after creation)

These settings cannot be defined in files — they must be set via the GitHub UI after creating the repo.

### Repository Name

```
template-symfony-sveltekit
```

Descriptive, keyword-rich, with hyphens. GitHub SEO indexes the repo name as the most important signal. Alternatives: `symfony-sveltekit-starter`, `fullstack-php-svelte-template`.

### About / Description

```
Opinionated full-stack template: PHP 8.4 + Symfony 8 + SvelteKit 2 + FrankenPHP + Docker + 10 PHPStan extensions + Claude Code. Production-ready quality tooling from commit zero.
```

Displayed in: GitHub search, Google snippets, social previews, starred lists. Max ~350 characters.

### Topics (Tags)

Set after repo creation under Settings > About > Topics:

```
symfony, php, sveltekit, svelte, docker, template, frankenphp, phpstan,
rector, postgresql, tailwindcss, typescript, github-actions,
claude-code, opentofu, hetzner, boilerplate, starter-template,
repository-template, fullstack, pwa, mutation-testing
```

Topics are the strongest discoverability lever on GitHub — they appear in topic search (`github.com/topics/symfony`) and are used by GitHub's recommendation engine.

### Social Preview Image

A custom social preview image (1280x640px) must be uploaded under Settings > Social Preview. It appears when someone shares the repo link on Twitter, LinkedIn, Slack, Discord, etc.

Image content:
- Template name large: **Symfony + SvelteKit Template**
- Tech stack icons: PHP, Symfony, Svelte, Docker, PostgreSQL
- Tagline: "Production-ready quality tooling from commit zero"
- Badges visual: PHPStan max, MIT, 10 Extensions
- Dark background, clean design

Can be created with e.g. Figma, Canva, or the tool `socialify.git.ci`.

### GitHub Features to Enable

| Feature | Where | Why |
|---|---|---|
| **Template Repository** | Settings > General > Template repository | Enables "Use this template" button |
| **Discussions** | Settings > General > Features > Discussions | Q&A and feedback without polluting Issues |
| **Sponsorship** | Settings > Sponsor this project | Optional: FUNDING.yml links to GitHub Sponsors / Ko-fi |
| **Wiki** | Settings > General > Features > Wiki (off) | Disable — docs live in `.claude/` and `docs/`, no wiki sprawl |
| **Projects** | Settings > General > Features > Projects (off) | Disable — a template does not need a Kanban board |

### Community Standards Score

GitHub shows a score under Insights > Community. For 100% we need:

- [x] README.md — present
- [x] CODE_OF_CONDUCT.md — present
- [x] CONTRIBUTING.md — present (shown as a tab in the repo since Aug 2025)
- [x] LICENSE — MIT
- [x] SECURITY.md — present
- [x] Issue Templates — YAML forms
- [x] Pull Request Template — present
- [ ] Description — set manually (see above)

### Release / Tag

After the first green CI run: create a GitHub Release:

```bash
git tag -a v1.0.0 -m "Initial template release"
git push origin v1.0.0
```

Then on GitHub: Releases > Draft new release > Tag `v1.0.0` > Copy release notes from CHANGELOG.md > "Set as latest release".

Releases appear prominently on the repo page and in GitHub search. Without a release, a repo looks unfinished.

### Optional: Announcement

After the release, promote the template:
- **Reddit**: r/PHP, r/symfony, r/sveltejs, r/selfhosted
- **Dev.to / Hashnode**: Blog post "I built an opinionated Symfony + SvelteKit template with 10 PHPStan extensions"
- **X/Twitter**: with relevant hashtags (#PHP #Symfony #SvelteKit #OpenSource)
- **Symfony Slack** + **Svelte Discord**: in the appropriate channels

---

## Files

#### `LICENSE` — MIT

```
MIT License

Copyright (c) [year] [owner]

Permission is hereby granted, free of charge, to any person obtaining a copy...
```

Standard MIT — maximum freedom for template users. Commercial use and private forks (like SmartHabit) are allowed.

#### `CONTRIBUTING.md`

```markdown
# Contributing

Contributions are welcome! This template is meant to be opinionated but practical.

## How to contribute

1. Fork the repository
2. Create a feature branch (`git checkout -b feat/my-feature`)
3. Make sure all checks pass (`make quality`)
4. Commit with Conventional Commits (`feat:`, `fix:`, `docs:` etc.)
5. Open a Pull Request

## What we're looking for

- Bug fixes in Docker/CI configuration
- Improvements to PHPStan/Rector/ECS config
- Better defaults for the quality tooling
- Documentation improvements
- Additional Architecture Tests (phpat)

## What we're NOT looking for

- Domain-specific logic (this is a generic template)
- Alternative frameworks (no Laravel, no Next.js)
- Removing quality tools or lowering thresholds

## Development

```bash
docker compose --profile dev up -d
cd backend && composer install
cd frontend && bun install
make quality    # Must pass before opening a PR
```

## Code Style

This project enforces its own coding standards automatically. Run `make quality`
and fix everything it reports. Don't add `@phpstan-ignore` without a comment explaining why.
```

#### `CODE_OF_CONDUCT.md`

Contributor Covenant v2.1 — standard for open-source projects. Full text from https://www.contributor-covenant.org/version/2/1/code_of_conduct/

#### `SECURITY.md`

```markdown
# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this template, please report it responsibly.

**Do NOT open a public GitHub issue.**

Instead, use GitHub's private vulnerability reporting:
Repository > Security > Advisories > "Report a vulnerability"

Or email: [maintainer email]

We will acknowledge receipt within 48 hours and provide a timeline for a fix.

## Scope

This is a project template — security issues in the template configuration
(Docker, CI, default credentials) are in scope. Issues in upstream dependencies
(Symfony, PostgreSQL, etc.) should be reported to those projects directly.
```

#### `CHANGELOG.md`

Format: [Keep a Changelog](https://keepachangelog.com/). Starts with:

```markdown
# Changelog

All notable changes to this template will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - YYYY-MM-DD

### Added
- Initial template release
- PHP 8.4 + Symfony 8 backend with FrankenPHP
- SvelteKit 2 + Svelte 5 frontend with Bun
- Docker Compose setup (FrankenPHP, PostgreSQL 17, PgBouncer)
- 10 PHPStan extensions pre-configured
- GitHub Actions CI (backend + frontend)
- Claude Code integration (.claude/ directory)
- Makefile with all common targets
```

#### `.github/ISSUE_TEMPLATE/bug_report.yml`

Structured form with required fields:

```yaml
name: Bug Report
description: Something isn't working as expected
labels: ["bug"]
body:
  - type: textarea
    id: description
    attributes:
      label: What happened?
      description: Clear description of the bug
    validations:
      required: true
  - type: textarea
    id: reproduce
    attributes:
      label: Steps to reproduce
      description: Minimal steps to reproduce the behavior
      value: |
        1. ...
        2. ...
    validations:
      required: true
  - type: textarea
    id: expected
    attributes:
      label: Expected behavior
    validations:
      required: true
  - type: dropdown
    id: area
    attributes:
      label: Affected area
      options:
        - Docker / Compose
        - Backend (PHP / Symfony)
        - Frontend (SvelteKit)
        - CI / GitHub Actions
        - Quality Tooling (PHPStan, Rector, ECS, Infection)
        - Documentation
    validations:
      required: true
  - type: textarea
    id: environment
    attributes:
      label: Environment
      description: OS, Docker version, PHP version etc.
```

#### `.github/ISSUE_TEMPLATE/feature_request.yml`

```yaml
name: Feature Request
description: Suggest an improvement to the template
labels: ["enhancement"]
body:
  - type: textarea
    id: problem
    attributes:
      label: What problem does this solve?
    validations:
      required: true
  - type: textarea
    id: solution
    attributes:
      label: Proposed solution
    validations:
      required: true
  - type: dropdown
    id: area
    attributes:
      label: Affected area
      options:
        - Docker / Compose
        - Backend (PHP / Symfony)
        - Frontend (SvelteKit)
        - CI / GitHub Actions
        - Quality Tooling
        - Documentation
        - Claude Code Integration
```

## README.md

```markdown
# Symfony + SvelteKit Template

[![Backend CI](https://github.com/[owner]/template-symfony-sveltekit/actions/workflows/ci.yml/badge.svg)](https://github.com/[owner]/template-symfony-sveltekit/actions/workflows/ci.yml)
[![Frontend CI](https://github.com/[owner]/template-symfony-sveltekit/actions/workflows/ci-frontend.yml/badge.svg)](https://github.com/[owner]/template-symfony-sveltekit/actions/workflows/ci-frontend.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHPStan Level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)

Opinionated full-stack template for PHP 8.4 + Symfony 8 + SvelteKit projects.
Production-ready quality tooling from commit zero.

## What's included

**Backend** — PHP 8.4, Symfony 8, Doctrine ORM, FrankenPHP (Worker Mode)
**Frontend** — SvelteKit 2, Svelte 5, Bun, TypeScript strict, Tailwind 4
**Database** — PostgreSQL 17, PgBouncer (Transaction Mode)
**Quality** — 10 PHPStan extensions, Rector, ECS, PHPUnit 13, Infection
**CI/CD** — GitHub Actions (backend + frontend), Dependabot auto-merge
**AI** — Claude Code integration via CLAUDE.md + .claude/ guidelines

## Quick Start

> **Prerequisites**: Docker, Bun, Make

\```bash
# 1. Use this template (green button above) or clone
gh repo create my-project --template [owner]/template-symfony-sveltekit --private

# 2. Start containers
docker compose --profile dev up -d

# 3. Install dependencies
cd backend && composer install && cd ..
cd frontend && bun install && cd ..

# 4. Run all quality checks
make quality
\```

## After forking — Checklist

- [ ] Update `composer.json` name + description
- [ ] Update `package.json` name
- [ ] Generate JWT keys: `php bin/console lexik:jwt:generate-keypair`
- [ ] Copy `.env.example` → `.env.local` with real secrets
- [ ] Add `ANTHROPIC_API_KEY` as GitHub repository secret (for Claude workflows)
- [ ] Create domain folders under `backend/src/` (e.g. `src/User/`, `src/Product/`)
- [ ] Add project-specific phpat rules in `tests/Architecture/`
- [ ] Update `CLAUDE.md` with project context
- [ ] Remove this checklist from README

## Quality Stack

| Tool | What it enforces |
|---|---|
| PHPStan Level max | Type safety, dead code, logic errors |
| phpstan-strict-rules | `===`, no `empty()`, strict `in_array()` |
| phpstan-deprecation-rules | No deprecated API usage |
| shipmonk/phpstan-rules | ~40 rules: enum safety, forgotten exceptions, custom bans |
| voku/phpstan-rules | Operator type safety, assignment-in-condition |
| tomasvotruba/cognitive-complexity | Max 8/method, max 50/class |
| tomasvotruba/type-coverage | 100% type declarations |
| phpat/phpat | Architecture tests (layer dependencies, naming) |
| phpstan-symfony | Container-aware analysis, route parameters |
| phpstan-doctrine | Entity mapping, repository return types |
| phpstan-phpunit | Mock type inference, assertion analysis |
| Infection | Mutation testing: MSI ≥80%, Covered MSI ≥90% |

## Make Targets

Run `make help` for all available targets. Most common:

| Target | Description |
|---|---|
| `make up` | Start all Docker services |
| `make quality` | Run all backend quality checks |
| `make test` | Run PHPUnit (all suites) |
| `make phpstan` | Static analysis |
| `make infection` | Mutation testing |
| `make frontend-dev` | SvelteKit dev server |

## CI / GitHub Actions

**Backend** (`ci.yml`): ECS → PHPStan → Rector → PHPUnit → Infection
**Frontend** (`ci-frontend.yml`): ESLint → Svelte Check → Build
**Claude Update** (`claude-update.yml`): Biweekly dependency updates via Claude Code
**Claude Review** (`claude-review.yml`): Automatic code review on every PR

Default: GitHub-hosted runners. For self-hosted: set repository variable
`RUNNER_LABEL` to your runner label.

## Claude Code

This template includes full Claude Code integration:

- `.claude/` directory with coding guidelines (read automatically)
- `CLAUDE.md` as entry point for Claude Code
- `@claude` mentions in issues and PRs (`claude.yml`)
- Automatic code review on every PR (`claude-review.yml`)
- Biweekly dependency updates with breaking-change resolution (`claude-update.yml`)

**Required secret**: Add `ANTHROPIC_API_KEY` to your repository secrets.
Without it the Claude workflows will fail silently.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). TL;DR: fork, branch, `make quality`, PR.

## License

[MIT](LICENSE)
\```

---
