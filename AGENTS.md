# DOX Framework for S-printer

DOX is the AGENTS.md hierarchy used in this repository. AGENTS.md files are binding work contracts for their subtrees. Agents must read the applicable DOX chain before editing and keep the chain current after meaningful changes.

## Core Contract

- AGENTS.md files define durable project rules, local contracts, verification expectations, and child documentation indexes.
- Work products, source materials, instructions, records, assets, and durable docs must stay understandable from the nearest applicable AGENTS.md plus every parent AGENTS.md above it.
- Do not rely on memory. Re-read the applicable DOX chain in the current session before editing.

## Read Before Editing

1. Read this root `AGENTS.md`.
2. Identify every file or folder you expect to touch.
3. Walk from the repository root to each target path.
4. Read every `AGENTS.md` found along each route.
5. If a parent `AGENTS.md` lists a child `AGENTS.md` whose scope contains the path, read that child and continue from there.
6. Use the nearest `AGENTS.md` as the local contract and parent docs for repo-wide rules.
7. If docs conflict, the closer doc controls local work details, but no child doc may weaken DOX.

## Pre-Build Checklist (before writing new code)

Reading the DOX chain tells you what exists. This checklist prevents creating verbose, duplicated code in the first place. Run it after reading DOX, before writing any new function, method, or module.

1. **Search for existing patterns.** Use `search_files` to find the pattern you're about to implement across the whole codebase (not just the target file). Duplication often lives across files.
2. **Count instances.**
   - **0:** New pattern. Proceed to step 3.
   - **1-2:** Emerging. Write it, but note: if this hits 3 instances, extract.
   - **3+:** **STOP. Extract the shared abstraction FIRST**, refactor existing instances to use it, verify nothing breaks, THEN add your code. Adding a 4th copy of a pattern is how codebases rot.
3. **Research the best practice.** For non-trivial behavior (security, DOM semantics, DB locking, CSS ordering), check the authoritative source: OWASP cheat sheets for security, MDN/W3C for DOM/CSS, php.net for PHP, MySQL/MariaDB KB for SQL. Record the source URL and key finding.
4. **Check abstraction level.** Too low (each method reimplements boilerplate individually) → extract a helper. Too high (one function handles 5 unrelated concerns) → split. Could a lookup table replace an if-else chain? Could an existing function do the job?

Skip this checklist only for: one-line fixes, renames, documentation-only changes, or genuine one-off operations.

## Update After Editing

Every meaningful change requires a DOX pass before the task is done.

Update the closest owning `AGENTS.md` when a change affects:

- purpose, scope, ownership, or responsibilities
- durable structure, contracts, workflows, or operating rules
- required inputs, outputs, permissions, constraints, side effects, or artifacts
- user preferences about behavior, communication, process, organization, or quality
- `AGENTS.md` creation, deletion, move, rename, or index contents

Update parent docs when parent-level structure, ownership, workflow, or child index changes. Update child docs when parent changes alter local rules. Remove stale or contradictory text immediately. Small edits that do not change behavior or contracts may leave docs unchanged, but the DOX pass still must happen.

## Hierarchy

- Root `AGENTS.md` owns project-wide instructions, global workflow rules, deployment/documentation contracts, and the top-level Child DOX Index.
- Child `AGENTS.md` files own domain-specific instructions and their own Child DOX Index.
- Each parent explains what its direct children cover and what stays owned by the parent.
- The closer a doc is to the work, the more specific and practical it must be.

## Child Doc Shape

Use this section order for child docs:

1. Purpose
2. Ownership
3. Local Contracts
4. Work Guidance
5. Verification
6. Child DOX Index

Create a child `AGENTS.md` when a folder becomes a durable boundary with its own purpose, rules, responsibilities, workflow, materials, or quality standards.

## Style

- Keep docs concise, current, and operational.
- Document stable contracts, not diary entries.
- Put broad rules in parent docs and concrete details in child docs.
- Prefer direct bullets with explicit names.
- Do not duplicate rules across many files unless each scope needs a local version.
- Delete stale notes instead of explaining history.
- Trim obvious statements, repeated rules, misplaced detail, and warnings for risks that no longer exist.

## Closeout

1. Re-check changed paths against the DOX chain.
2. Update nearest owning docs and any affected parents or children.
3. Refresh every affected Child DOX Index.
4. Remove stale or contradictory text.
5. Run existing verification when relevant.
6. Report any docs intentionally left unchanged and why.

## Project Purpose

S-printer/Sprinter is a Dockerized PHP 8.2 + Apache printer monitoring dashboard. It stores configured printers, floors, and UI settings in MariaDB, queries printer status and consumable levels with SNMPv2c, and renders a vanilla JavaScript/CSS dashboard at `http://localhost:8080/` in the default Docker Compose setup.

## Ownership

This root document owns:

- repository-wide DOX policy
- root deployment files: `Dockerfile`, `docker-compose.yml`, `apache-vhost.conf`
- root project docs: `README.md`, `architecture.md`, `architektura.md`, `docs/*`
- root configuration: `jsconfig.json`
- root verification helpers: `tools/*`
- license and project-level metadata

Runtime application code under `src/` is owned by `src/AGENTS.md`. CSS-specific contracts under `src/css/` are owned by `src/css/AGENTS.md`.

## Local Contracts

- This project intentionally uses a simple no-framework architecture: PHP files, vanilla browser JavaScript, modular CSS, Docker Compose, and MariaDB.
- `src/` is the Apache document root in development via the Compose bind mount `./src:/var/www/html`.
- Production image behavior differs because `Dockerfile` currently leaves `COPY src/ /var/www/html/` commented out; deployment changes must account for both bind-mounted development and copied-image production paths.
- Required PHP extensions are `snmp` and `pdo_mysql`, installed in `Dockerfile`.
- The Compose database service is `mariadb:10.6`; default database, username, and password are in `docker-compose.yml` and should be treated as development defaults, not production secrets.
- The target folder may not be a Git repository. Check before relying on git history, branches, or diffs.
- No Composer, npm, bundler, or automated test suite is currently present. `jsconfig.json` is for IDE understanding only and does not build or type-check runtime code by itself.
- Keep `architecture.md` and `architektura.md` synchronized when architecture-level documentation changes.
- Keep Apache security headers in `apache-vhost.conf` and PHP/app-level headers aligned when changing browser security policy.
- Do not commit runtime cache, database volumes, logs, generated backups, or local environment secrets.

## Work Guidance

- Prefer incremental changes that preserve the existing PHP/JS/CSS shape unless the task explicitly justifies new infrastructure.
- For deployment changes, verify Docker Compose configuration and consider container startup order, DB readiness, Apache module/header behavior, and PHP extension availability.
- For documentation changes, separate operational guidance (`README.md`, `docs/*`) from thesis-style architecture narrative (`architecture.md`, `architektura.md`).
- For app behavior changes, follow `src/AGENTS.md` and update any affected root docs if the lifecycle, dependencies, endpoints, schema, or deployment assumptions change.
- For CSS or visual behavior, follow `src/css/AGENTS.md` and verify both dark and light themes.

## Verification

Use the strongest available subset of these checks before closeout:

```sh
docker compose config
docker compose build
docker compose up -d
docker compose ps
curl -fsSI http://localhost:8080/
curl -fsS http://localhost:8080/api.php?action=get_settings
```

When PHP is unavailable on the host, run syntax checks inside the web container:

```sh
docker compose exec web sh -lc 'for f in /var/www/html/*.php; do php -l "$f"; done'
```

For JavaScript-only changes, at minimum run:

```sh
node --check src/script.js
```

For Phase 1 security/correctness contracts, run:

```sh
node tools/verify-phase1.mjs
```

For Phase 2 renderer/DOM-safety contracts, run:

```sh
node tools/verify-phase2.mjs
```

For Phase 3 performance/cache/cancellation contracts, run:

```sh
node tools/verify-phase3.mjs
```

For Phase 5 CSP-hardening contracts, run:

```sh
node tools/verify-phase5-csp.mjs
```

For Phase 6 accessibility contracts, run:

```sh
node tools/verify-phase6-a11y.mjs
```

For Phase 7 security hardening contracts, run:

```sh
node tools/verify-phase7-security.mjs
```

For Phase 8 brute-force protection contracts, run:

```sh
node tools/verify-phase8-bruteforce.mjs
```

For Phase 9 Pantum SNMP contracts, run:

```sh
node tools/verify-phase9-pantum.mjs
```

For a dynamic security scan (requires Docker running), run:

```sh
node tools/security-scanner.mjs
```

For Phase 10 simplification contracts, run:

```sh
node tools/verify-phase10-simplify.mjs
```

For DB/schema changes, inspect the live schema:

```sh
docker compose exec db mariadb -usprinter -psprinter_password sprinter -e 'SHOW TABLES; SHOW CREATE TABLE printers\G; SHOW CREATE TABLE floors\G; SELECT * FROM settings;'
```

If Docker or PHP is not installed in the local environment, report that blocker and run static validation that is available.

## User Preferences

When the user requests a durable project behavior or quality preference, record it here or in the nearest relevant child `AGENTS.md`.

## Child DOX Index

- `src/AGENTS.md` — runtime PHP, JSON API, database, SNMP, cache, server-rendered dashboard, and browser JavaScript contracts.
- `tools/AGENTS.md` — static verification scripts (`verify-phase*.mjs`) and the dynamic OWASP security scanner (`security-scanner.mjs`).
- `docs/AGENTS.md` — analysis documents (`project-analysis.md`, `research-backed-fixes.md`) tracking phase status and research-backed fix logs.
