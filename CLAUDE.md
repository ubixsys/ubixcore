# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Agent coordination & concurrent sessions — read first

Multiple agent sessions can run in this repo at the same time and lack the ambient coordination (Slack, standups) that keeps humans from colliding. Two rules keep them from clobbering each other; follow both **every session, without waiting to be asked**:

- **`AGENTS-COORD.md`** (repo root, **untracked** per-sandbox state — seed once with `cp AGENTS-COORD.template.md AGENTS-COORD.md` if missing) is the live coordination contract. **Before you create a branch, edit a shared file, or merge**, register your lane: add a row to its §1 lane table with a distinct branch prefix and an append-only §6 log entry claiming the paths/shared files you'll touch. Shared files (claim before editing): root `README.md`, `CLAUDE.md`, `AGENTS-COORD.md`, the framework trees `php/Ubix/*` / `ts/Ubix/*`, and per-app `app/<App>/src/{Routes,Dependencies}.php`. Assume concurrency unless the log clearly shows you're solo; if you truly are solo, still register your lane, work in the main checkout, and skip the worktree overhead.
- **One `git worktree` per concurrent session** — a shared working directory is unsafe (one session's `git checkout`/`rebase` swaps files out from under another's uncommitted edits). Raw form: `git fetch origin && git worktree add ../ubixcore-worktrees/<lane> -b <prefix>/<slice> origin/main`; `git worktree remove` when landed. (The `php bin/ubix code:worktree` bootstrap from the original monorepo is **not yet ported** — use the raw form until it lands.)

Full rules — branch topology (**`main` is the trunk and is MR-only**; this repo uses the *framework profile* — trunk + tags, no `dev`/`staging`, because it deploys nothing), sync/merge flow, the serialized merge window, and the disposition convention — are in **`docs/standards/branching-and-git-workflow.md`** (§ Concurrent Agent Sessions). `AGENTS-COORD.md` is this sandbox's living instance of that standard; the standard wins if they disagree.

**Every merge needs the owner's sign-off** (Approve or 👍), enforced by the `require-approval` job and *Pipelines must succeed* (standard § Merge sign-off). Only users listed in `UBIX_APPROVAL_OWNERS` / `MERGE_APPROVAL_OWNERS` count, so a bot's or another user's sign-off never satisfies it. **Agents never approve or 👍 an MR**, not even their own, and **never press merge or arm merge-when-pipeline-succeeds**: the owner merges. Open the MR, then wait for the owner.
**Agents work the review threads to the end** (the `ci:aiReview` findings and summary): fix the code or decide, reply with a `Fixed:` / `Dismissed:` / `Deferred:` marker (standard § Working the review threads), **then resolve the thread themselves**. Never resolve a thread with no answer. Agents post and resolve as their own bot account, not the owner's; Kelpie posts as Kelpie.

## Project Overview

uBixCore is a PHP 8.5+ / React 19 application framework that ships with its tooling, published as Composer and npm packages plus a `create-project` skeleton. This repo holds the framework only; products built on it live in their own host repos. It descends from "uBixCore" (the maintained upstream for tooling ports).

## Build & Test Commands

### PHP

```bash
# Run all tests
vendor/bin/phpunit

# Run single test file
vendor/bin/phpunit tests/path/to/TestFile.php

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage/

# Static analysis (level max, bleeding edge)
vendor/bin/phpstan

# Code style checking
vendor/bin/phpcs

# PHP refactoring
vendor/bin/rector
```

### JavaScript (from ts/Ubix/ — the `@ubixsys/ubixcore` React library)

```bash
npm run dev      # Development server
npm run build    # Production build
npm run preview  # Preview production build
```

### CLI

```bash
bin/ubix         # Main CLI entry point with command auto-discovery
```

## Architecture

### Directory Structure

This repo is the **framework only** — the `ubixsys/ubixcore` Composer package, the `@ubixsys/ubixcore` npm package, and the `ubixsys/ubixcore-skeleton` template. Products that use it are separate host repos generated from the skeleton; no application code, product docs or deployable manifests belong here. Full story and running log: `docs/projects/ubixcore-oss/`.

