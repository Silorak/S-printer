# S-printer Runtime Source DOX

## Purpose

`src/` contains the runtime web application served by Apache: the PHP dashboard, JSON API, MariaDB data access layer, SNMP printer discovery/status logic, file cache helper, browser JavaScript, and static CSS assets.

## Ownership

This document owns these files and their runtime contracts:

- `index.php` — first-admin/login gating, server-rendered dashboard shell, security headers, initial DB-backed card render, modals, and DOM bootstrap.
- `api.php` — authenticated JSON API consumed by `script.js`.
- `Auth.php` — local session startup, first-admin registration, login/logout, password hashing, CSRF token checks, and permission helpers.
- `Database.php` — PDO/MariaDB connection, idempotent schema creation/migration, user/printer/floor/settings CRUD, import/export helpers.
- `PrinterDiscovery.php` — SNMPv2c printer probing, vendor detection, printer metadata, and consumable-level parsing.
- `CacheManager.php` — JSON file cache under the runtime cache directory.
- `Formatting.php` — shared PHP display-format and JSON-escaping helpers for entry points.
- `script.js` — browser behavior, API calls, modal flows, search/filtering, refresh concurrency, import/export, settings, and card updates.

`src/css/AGENTS.md` owns all files under `src/css/`.

## Local Contracts

### PHP and API

- PHP source files must use `declare(strict_types=1);`.
- Do not introduce a Composer/autoloader dependency without updating Docker/runtime docs and verification.
- `api.php` is JSON-only. It must not emit HTML.
- API responses must preserve the envelope shape:
  - success: `{ "success": true, "data": ... }`
  - error: `{ "success": false, "error": "..." }`
- `api.php` must call `sprinterRequireApiUser($db)` before serving actions. The unauthenticated first-run path lives in `index.php`, not the JSON API.
- All API calls must include the `X-CSRF-Token` header with the session token bootstrapped by `index.php`; `script.js::apiFetch()` owns this header.
- New mutating browser API calls must use `apiFetch()`, not raw `fetch()`, so CSRF protection is retained.
- Mutating actions must require `POST` and `Content-Type: application/json`.
- JSON request bodies are capped at 2 MiB by `MAX_JSON_BODY_BYTES`; keep the browser import-file cap aligned with this server limit.
- API input must flow through centralized validation/sanitization helpers before DB writes, SNMP probes, or settings changes.
- Keep the current API action names stable unless `script.js`, docs, and tests/smoke checks are updated together:
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
- Permission mapping:
  - `check_ip`, `add_printer` require `can_add_printer`
  - `edit_printer` requires `can_edit_printer`
  - `delete_printer` requires `can_delete_printer`
  - `add_floor`, `edit_floor`, `delete_floor` require `can_manage_floors`
  - `save_settings` requires `can_manage_settings`
  - `import_printers`, `export_printers` require `can_import_export`
  - user-management actions require main-admin-only `can_manage_users`
- Use PDO prepared statements for all untrusted database input.
- Keep `Database::initSchema()` idempotent. Existing installs may run it repeatedly.
- Settings keys are an allowlist. Current supported keys are `hide_ip` and `show_serial`.
- Setting values are normalized to `'0'` or `'1'`; do not persist arbitrary settings values.
- Export format version is currently `1`; bump and document it if the import/export structure changes.

### Database shape

The live schema is initialized in `Database.php` and currently includes:

- `users(id, username, password_hash, is_admin, can_add_printer, can_edit_printer, can_delete_printer, can_manage_floors, can_manage_settings, can_import_export, is_active, last_login_at, created_at, updated_at)`
- `floors(id, name, sort_order, created_at)`
- `settings(setting_key, setting_value, updated_at)`
- `printers(id, ip, name, description, purchase_url, serial, model, vendor, floor_id, custom_oids, last_levels, created_at, updated_at)`

Durable schema contracts:

- `users.password_hash` stores PHP `password_hash()` output only; never store plain text or reversible passwords.
- The first account is the persistent main admin (`is_admin = 1`) and always has every permission. Do not add UI/API paths that demote or disable the main admin.
- Basic users default to view-only. Main admin grants printer/floor/settings/import-export permissions explicitly.
- `printers.ip` is unique and supports IPv4/IPv6 via `VARCHAR(45)`.
- `printers.floor_id` references `floors.id` and uses `ON DELETE SET NULL` on newly initialized schemas.
- `custom_oids` and `last_levels` are JSON columns.
- If adding persisted fields, update schema/migration, insert/update paths, import/export, server render, edit form, JS modal population, docs, and verification.

