# S-printer Project Analysis

This analysis summarizes the current S-printer codebase and the DOX adaptation decisions. It is based on static inspection of the project files plus available local validation.

## Executive summary

S-printer is a compact, no-framework printer monitoring web app:

- **Backend/runtime:** PHP 8.2 on Apache.
- **Database:** MariaDB 10.6 through PDO.
- **Printer protocol:** SNMPv2c through PHP SNMP functions.
- **Frontend:** server-rendered PHP HTML plus vanilla JavaScript.
- **Authentication:** local first-admin registration, PHP sessions, CSRF token checks, and per-user permissions.
- **Styling:** five CSS modules loaded directly by `index.php`.
- **Deployment:** Docker Compose with `web` and `db` services.

The codebase is small enough for a three-file DOX tree:

- `AGENTS.md`
- `src/AGENTS.md`
- `src/css/AGENTS.md`

The highest-value maintenance contracts are first-admin/login flow, API response shape, CSRF/session handling, DB schema/idempotent migrations, permission mapping, SNMP level semantics, JS-owned level rendering, DOM/CSS class contracts, and Docker runtime assumptions.

## File inventory

| File | Lines | Role |
|---|---:|---|
| `README.md` | 2 | Minimal project title/description. |
| `Dockerfile` | 35 | Builds `php:8.2-apache`, installs SNMP/PDO MySQL, enables Apache modules, defines healthcheck. |
| `docker-compose.yml` | 34 | Local web/db stack, port `8080:80`, bind mounts `./src`, configures DB/SNMP env vars. |
| `apache-vhost.conf` | 20 | Apache document root, directory rules, security headers. |
| `jsconfig.json` | 99 | IDE/IntelliSense configuration for `src/**/*.js`; no runtime effect. |
| `architecture.md` | 1053 | English technical architecture/thesis documentation. |
| `architektura.md` | 1061 | Lithuanian architecture/thesis documentation. |
| `src/index.php` | 592 | Dashboard/auth entrypoint, first-admin/login render, initial card shell render, security headers, modals, bootstrap data. |
| `src/api.php` | 687 | Authenticated JSON API action router with centralized validation, permissions, CSRF, and short refresh caching. |
| `src/Database.php` | 726 | PDO DB connection, schema creation/migration, user/printer/floor/settings CRUD, import/export helpers, unchanged-level comparison. |
| `src/PrinterDiscovery.php` | 645 | SNMP printer detection and consumable parsing. |
| `src/CacheManager.php` | 138 | Simple JSON file cache. |
| `src/Auth.php` | 390 | Session, first-admin registration, login/logout, password hashing, CSRF, and permission helpers. |
| `src/Formatting.php` | 72 | Shared PHP display-format and JSON-escaping helpers. |
| `src/script.js` | 1543 | Browser initialization, CSRF-aware API fetch wrapper, user-management UI, DOM-safe/reused level rendering, abortable refresh, modals, search/filtering, import/export/settings. |
| `src/css/base.css` | 173 | Reset, design tokens, themes, focus styles. |
| `src/css/layout.css` | 310 | Header, main layout, sidebar, grid, refresh/empty/error states. |
| `src/css/components.css` | 509 | Printer cards, status indicators, level bars, buttons, FAB. |
| `src/css/modals.css` | 746 | Auth, modal/form/settings/user-management/floor-manager/toggle styles. |
| `src/css/responsive.css` | 665 | Animations, responsive behavior, late component overrides. |

## Architecture overview

```mermaid
graph TD
    Browser[Browser] -->|GET /| Index[src/index.php]
    Browser -->|fetch api.php?action=...| API[src/api.php]

    Index --> DB[(MariaDB via Database.php)]
    API --> DB
    API --> Discovery[PrinterDiscovery.php]
    Index --> Cache[CacheManager.php]

    Discovery -->|SNMPv2c UDP/161| Printers[Network printers]

    DB --- Tables[users / printers / floors / settings]
```

### Runtime lifecycle in current code

1. Browser requests `/`.
2. `index.php` loads config, starts the hardened session through `Auth.php`, applies security headers, instantiates `Database`, `CacheManager`, and `PrinterDiscovery`.
3. `Database` connects and runs `initSchema()` idempotently, including the persistent `users` table.
4. If `!$db->hasUsers()`, `index.php` renders first-admin registration; otherwise unauthenticated sessions render login.
5. After successful registration/login, `Auth.php` regenerates the session ID, stores the active user ID, and issues a fresh CSRF token.
6. Authenticated dashboard render fetches printers, floors, settings, and main-admin user-management data from DB.
7. Initial printer card shells render in `loading` state with last-known levels encoded in `data-initial-*` attributes.
8. `index.php` bootstraps settings, floors, `csrfToken`, `currentUser`, and `currentPermissions` into `window.__SPRINTER_BOOTSTRAP__`.
9. `script.js` hydrates initial level sections with its DOM renderer and calls `refreshAllPrinters()`.
10. `apiFetch()` sends the session CSRF token to `api.php`; `api.php` requires an authenticated active user, validates CSRF, and enforces per-action permissions.
11. `refreshAllPrinters()` calls `api.php?action=refresh_printer&ip=...` for each card with concurrency limit `4`.
12. `api.php` checks the short per-printer refresh cache; on miss it looks up the printer by IP, queries SNMP through `PrinterDiscovery`, stores fresh levels only when changed, caches the response briefly, and returns JSON.
13. `script.js` updates each card's status, levels, name, serial, and edit data while canceling stale refreshes and prioritizing active-floor cards.