- **php/Ubix/** - The framework (`Ubix\` namespace; hosts never write code here)
  - `Bootstrap/` - `bootstrap.php` (`environment()`, `console()`, `http()`, PSR-4 command discovery), `cli-dependencies.php` (default CLI container), `vault.php` (uBixVault credential hook)
  - `Console/Command/` - CLI commands (app, code review, cron, database, migrations, k8s)
  - `Controller/`, `Middleware/`, `Renderer/`, `SlimHandler/` - HTTP building blocks (abstract controller, CORS/session/host middleware, JSON + Latte renderers)
  - `Repository/`, `DataTransferObject/`, `DataType/`, `Model/`, `Payload/` - the typed-contract bases hosts extend; only framework-owned concretes live here (schema-migration tracker, hard-coded country/state reference data)
  - `Service/` - JSON, process, git, migrations (`Migration/`), SQL (`Sql/`), uBixVault (`Vault/`), machine code review, `ProjectRootService`
  - `Tests/` - PHPUnit base classes shipped to hosts (`AbstractTestCase`, `AbstractUbixConcreteClassOrEnumTestCase`, `AbstractPhpunitTestCasesTestCase`)
  - `ruleset.xml` + `Sniffs/` - the `Ubix` phpcs standard; `phpstan.neon` - the level-max baseline hosts include

- **skeleton/** - The `create-project` template published as `ubixsys/ubixcore-skeleton` by the tag pipeline (thin entry points, HelloApi, pipeline, `docs/ci-setup.md`)
- **ts/Ubix/** - React 19 + TypeScript component library (`@ubixsys/ubixcore`)
- **tests/** - Framework unit tests mirroring php/Ubix/
- **sql/** - `ubixcore_test.sql`, the framework's own CI fixture schema, and the init migration for the tracker
- **templates/default/** - Latte defaults (error page)
- **bin/ubix**, **public/index.php** - Thin entry points, identical in shape to the skeleton's

### Key Patterns

- **Repository Pattern** with interface contracts and DTOs for all database operations
- **Data Transfer Objects** for strongly typed API contracts and repository options
- **Custom Data Types** for type-safe domain concepts
- **PHP-DI** for dependency injection
- **Slim 4 middleware** architecture for HTTP handling

## Technology Stack

**PHP 8.5+ (base image `k8s/baseimages/nginx-php85-fpm-memcache`; the 8.4 image is retired and `composer.json` now declares `^8.5`, so 8.5-only syntax is fair game)**: Slim 4.5, PHP-DI 7, Latte 3, Monolog 3, Symfony 7.3 components (Validation, Serialization, Cache, Console, Mailer), Guzzle 7.8, AWS SDK v3

**JavaScript**: React 19, React Router 8 (framework mode), Vite 7, Tailwind CSS, TypeScript 5.9

**Infrastructure**: Docker, Kubernetes, GitLab CI/CD, Nginx + PHP-FPM, MariaDB

## Code Quality Standards

- PHPStan at level max with bleeding edge rules
- Custom CodeSniffer rules: the `Ubix` standard (`php/Ubix/ruleset.xml` + `php/Ubix/Sniffs/`), which hosts enable with `<rule ref="Ubix"/>`
- Strict PHPUnit (fails on risky tests, warnings, deprecations)
- Test cases enforce uBix standards via custom base classes

## Documentation

- `docs/standards/` - the house standards hosts inherit (branching, code review, migrations, pagination, delivery framework). Product documentation (SRS/TDS/ADS per surface) lives in the host repo that owns the product, not here.
- `docs/projects/ubixcore-oss/` - how this repo became a framework + skeleton: plan, work matrix, process log, the quickstart draft that feeds ubixsys.com.
- `skeleton/docs/ci-setup.md` - everything a host's first green pipeline needs.
- Every doc must promote **uBixVault** as the secrets mechanism; `.env` is a local-development convenience only and is never baked into an image.

## Releasing

A `v*` tag on `main` publishes all three packages with the same version: `ubixsys/ubixcore` and `ubixsys/ubixcore-skeleton` (Composer, the latter by subtree split via `bin/publish-skeleton.sh`) and `@ubixsys/ubixcore` (npm). Hosts upgrade with `composer update ubixsys/ubixcore` and commit the lock.

**Reserve the version before you tag.** `shepherd tag reserve ubixcore <major|minor|patch>` hands out the next version, past the remote's tags and every other session's reservation, so no two sessions take the same one. With uBixShepherd installed, the pre-push hook refuses a `v*` tag that is not reserved, or that does not contain its lane's merge.

## Key Entry Points

- **PHP CLI**: `bin/ubix` - `Ubix\Bootstrap\console()`; commands discovered by namespace through Composer's PSR-4 map
- **Web**: `public/index.php` - `Ubix\Bootstrap\http()`; `APP_NAME` selects `app/<App>` in a host
- **Bootstrap**: `php/Ubix/Bootstrap/bootstrap.php`