### SNMP and printer data

- SNMP uses SNMPv2c and defaults to community `public` unless configured through `SNMP_COMMUNITY`.
- `index.php` and `api.php` both read `SNMP_COMMUNITY`; keep these paths aligned when touching SNMP setup.
- Detection starts with sysDescr OID `.1.3.6.1.2.1.1.1.0`.
- Standard consumable fallback walks Printer-MIB supply table `.1.3.6.1.2.1.43.11.1.1`.
- SNMP failures and timeouts are normal operational states. Preserve offline behavior instead of converting every SNMP failure into a fatal application error.
- Avoid uncontrolled network scanning. The current application checks specific configured/requested IPs.
- Consumable status semantics are a frontend contract:
  - `-3` means level is above threshold / OK; render as `status: "ok"` at 100%.
  - `-2` means unknown; skip that supply.
  - level `<= 0` means `status: "empty_or_not_genuine"`.
- `PrinterDiscovery::getPrinterData()` must keep returning:
  - `info.status`: `loading`, `online`, or `offline`
  - `info.last_levels`: last DB JSON string or null
  - `info.serial_number_display`: display-normalized serial string added by API/dashboard entry points
  - `levels[color]`: `percentage`, `current`, `max`, `color`, `description`, optional `status`
- `api.php?action=refresh_printer` uses a short per-printer cache (`refresh_printer_<ip>`, `REFRESH_CACHE_TTL_SECONDS`) to reduce duplicate SNMP probes.
- Online refreshes must use `Database::updatePrinterLevelsIfChanged()` so unchanged level payloads do not write to MariaDB.

### Browser/DOM contracts

- `script.js` relies on IDs and classes emitted by `index.php`. Rename only when updating PHP, JS, and CSS together.
- `window.__SPRINTER_BOOTSTRAP__` includes `csrfToken`, `currentUser`, and `currentPermissions`; keep those fields stable for `apiFetch()` and permission-gated UI.
- `script.js::hasPermission()` gates browser affordances only. Server-side `sprinterRequirePermission()` remains authoritative.
- Important IDs/classes/data attributes include:
  - `printerModal`, `addPrinterBtn`, `printerForm`, `printerIp`, `checkIpBtn`
  - `settingsModal`, `settingsBtn`, `floorList`, `refreshDataBtn`, `lastRefreshTime`
  - `logoutForm`, `usersTab`, `userCreateForm`, `userList`, `.user-row`, `[data-permission]`
  - `.cube`, `data-id`, `data-ip`, `data-floor-id`
  - `.status-indicator`, `.printer-name`, `.printer-serial`, `.levels-section`, `.edit-btn`
  - `.levels-section[data-initial-status][data-initial-levels][data-initial-last-levels]`
- `openEditModal()` and `deletePrinter()` are called from inline `onclick` handlers in `index.php`; they must remain global unless inline handlers are removed and event listeners replace them.
- Level rendering is JS-owned: `index.php` emits card shells plus JSON data attributes, and `script.js::renderLevelsSection(levelsSection, status, levels, lastLevels)` renders DOM nodes with `textContent`/DOM APIs.
- The JS level renderer computes stable signatures and keyed level bars (`data-level-key`) so unchanged sections can be skipped and existing bars can be reused.
- `index.php` bootstraps settings/floors through `window.__SPRINTER_BOOTSTRAP__`; `script.js` should only fetch those APIs on bootstrap fallback.
- `index.php` renders first-admin registration when `!$db->hasUsers()`, renders login when a user exists but the session is unauthenticated, and renders the dashboard only for active authenticated users.
- Settings tabs are permission-gated: floors, display settings, and users may not all exist for every account.
- `script.js` throttles all-printer refreshes with `throttlePromises(tasks, 4)`; preserve bounded concurrency for large printer lists.
- `script.js` uses `AbortController` plus per-card `WeakMap` refresh tokens to cancel stale refresh work without stale cleanup corrupting newer card state.
- Refresh ordering should prioritize active-floor / visible cards before background cards.
- Search and floor filtering share `.cube` visibility state. Test both features together after DOM/layout changes.

