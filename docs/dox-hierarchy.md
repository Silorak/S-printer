# DOX Hierarchy Adaptation for S-printer

This project now has an adapted DOX hierarchy based on `agent0ai/dox`. DOX is a lightweight `AGENTS.md` contract tree: agents read the root and nearest child docs before editing, then update the relevant docs after meaningful changes.

## Hierarchy

```text
S-printer-main/
├── AGENTS.md              # Root DOX rail: global rules, deployment/docs ownership
├── docs/
│   ├── dox-hierarchy.md   # This adaptation/ownership analysis
│   └── project-analysis.md
└── src/
    ├── AGENTS.md          # Runtime PHP/API/DB/SNMP/JS contracts
    ├── *.php
    ├── script.js
    └── css/
        ├── AGENTS.md      # CSS/theme/responsive contracts
        └── *.css
```

## Why this shape

The project has three natural durability boundaries:

1. **Root:** deployment, root docs, architecture narratives, Docker/Apache configuration, and repository-wide workflow.
2. **`src/`:** the complete runtime app served by Apache: PHP entrypoints/classes plus browser JavaScript.
3. **`src/css/`:** a cohesive styling subsystem already split by concern into base/layout/components/modals/responsive modules.

A deeper tree would add maintenance overhead right now. Add child `AGENTS.md` files later only when a folder develops its own durable responsibilities, workflows, or verification beyond its parent.

## Ownership matrix

| Path | Nearest owner | Notes |
|---|---|---|
| `AGENTS.md` | root `AGENTS.md` | Project-wide DOX policy and child index. |
| `README.md` | root `AGENTS.md` | Operational overview. Currently minimal. |
| `architecture.md` | root `AGENTS.md` | English thesis/technical architecture document. Keep synced with Lithuanian version when architecture changes. |
| `architektura.md` | root `AGENTS.md` | Lithuanian architecture document. Keep synced with English version when architecture changes. |
| `Dockerfile` | root `AGENTS.md` | PHP 8.2 Apache image, SNMP/PDO extensions, Apache modules, healthcheck. |
| `docker-compose.yml` | root `AGENTS.md` | Local web/db services and bind mount. |
| `apache-vhost.conf` | root `AGENTS.md` | Apache document root and security headers. |
| `jsconfig.json` | root `AGENTS.md` | IDE-only JS configuration. |
| `docs/*` | root `AGENTS.md` | Analysis and operational docs until docs become a larger subsystem. |
| `tools/verify-phase1.mjs` | root `AGENTS.md` | Static verification for Phase 1 security/correctness contracts. |
| `tools/verify-phase2.mjs` | root `AGENTS.md` | Static verification for Phase 2 renderer/DOM-safety contracts. |
| `tools/verify-phase3.mjs` | root `AGENTS.md` | Static verification for Phase 3 performance/cache/cancellation contracts. |
| `src/index.php` | `src/AGENTS.md` | Dashboard render, headers, modals, DOM contracts. |
| `src/api.php` | `src/AGENTS.md` | JSON API action router and response envelope. |
| `src/Database.php` | `src/AGENTS.md` | DB connection, schema/migrations, CRUD, settings, import/export helpers. |
| `src/PrinterDiscovery.php` | `src/AGENTS.md` | SNMP detection, vendor handling, consumable-level parsing. |
| `src/CacheManager.php` | `src/AGENTS.md` | File JSON cache. |
| `src/Formatting.php` | `src/AGENTS.md` | Shared PHP display-format and JSON escaping helpers. |
| `src/script.js` | `src/AGENTS.md` | Browser flows, API calls, refresh, filtering, modals. |
| `src/css/base.css` | `src/css/AGENTS.md` | Tokens, reset, themes, focus. |
| `src/css/layout.css` | `src/css/AGENTS.md` | Header, sidebar, grid, refresh/empty/error layout. |
| `src/css/components.css` | `src/css/AGENTS.md` | Cards, levels, buttons, shared components. |
| `src/css/modals.css` | `src/css/AGENTS.md` | Printer/settings modals, forms, floor manager. |
| `src/css/responsive.css` | `src/css/AGENTS.md` | Animations, media queries, mobile/narrow overrides. |

## Agent edit workflow

Before editing:

1. Read root `AGENTS.md`.
2. Identify target paths.
3. Read each child `AGENTS.md` on the path to the target.
4. Check existing docs/code contracts before changing behavior.

After editing:

1. Re-read the nearest owning DOX doc for changed paths.
2. Update that doc if purpose, ownership, APIs, schema, verification, deployment, or UI contracts changed.
3. Update parent child indexes if adding/removing/moving `AGENTS.md` files.
4. Run available verification.
5. Report verification and any unavailable checks.

## Verification matrix by change type

| Change type | Minimum checks | Additional checks |
|---|---|---|
| Root Docker/Apache | `docker compose config`; `docker compose build` | `docker compose up -d`; `curl -fsSI http://localhost:8080/`; inspect headers. |
| PHP syntax/API | `php -l` for touched PHP, or Docker PHP lint | curl smoke tests for affected endpoints; check JSON envelope. |
| DB schema | PHP lint; Docker startup | `SHOW CREATE TABLE`; import/export round trip; migration on existing DB. |
| SNMP/vendor logic | PHP lint | Real device tests for affected model; offline timeout behavior; fallback path. |
| JavaScript | `node --check src/script.js`; relevant `node tools/verify-phase*.mjs` | Browser console; add/edit/delete; refresh; import/export; search/floor filters. |
| CSS/UI | Browser visual check | Dark/light theme; 1024/768/480/320 widths; keyboard focus; modals. |
| Docs/DOX only | Read changed docs for internal links/paths | Confirm child indexes and ownership matrix still match tree. |

## Current project observations captured in DOX

- The app is a Dockerized PHP 8.2 + Apache + MariaDB 10.6 project.
- Runtime source is bind-mounted from `./src` to `/var/www/html` in local Compose.
- There is no Composer/npm build pipeline and no automated test suite observed.
- The target folder is not currently a Git repository.
- `architecture.md` / `architektura.md` request lifecycles and cache strategy sections describe the current DB-backed card shell plus async per-printer refresh flow.
- `api.php` and `index.php` now both read `SNMP_COMMUNITY` before constructing `PrinterDiscovery`; keep these paths aligned.
- Level rendering is JS-owned; PHP emits card shells plus level JSON data attributes.
- `refresh_printer` uses a short per-printer cache and unchanged-level DB write skip; mutations clear runtime caches.
- CSS load order is behaviorally important because later files intentionally override earlier rules.

## When to add future child AGENTS files

Add a child `AGENTS.md` only when the folder needs local rules not already practical in a parent, for example:

- a future `tests/` directory with test-data and execution contracts
- a future `docs/` subsystem with publishing/translation rules
- a future `src/vendor-printers/` module with per-manufacturer SNMP contracts
- a future `src/js/` split if browser code becomes modular

Do not add child docs for every folder automatically. DOX should stay operational and low-friction.