### Important documentation mismatch

`architecture.md` / `architektura.md` request-lifecycle, client-side XSS, and cache-strategy sections now reflect async per-printer refresh, JS-owned DOM rendering, and the short per-printer refresh cache.

## Backend analysis

### `Database.php`

Responsibilities:

- Reads DB connection settings from environment (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
- Opens PDO MySQL connection with exceptions, associative fetch mode, and native prepares.
- Initializes tables:
  - `floors`
  - `users`
  - `settings`
  - `printers`
- Provides CRUD for users, printers, and floors.
- Stores last-known printer levels as JSON.
- Provides import/export support and settings accessors.

Durable contracts:

- `users.password_hash` stores PHP `password_hash()` output only.
- `users.is_admin = 1` marks the persistent main admin that always has every permission.
- Basic users are view-only until the main admin toggles `can_add_printer`, `can_edit_printer`, `can_delete_printer`, `can_manage_floors`, `can_manage_settings`, or `can_import_export`.
- `printers.ip` is unique.
- `printers.floor_id` is nullable and should point to `floors.id`.
- `settings` is key/value and only selected settings are allowed through the API.
- Schema initialization is in app startup, not external migrations.
- `Database::updatePrinterLevelsIfChanged()` canonicalizes stored/new JSON and skips no-op `last_levels` writes.

Risks:

- Existing installs missing `custom_oids` may not be migrated; `last_levels` and `floor_id` have migration checks, but `custom_oids` does not.
- `floor_id` migration adds the column but does not add the foreign key on existing schemas.
- Import batching commits every 50 printers; if a later batch fails, earlier batches are already committed despite comments implying one transaction.
- Main-admin account recovery still depends on database access if its password is lost.
- No explicit upper bounds on import size.

### `api.php`

API actions:

- `check_ip`
- `add_printer`
- `edit_printer`
- `delete_printer`
- `export_printers`
- `import_printers`
- `refresh_printer`
- `get_floors`
- `add_floor`
- `edit_floor`
- `delete_floor`
- `get_settings`
- `save_settings`
- `get_users`
- `create_user`
- `update_user_permissions`

Strengths:

- Central response helpers maintain a consistent JSON envelope.
- POST requests enforce `Content-Type: application/json`.
- Mutating actions check request method.
- All API actions require an active authenticated session and CSRF token.
- Mutating/privileged actions enforce explicit permission checks in `api.php`.
- Detailed errors are usually logged server-side while clients receive generic messages.
- Import supports old and new backup formats.
- `refresh_printer` uses a short per-printer cache to reduce repeated SNMP probes.

Risks:

- `check_ip` is now permissioned, but any user granted `can_add_printer` can still trigger SNMP probes from the server network; rate limiting and subnet allowlisting remain future hardening items.
- `add_printer` can be called directly by users with `can_add_printer` using a syntactically valid IP without a preceding successful SNMP detection.
- Phase 1 added centralized IP/payload/settings validation and HTTP/HTTPS `purchase_url` validation in `api.php`; future write paths should use those helpers.
- Authorization now exists, but every new API action must explicitly choose a permission before merge.
- Phase 1 aligned `api.php` with `index.php` by passing `SNMP_COMMUNITY` to `PrinterDiscovery`.
- Refresh cache is intentionally short; real device/browser validation is still required to tune TTL for a production network.

### `PrinterDiscovery.php`

Responsibilities:

- Validates and probes a specific printer IP.
- Reads system info and printer metadata OIDs.
- Detects vendor from sysDescr.
- Reads consumable levels using direct vendor mappings first, then Printer-MIB fallback.
- Handles Brother proprietary consumable data.
- Sanitizes SNMP values, including hex strings.

Durable contracts:

- sysDescr probe OID: `.1.3.6.1.2.1.1.1.0`
- Printer-MIB supply table: `.1.3.6.1.2.1.43.11.1.1`
- Special level semantics:
  - `-3` => OK/above threshold, rendered as 100 with `status: ok`
  - `-2` => unknown, skipped
  - `<= 0` => `status: empty_or_not_genuine`
- Supported vendor keywords include HP, Canon, Epson, Brother, Lexmark, Xerox, Kyocera, Ricoh, Konica, Samsung, Toshiba, Pantum, and Generic fallback.

Risks:

- Direct vendor OID logic can return partial data and skip a fuller MIB walk.
- Epson light cyan/light magenta OIDs are read but the direct output loop only includes `black`, `cyan`, `magenta`, `yellow`.
- SNMP timeout/retry values are optimized for local networks and may not fit high-latency networks.
- Real-printer validation is required for model-specific mappings.

### `CacheManager.php`

Responsibilities:

- Stores JSON cache files under a configured directory.
- Uses `md5($key)` to create safe cache file names.
- Expires files by modification time and configured TTL.

Risks / notes:

- Current async refresh flow mostly bypasses the cache documented in `architecture.md`.
- Mutations invalidate `printers_data`; update this if cache keys or data flows change.
- In bind-mounted development mode, cache files are created under `src/cache` if used.

## Frontend analysis

### `index.php`

Responsibilities:

- Sends app-level security headers.
- Starts the auth/session flow and renders first-admin registration, login, or authenticated dashboard.
- Loads printers, floors, settings, and main-admin user-management data from DB.
- Renders initial dashboard, floor sidebar, printer card shells, add/edit printer modal, permission-gated settings/user-management modal, bootstrap data, and CSS/JS includes.
- Uses `Formatting.php` for display-only serial normalization and safe JSON emission.

Strengths:

- Most server-rendered fields use `htmlspecialchars`.
- Initial page does not block on SNMP for every printer.
- Handles DB connection startup failure with a loading overlay and auto-refresh.

Risks:

- Inline `onclick` handlers require global JS functions and force a CSP that allows inline script.
- The bootstrap inline script and inline event handlers still affect CSP tightening.

### `script.js`

Responsibilities:

- Bootstraps settings, floors, CSRF token, current user, and permissions from `window.__SPRINTER_BOOTSTRAP__` with limited API fallback.
- Sends all API calls through `apiFetch()` with `X-CSRF-Token`.
- Gates UI controls with `hasPermission()` and wires main-admin user-management forms.
- Theme toggle with localStorage.
- Search and floor filtering.
- Add/edit/delete printer modal flows.
- Check IP flow.
- Import/export backup flow.
- Settings/floor/user management.
- Display settings application.
- DOM-safe level rendering with keyed bar reuse and card refresh.
- Refresh-all concurrency control.
- Abortable stale-refresh cancellation.

Important contracts:

- Uses API response envelope from `api.php`.
- Depends on DOM IDs/classes emitted by `index.php`.
- `openEditModal()` and `deletePrinter()` are global due inline handlers.
- Uses `throttlePromises(tasks, 4)` for refresh concurrency.
- Uses `AbortController` and `WeakMap` card tokens so stale refresh cleanup does not corrupt newer card state.
- `sortCubesForRefresh()` prioritizes active-floor / visible cards.

Risks:

- Runtime browser validation is still needed because static checks cannot prove rendered UI behavior.
- Runtime/browser profiling is still needed to quantify DOM reuse/cache impact on real printer counts.
- Modals lack robust accessibility semantics and focus handling.

## CSS analysis

The CSS is already modularized:

- `base.css`: foundational design tokens and themes.
- `layout.css`: macro layout and structural page regions.
- `components.css`: printer cards and reusable controls.
- `modals.css`: modal/form/settings UI.
- `responsive.css`: animations, media queries, and late overrides.

Contracts:

- Load order matters.
- `responsive.css` is not purely responsive; it deliberately overrides component styles.
- CSS custom properties define the theme system.
- Dynamic state classes from PHP/JS drive visual behavior.

Risks:

- Duplicate selectors across CSS modules are intentional but fragile (`.fab`, `.theme-toggle`, `.action-btn`).
- Accessibility can be improved for focus, reduced motion, status text, and progressbar semantics.
- Visual regressions require browser checks; syntax checks alone are insufficient.

## Deployment analysis

### Dockerfile

Strengths:

- Uses official `php:8.2-apache` image.
- Installs `libsnmp-dev`, `snmp`, `curl`.
- Installs PHP extensions `snmp` and `pdo_mysql`.
- Enables Apache `rewrite` and `headers`.
- Adds a healthcheck against `/`.

Risks / notes:

- `COPY src/ /var/www/html/` is commented out, so production image alone may not contain app source unless a volume or later copy is used.
- Healthcheck may fail while DB is still starting because `/` depends on DB readiness.

### docker-compose.yml

Strengths:

- Clear two-service local stack.
- Web exposed on `localhost:8080`.
- Environment variables configure DB and SNMP community.
- DB data persisted in named volume `db_data`.

Risks:

- `depends_on` controls startup order but not DB readiness.
- Development DB passwords are hardcoded.
- `SNMP_COMMUNITY=public` is the default and should be treated as a local/dev value.

### apache-vhost.conf

Strengths:

- Disables directory indexes.
- Enables expected document root.
- Adds security headers.

Risks:

- CSP allows `'unsafe-inline'`, required by current inline handlers/styles but weaker than ideal.
- Header values should stay aligned with PHP app headers.

## Security assessment

Authentication and authorization status:

- First run forces creation of a persistent main-admin account because `index.php` renders registration only when `!$db->hasUsers()`.
- Once the main admin exists, restarts and normal visits go to login until a valid session is present.
- Passwords are stored with PHP `password_hash()` and verified with `password_verify()`.
- Sessions use HttpOnly, SameSite=Lax cookies and regenerate IDs after registration/login.
- API calls require an authenticated active user plus `X-CSRF-Token`; write/privileged actions then enforce explicit permissions.
- Basic users are view-only unless the main admin toggles add/edit/delete/floor/settings/import-export permissions.

High-priority issues before broader exposure:

1. **Server-side request/probe risk remains permissioned, not eliminated.** Users granted `can_add_printer` can trigger SNMP probes from the server network; add rate limits and subnet allowlisting before untrusted deployment.
2. **Residual frontend injection/CSP risk.** Dynamic level rendering now uses DOM APIs, but inline handlers/bootstrap still require a weak CSP.
3. **Weak CSP due inline code/styles.** Tightening CSP requires removing inline handlers and moving bootstrap data to a non-inline pattern.
4. **Account recovery policy.** Lost main-admin credentials currently require direct DB intervention.

Medium-priority issues:

- Rate-limit SNMP-related endpoints.
- Add audit logging for user/permission changes and printer mutations.
- Improve error handling and logging consistency.

## Accessibility assessment

Current gaps:

- Modals should use `role="dialog"`, `aria-modal="true"`, focus trap, Escape-to-close, and focus return.
- Close controls should be buttons, not spans.
- Icon buttons need explicit `aria-label`.
- Status indicator is color-only.
- Level bars should use `role="progressbar"` with ARIA value attributes.
- Animations should respect `prefers-reduced-motion`.

## Verification status

Available checks completed during this adaptation:

- Inspected the target project tree.
- Confirmed the target folder is not a Git repository by `git status` failure.
- Retrieved upstream `agent0ai/dox` `AGENTS.md` and README.
- Parsed file inventory, PHP methods, API actions, JS functions, fetch actions, and DB table definitions.
- Added static Phase 1, Phase 2, Phase 3, and Phase 4 auth contract verifiers under `tools/`.
- Phase 2 static verification confirms JS-owned level rendering, bootstrap data, serial formatting deduplication, and Phase 1 regression contracts.
- Phase 3 static verification confirms short refresh caching, unchanged-level DB write skip, abortable refreshes, active-floor refresh ordering, DOM signature skips, and keyed level-bar reuse.
- Phase 4 static verification confirms first-admin registration/login contracts, users schema, password/session/CSRF helpers, API permission checks, and user-management UI wiring.

Environment blockers:

- Local `git status` failed because the folder is not a Git repo.
- PHP CLI availability must be checked in the active environment before `php -l`; if absent, use Docker.
- Docker availability must be checked before container build/runtime verification.

Recommended validation commands:

```sh
node --check src/script.js
node tools/verify-phase1.mjs
node tools/verify-phase2.mjs
node tools/verify-phase3.mjs
node tools/verify-phase4-auth.mjs
php -l src/index.php
php -l src/api.php
php -l src/Auth.php
php -l src/Database.php
php -l src/PrinterDiscovery.php
php -l src/CacheManager.php
php -l src/Formatting.php
docker compose config
docker compose build
docker compose up -d
curl -fsSI http://localhost:8080/
curl -i http://localhost:8080/ # first-run register or login page
curl -i http://localhost:8080/api.php?action=get_settings # unauthenticated should return 401 JSON
```

## Recommended next improvements

1. Remove inline event handlers/bootstrap script so CSP can be tightened.
2. Add SNMP endpoint rate limiting and optional subnet allowlisting before non-LAN deployment.
3. Add audit logging for login/user/permission/printer mutations.
4. Add accessibility improvements for modals, icon buttons, status, and level bars.
5. Add runtime/API smoke tests once PHP/Docker are available, including first-admin registration, login, logout, basic user permissions, and restart persistence.
6. Consider `.dockerignore` and production source-copy behavior in `Dockerfile`.
7. Tune `REFRESH_CACHE_TTL_SECONDS` with real printer/browser measurements if needed.
8. Keep DOX docs current whenever these contracts change.