### Security and escaping

- Use `Auth.php` helpers for all auth/session work. Do not call `session_start()`, `password_hash()`, `password_verify()`, or raw CSRF comparisons from scattered code unless the helper contract is intentionally being changed.
- Session cookies must stay HttpOnly and SameSite=Lax. Preserve `session_regenerate_id(true)` after successful registration/login.
- Passwords must be validated before hashing and verified only with PHP's password API.
- CSRF tokens must be generated with `random_bytes()` and compared with `hash_equals()`.
- Authorization must be deny-by-default: adding a new API action requires choosing an explicit permission or documenting why all authenticated users may call it.
- Escape all server-rendered untrusted content with `htmlspecialchars` in the correct context.
- Prefer `textContent`/DOM APIs over `innerHTML` for new client-side rendering of untrusted data.
- If `innerHTML` is unavoidable, sanitize/escape for the target context and test malicious import/API/SNMP data.
- Validate IP addresses server-side; browser `pattern` attributes are not sufficient.
- Validate `purchase_url` server-side before storing or rendering; restrict to safe schemes when this area is touched.
- Destructive browser actions should ask for confirmation before calling delete APIs.
- Keep CSP changes coordinated with inline event handlers and inline `style` attributes.
- Do not expose detailed DB/SNMP stack errors to API clients; log server-side details instead.

### Cache

- `CacheManager` stores JSON files named `md5($key) . '.json'` under the runtime cache directory.
- Printer/import mutations call centralized cache invalidation that clears per-printer refresh caches and legacy cache entries.
- Per-printer refresh cache TTL is intentionally short to reduce duplicate SNMP probes without hiding stale printer state for long.
- The current dashboard primarily uses async per-printer refresh rather than synchronous cached SNMP reads during initial page render.

## Work Guidance

- For a new persisted printer/floor/settings field:
  1. update `Database::initSchema()` and any needed idempotent migrations,
  2. update add/edit/update/import/export methods,
  3. update `api.php` validation and response shape,
  4. update `index.php` forms/rendering,
  5. update `script.js` modal population, serialization, and refresh/update path,
  6. update CSS if the field is visible,
  7. update docs and DOX contracts.
- For a new API action, add method checks, input validation, envelope responses, cache invalidation if needed, and a curl smoke test.
- For a new vendor/model SNMP shortcut, preserve generic RFC 3805 fallback and document model-specific OID assumptions.
- For UI state changes, test initial server render and post-refresh client render; both must produce equivalent visual behavior.
- Keep root `architecture.md` / `architektura.md` updated when lifecycle, protocol, schema, or deployment behavior changes.

## Verification

Use the strongest available subset:

```sh
php -l src/index.php
php -l src/api.php
php -l src/Auth.php
php -l src/Database.php
php -l src/PrinterDiscovery.php
php -l src/CacheManager.php
php -l src/Formatting.php
node --check src/script.js
node tools/verify-phase1.mjs
node tools/verify-phase2.mjs
node tools/verify-phase3.mjs
node tools/verify-phase4-auth.mjs
```

If PHP is only available in Docker:

```sh
docker compose exec web sh -lc 'for f in /var/www/html/*.php; do php -l "$f"; done'
```

API smoke checks after relevant changes:

```sh
# First request / and create/login a main-admin account; API smoke checks then need the session cookie and X-CSRF-Token from the authenticated page bootstrap.
curl -i http://localhost:8080/api.php?action=get_settings # unauthenticated should return 401 JSON
```

Manual/browser checks after UI changes:

- dashboard with zero printers
- first-run main-admin registration, logout, login, and docker restart persistence of the `users` table
- main-admin creation of a basic view-only user, then toggling add/edit/delete/floor/settings/import-export permissions
- loading, online, offline, no-data, OK, and empty/not-genuine level states
- add/edit/delete printer flows
- import/export round trip
- floor add/edit/delete and floor filter
- search combined with active floor filter
- display settings save/apply
- browser console has no JavaScript errors

## Child DOX Index

- `css/AGENTS.md` — CSS module ownership, theme, layout, components, modals, responsive behavior, animations, and visual-state contracts.
