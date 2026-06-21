# Research-backed Fix Plan for S-printer

This document records the research, comparisons, and selected implementation phases for security, duplication, performance, heap/allocation, and deployment fixes.

## Sources used

| Area | Source | Relevant guidance |
|---|---|---|
| Input validation | OWASP Input Validation Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html | Validate untrusted input as early as possible; server-side validation is mandatory because client-side validation can be bypassed; prefer syntactic + semantic validation and allowlists. |
| DOM XSS | OWASP DOM Based XSS Prevention Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html | Prefer safe DOM population methods/properties like `textContent`; avoid dangerous sinks such as `innerHTML` for untrusted data. |
| `innerHTML` risk | MDN `Element.innerHTML` — https://developer.mozilla.org/en-US/docs/Web/API/Element/innerHTML | `innerHTML` is an injection sink and can be an XSS vector; use `textContent` for plain text. |
| DOM batching | MDN `DocumentFragment` — https://developer.mozilla.org/en-US/docs/Web/API/DocumentFragment | Fragments are useful for building nodes outside the active DOM; MDN cautions performance gains are often overstated, so optimize for readability unless measured. |
| Request cancellation | MDN `AbortController` — https://developer.mozilla.org/en-US/docs/Web/API/AbortController | `AbortController` can abort one or more web requests and is baseline/widely available; use it to cancel stale refresh fetches. |
| DOM node replacement/reuse | MDN `Node.replaceChild()` — https://developer.mozilla.org/en-US/docs/Web/API/Node/replaceChild | Existing nodes can be replaced/moved; this supports keyed DOM synchronization when only some level bars change. |
| SSRF / network probing | OWASP SSRF Prevention Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html | Validate IPs, prefer allowlists for trusted destinations, and treat server-side network access as a defended boundary. |
| Authentication | OWASP Authentication Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html | Use secure password handling, generic login failures, and session renewal after authentication. |
| Session management | OWASP Session Management Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html | Use secure cookie attributes, server-side sessions, and regenerate session identifiers on privilege changes/login. |
| Password storage | OWASP Password Storage Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html | Store passwords with modern one-way password hashing, not reversible encryption. |
| Authorization | OWASP Authorization Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html | Enforce least privilege and deny-by-default server-side permission checks. |
| CSRF | OWASP CSRF Prevention Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html | Use CSRF tokens for state-changing authenticated requests; compare tokens safely. |
| PHP password API | PHP manual `password_hash()` / `password_verify()` — https://www.php.net/manual/en/function.password-hash.php | Use PHP password API instead of custom hashing. |
| PHP sessions/constant-time compare | PHP manual `session_regenerate_id()` / `hash_equals()` — https://www.php.net/manual/en/function.session-regenerate-id.php | Regenerate session IDs after auth and compare secret tokens using timing-safe equality. |
| Docker readiness | Docker Compose startup order docs — https://docs.docker.com/compose/how-tos/startup-order/ | `depends_on` starts dependencies in order but does not wait for readiness; use `condition: service_healthy` with `healthcheck` for ready dependencies. |
| Docker healthcheck syntax | Docker Compose file reference — https://docs.docker.com/reference/compose-file/services/#healthcheck | Use a service `healthcheck` to define readiness/health commands and timing. |

## Comparison of fix options

### 1. Server-side validation

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Keep browser-only validation | No backend work | Insecure; bypassable; violates OWASP server-side validation guidance | Reject |
| Add validation inline in each `case` | Small local edits | Duplication and drift across endpoints | Reject |
| Add centralized helper functions in `api.php` | Consistent rules; small no-framework change; easy to audit | `api.php` grows slightly | Select for Phase 1 |
| Add full validation library/framework | Stronger long-term if app grows | Adds dependency/build complexity to a no-Composer app | Defer |

Selected Phase 1 behavior:

- Validate IPs server-side for `check_ip`, `add_printer`, `refresh_printer`.
- Validate/sanitize printer payload fields centrally.
- Restrict `purchase_url` to safe HTTP/HTTPS URLs.
- Allowlist setting keys.
- Add a request body size guard for JSON POST/import.

### 2. Server-side network probing / SSRF-style risk

S-printer intentionally probes printer IPs over SNMP. This is not HTTP SSRF, but the same boundary principle applies: user input controls server-side network traffic.

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Allow any valid IP | Flexible for any network | Can be abused for arbitrary internal probing | Reject as long-term default |
| Block private IPs | Reduces internal probing | Breaks the main use case: printers are usually private LAN IPs | Reject |
| Require explicit CIDR allowlist env var | Strongest for multi-user deployments | Needs subnet config and CIDR helper; may break existing installs if mandatory | Defer to Phase 2 |
| Validate syntax now, document trusted-LAN assumption | Safe compatibility improvement | Does not fully solve abuse without auth/allowlist | Select Phase 1 |

Selected Phase 1 behavior:

- Validate IP syntax strictly and reject malformed IPs everywhere.
- Document that full network allowlisting/auth is still needed before untrusted exposure.

### 3. Dynamic DOM rendering and `innerHTML`

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Keep `innerHTML` and escape strings | Smallest change | Context-specific escaping is easy to get wrong; still parses markup; high XSS risk | Reject for untrusted dynamic data |
| Use sanitizer like DOMPurify | Good if rich HTML is needed | Adds dependency and still allows HTML | Defer; app mostly needs plain text/widgets |
| Use DOM APIs + `textContent` for untrusted values | Matches OWASP/MDN guidance; avoids HTML parsing for data | More verbose code | Select for level rendering cleanup phase |
| Use templates/DocumentFragment everywhere | Can improve structure | MDN says perf gains are often overstated; may over-refactor | Use selectively |

Selected Phase 1 behavior:

- Avoid new `innerHTML` paths.
- Keep major level-rendering refactor for Phase 2 because it touches many UI states.
- In Phase 1, fix smaller correctness bugs first.

### 4. Duplicate rendering logic

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Keep PHP + JS duplicate level renderers | Initial page works without JS; no immediate work | Drift risk; duplicated bug fixes; duplicated allocations | Reject long-term |
| PHP owns rendering and API returns HTML | One renderer | API mixes data+HTML; still needs HTML escaping; less flexible | Reject |
| JS owns rendering and PHP emits data/shell | One dynamic renderer; safer DOM API path; best fit for async refresh UI | Needs careful initial-render migration | Select for Phase 2 |

Selected phase:

- Phase 1 keeps behavior stable.
- Phase 2 moves level rendering to a single JS DOM renderer and removes `renderLevelsSectionHtml()` duplication.

### 5. Heap/allocation/performance

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Micro-optimize PHP arrays/strings | Low risk | Low impact; PHP request memory is released after each request | Reject as priority |
| Reduce duplicate HTTP/DB requests | High impact on page load | Needs bootstrap globals and fallback logic | Select Phase 2 |
| Prevent overlapping refreshes | Reduces wasted fetches/SNMP probes and race conditions | Small JS state management | Select Phase 1/2 if low risk |
| Cache per-printer SNMP briefly | Reduces printer/network/PHP load | Freshness tradeoff; cache invalidation rules | Select Phase 3 |
| Avoid DB writes when levels unchanged | Reduces DB churn | Needs JSON comparison | Select Phase 3 |
| DOM node reuse for level bars | Reduces browser churn | More refactor; should follow single renderer work | Select Phase 2/3 |

Selected Phase 1 behavior:

- Fix clear correctness/perf bug in display settings application.
- Fix floor+search filtering to avoid incorrect DOM state.
- Consider refresh overlap guard if minimally invasive.

Selected Phase 3 behavior:

- Add a short per-printer refresh cache for `refresh_printer` responses.
- Skip `last_levels` DB writes when the normalized JSON payload has not changed.
- Use `AbortController` to cancel stale refresh runs.
- Prioritize visible/current-floor cards before background cards.
- Reuse keyed level-bar DOM nodes and skip unchanged level-section renders.

### 6. Authentication and authorization

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Keep trusted-LAN/no-login model | Simplest local-only deployment | Any reachable user can mutate printers/settings and export data | Reject |
| Single hardcoded admin password | Small implementation | No per-user accountability or least privilege; awkward rotation | Reject |
| First-run local admin + per-user permissions | Matches self-hosted app expectations; survives Docker restarts through DB; supports view-only/basic users | Requires schema, UI, sessions, CSRF, permission checks | Select Phase 4 |
| External SSO/OIDC | Strong for enterprise deployments | Adds provider configuration and dependencies beyond no-framework app scope | Defer |

Selected Phase 4 behavior:

- If no user exists, `/` renders first-admin registration.
- After the main admin exists, `/` renders login for unauthenticated sessions, including after Docker restarts.
- Main admin can create basic users and toggle add/edit/delete/floor/settings/import-export permissions.
- Passwords are stored with PHP `password_hash()` and checked with `password_verify()`.
- Sessions use HttpOnly, SameSite=Lax cookies, regenerate IDs after auth, and carry a CSRF token.
- `api.php` requires an active user, validates `X-CSRF-Token`, and then enforces per-action permissions.

### 7. Docker readiness

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Keep current `depends_on` | Simple | Docker docs say it does not wait for readiness | Reject long-term |
| Add DB healthcheck + `condition: service_healthy` | Official Compose pattern; better startup reliability | Requires `mariadb-admin` command availability in DB image | Select Phase 5 |
| Add retry loop in PHP DB constructor | Works outside Compose too | Adds app-level startup complexity | Defer; maybe pair with Compose healthcheck |

Selected phase:

- Defer Docker changes until after app-level safety work, unless deployment reliability becomes the active priority.

## Implementation phases

### Phase 1 — low-risk correctness and validation

Backed by OWASP Input Validation and SSRF boundary guidance.

- Add JSON body size limit.
- Add central API helpers for input arrays, IP validation, URL validation, text length limiting, allowed settings.
- Align `api.php` SNMP community with `SNMP_COMMUNITY` env var.
- Validate printer payloads for add/edit/import paths.
- Validate `refresh_printer` IP.
- Fix `saveDisplaySettings()` immediate-apply order.
- Fix search + active floor filter interaction.
- Add delete confirmations for destructive UI actions.

### Phase 2 — duplication and DOM safety

Backed by OWASP DOM XSS + MDN `innerHTML` guidance.

- Move level rendering to one JS-owned DOM renderer.
- Remove duplicated PHP level renderer or reduce PHP to a data bootstrap shell.
- Replace dynamic level `innerHTML` with DOM creation/update and `textContent`.
- Bootstrap settings/floors from server-rendered JSON to avoid duplicate startup fetches.
- Normalize serial display server-side to remove PHP/JS duplication.

### Phase 3 — performance/heap/SNMP efficiency

Backed by MDN DOM batching guidance and app-specific hot-path analysis.

- Add short per-printer refresh cache.
- Avoid DB writes when levels did not change.
- Add `AbortController` support for canceling stale per-printer refresh requests.
- Update existing level DOM nodes where practical instead of rebuilding whole sections.
- Refresh visible printers first when a floor filter is active.

### Phase 5 — deployment/accessibility hardening

Backed by Docker Compose readiness docs and accessibility best practices to be researched before implementation.

- Add DB service healthcheck and `depends_on.condition: service_healthy`.
- Decide production Docker source-copy behavior.
- Improve modal roles/focus/Escape behavior.
- Add ARIA labels/progressbar attributes.
- Add reduced-motion handling.

## Current selected start

Start with Phase 1 because it is the best risk/reward set: it improves server trust boundaries and fixes visible correctness issues without large rendering rewrites.

## Phase 1 status

Implemented in this pass:

- `api.php` now caps JSON bodies at 2 MiB and centralizes JSON parsing.
- `api.php` now validates IP syntax before SNMP/DB lookup paths.
- `api.php` now sanitizes printer payloads before add/edit/import DB writes.
- `api.php` now restricts `purchase_url` to HTTP/HTTPS URLs.
- `api.php` now reads `SNMP_COMMUNITY` before constructing `PrinterDiscovery`.
- `script.js` now caps imported backup files at 2 MiB client-side.
- `script.js` now updates `window.appSettings` before applying display settings.
- `script.js` now confirms printer and floor deletes before API calls.
- `script.js` now filters search results by active floor.
- `script.js` now creates newly added floor rows with DOM APIs instead of user-controlled `innerHTML`.
- `script.js` now prevents overlapping refresh-all runs.
- `tools/verify-phase1.mjs` verifies these static contracts plus JS/API action parity.

Validation completed:

```sh
node tools/verify-phase1.mjs
node --check src/script.js
```

Both commands passed. PHP and Docker runtime validation remain blocked in the current shell because `php` and `docker` are unavailable.

## Phase 2 status

Implemented in this pass:

- `src/Formatting.php` now centralizes display-only PHP formatting and safe JSON emission helpers.
- `index.php` now loads `Formatting.php` instead of owning duplicate serial cleanup logic.
- `api.php` now returns `info.serial_number_display` on printer refresh responses.
- `index.php` no longer owns a duplicate PHP level renderer; it emits card shells plus level JSON data attributes.
- `script.js` now owns level rendering through `renderLevelsSection(levelsSection, status, levels, lastLevels)`.
- Level rendering now uses DOM APIs, `DocumentFragment`, and `textContent` instead of building level HTML strings.
- Static search/floor empty states now use DOM construction instead of `innerHTML`.
- `index.php` bootstraps settings/floors into `window.__SPRINTER_BOOTSTRAP__`, so startup only fetches those APIs as a fallback.
- `tools/verify-phase2.mjs` verifies renderer ownership, bootstrap, serial-format deduplication, and Phase 1 regression contracts.

Validation completed:

```sh
node tools/verify-phase2.mjs
node tools/verify-phase1.mjs
node --check src/script.js
```

`tools/verify-phase2.mjs` also runs the Phase 1 verifier and JS syntax check. PHP and Docker runtime validation remain blocked in the current shell because `php` and `docker` are unavailable.

## Phase 3 status

Implemented in this pass:

- `api.php` now uses `REFRESH_CACHE_TTL_SECONDS = 15` for short per-printer `refresh_printer_<ip>` cache entries.
- `api.php` now reads the per-printer refresh cache before SNMP and stores fresh refresh responses after SNMP.
- `api.php` now centralizes runtime cache invalidation through `invalidateRuntimeCache()` and clears per-printer refresh caches on printer/import mutations.
- `Database.php` now provides `hasLevelsChanged()` and `updatePrinterLevelsIfChanged()` with recursive key normalization to avoid no-op `last_levels` writes.
- `script.js` now uses `AbortController` and per-card `WeakMap` refresh tokens so stale refreshes can be canceled without stale cleanup mutating newer card state.
- `script.js` now prioritizes active-floor / visible printer cards before background cards during refresh-all.
- `script.js` now computes stable level-render signatures, skips unchanged level sections, and syncs keyed level bars through `updateLevelBar()` / `syncLevelBars()`.
- `tools/verify-phase3.mjs` verifies the Phase 3 cache, DB-write-skip, cancellation, refresh-ordering, and DOM-reuse contracts plus Phase 2 regression contracts.

Validation completed:

```sh
node tools/verify-phase3.mjs
node tools/verify-phase2.mjs
node tools/verify-phase1.mjs
node --check src/script.js
```

`tools/verify-phase3.mjs` also runs the Phase 2 verifier and JS syntax check. PHP and Docker runtime validation remain blocked in the current shell if `php` and `docker` are unavailable.

## Phase 4 status — Authentication and authorization

Implemented in this pass:

- `src/Auth.php` centralizes hardened session startup, first-admin registration, login/logout, password hashing/verification, CSRF token generation/validation, and permission helpers.
- `Database.php` now creates and manages `users(username, password_hash, is_admin, can_add_printer, can_edit_printer, can_delete_printer, can_manage_floors, can_manage_settings, can_import_export, is_active, last_login_at, created_at, updated_at)`.
- `index.php` forces first-admin registration only while no user exists; once a main account exists it renders login for unauthenticated sessions, so Docker restarts do not force re-registration.
- The authenticated dashboard bootstraps `csrfToken`, `currentUser`, and `currentPermissions` for the browser.
- `api.php` requires an authenticated active user for all API actions, validates `X-CSRF-Token`, and enforces deny-by-default per-action permissions.
- Main admin can create basic users and toggle permissions in the Settings → Users UI. Basic users are view-only unless permissions are granted.
- Authenticated users now have a compact profile/account dropdown with username, role badge, logout, and own-password change.
- `api.php?action=change_own_password` verifies the current password, validates the new password, updates the hash, regenerates the PHP session ID, and returns a rotated CSRF token.
- `script.js` now routes API calls through `apiFetch()` so the CSRF token is sent consistently, gates UI affordances with `hasPermission()`, updates bootstrapped and hidden-form CSRF tokens after password rotation, and wires header controls before awaited bootstrap fallback so visible controls are immediately interactive after login.
- `src/css/modals.css` now styles first-run/login auth screens and user-management controls; `src/css/components.css` styles the account dropdown and role badge.
- `src/css/base.css` now uses compact focus treatment for text fields while preserving visible keyboard focus on controls.
- `tools/verify-phase4-auth.mjs` verifies the auth/session/password/CSRF/schema/API/UI/documentation contracts and runs Phase 3 regression checks.

Validation target:

```sh
node tools/verify-phase4-auth.mjs
node --check src/script.js
php -l src/Auth.php src/index.php src/api.php src/Database.php
```

Browser/runtime checks still needed with PHP/Docker available:

- first-run main-admin registration
- logout/login
- immediately click settings/theme/account/logout after login, before background refresh finishes
- change own password from the account dropdown, then confirm subsequent API calls still pass CSRF
- Docker restart with existing `users` table showing login, not registration
- create basic view-only user
- toggle add/edit/delete/floor/settings/import-export permissions and confirm denied actions stay hidden and API-denied

## Phase 5 status — CSP script hardening

Implemented in this pass:

- `index.php` now emits dashboard bootstrap data as inert `<script id="sprinterBootstrap" type="application/json">` JSON instead of executable inline JavaScript.
- `script.js` now parses `#sprinterBootstrap`, populates the existing `window.__SPRINTER_BOOTSTRAP__` contract, and still uses `apiFetch()` for CSRF-aware API calls.
- Printer edit/delete card controls now use `data-action`, `data-printer-id`, and `data-printer-info` with delegated `setupPrinterActions()` listeners instead of inline `onclick` attributes.
- The database-loading page no longer injects inline script/style; it relies on the existing HTTP `Refresh` header and `.db-loading-page` CSS.
- The import file input now uses `.visually-hidden` instead of an inline `style` attribute.
- PHP and Apache CSP headers now use `script-src 'self'` without `script-src 'unsafe-inline'`. `style-src 'unsafe-inline'` remains until remaining style needs are fully audited.
- `tools/verify-phase5-csp.mjs` verifies the CSP/no-inline-script contracts and runs Phase 4 regression checks.

Validation target:

```sh
node tools/verify-phase5-csp.mjs
node --check src/script.js
php -l src/index.php src/Auth.php
```

Browser/runtime checks still needed with PHP/Docker available:

- first-run registration/login still receives the auth page CSS correctly under the tighter script CSP
- authenticated dashboard loads bootstrap data from `#sprinterBootstrap`
- edit/delete printer buttons still work through delegated listeners
- DB startup loading page still refreshes via the `Refresh` header

## Phase 6 status — Accessibility and auto-sort

Implemented in this pass:

- Modal close controls changed from `<span>` to `<button type="button" aria-label="Close">`.
- Both modals now declare `role="dialog"`, `aria-modal="true"`, and `aria-labelledby`.
- A focus trap (`trapFocus()`) cycles Tab within the active modal, closes on Escape, and returns focus to the trigger button on close.
- Status indicators use `role="img"` with `aria-label` so color-only status is accessible to screen readers.
- Level bars expose `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`, and `aria-label`.
- CSS `prefers-reduced-motion` media query disables animations, transitions, hover lifts, and spinner rotations for users who request reduced motion.
- After each full refresh, cubes are auto-sorted by lowest supply level (lowest cartridge percentage first). Offline/no-data cubes sort to the end. Search relevance sorting overrides this during active search.
- The edit button refresh path now updates `data-printer-info` instead of assigning `onclick`, keeping it consistent with Phase 5 delegated handling.
- `tools/verify-phase6-a11y.mjs` verifies the accessibility and auto-sort contracts and runs Phase 5 regression checks.

Validation target:

```sh
node tools/verify-phase6-a11y.mjs
node --check src/script.js
```

Browser/runtime checks still needed:

- Tab cycling stays inside modal and returns focus on close
- Escape closes modals and returns focus
- Screen reader announces status and progressbar values
- Reduced-motion mode disables animations
- After refresh, lowest-level printers appear first in the grid
- Search still re-sorts by relevance; clearing search restores level order

## Phase 7 status — Security hardening

Implemented in this pass (backed by OWASP Authentication Cheat Sheet, PHP manual, and audit findings):

- **H1 — First-admin TOCTOU race fixed:** `Database::createFirstAdminAtomic()` wraps `SELECT COUNT(*) FROM users FOR UPDATE` + INSERT inside a single transaction. Concurrent registration attempts serialize on the row lock, so only one can succeed.
- **H2 — password_hash leak fixed:** `Database::getUsers()` now uses an explicit column list that excludes `password_hash`. Previously `SELECT *` included it (mitigated by `sprinterPublicUser()` filtering, but defense-in-depth now closes the gap at the DB layer).
- **M2 — Import error info disclosure fixed:** `api.php` import catch block now logs `$e->getMessage()` server-side and returns a generic `'Import failed. Check server logs for details.'` to the client.
- **M3 — API Cache-Control:** All API responses now include `Cache-Control: no-store, no-cache, must-revalidate` and `X-Content-Type-Options: nosniff`, preventing browsers/proxies from caching authenticated user/printer data.
- **M5 — Throwable catch:** API outer exception handler now catches `Throwable` instead of `Exception`, so `TypeError`/`Error` from fatal bugs also get the clean generic-message handler instead of an unfiltered 500.
- **M7 — Cache write locking:** `CacheManager::set()` now uses `LOCK_EX` to prevent concurrent-write file corruption.
- **M8 — Cache dir permissions:** `CacheManager` now creates the cache directory with `0775` instead of `0777`.
- `tools/verify-phase7-security.mjs` verifies all Phase 7 contracts and runs Phase 6 regression checks.

Validation target:

```sh
node tools/verify-phase7-security.mjs
node --check src/script.js
php -l src/api.php src/Auth.php src/Database.php src/CacheManager.php
```

Remaining audit findings not yet addressed (medium/low priority, no immediate exploit):

- No login brute-force protection / rate limiting (OWASP recommends account lockout with exponential backoff)
- SSRF: SNMP probing accepts any IP (by-design for a LAN printer scanner, but add deny-list before non-LAN exposure)
- Login timing oracle (username enumeration via password_verify timing)
- No server-side idle session timeout
- Chunked import commits break atomicity (partial import on failure)
- `"0"` falsy-string logic bug in serial/printer name handling

## Phase 8 status — Brute-force protection

Implemented in this pass (backed by OWASP Authentication Cheat Sheet brute-force guidance):

- **Per-account lockout:** Added `failed_attempts INT` and `locked_until TIMESTAMP NULL` columns to the `users` table (idempotent migration).
- **Exponential backoff:** After 5 failed login attempts (`SPRINTER_MAX_LOGIN_ATTEMPTS`), the account is locked. Each subsequent failure doubles the lockout duration: 15s → 30s → 60s → 120s → 240s... capped at 1 hour (`SPRINTER_LOCKOUT_MAX_SECONDS`). Lockout is enforced via `Database::isUserLocked()` before `password_verify` runs.
- **Timing oracle fixed:** Nonexistent usernames now trigger a dummy `password_verify()` against `SPRINTER_DUMMY_HASH`, so response time is identical whether the username exists or not. Previously, `password_verify` only ran for existing users, allowing username enumeration via timing.
- **Auto-reset on success:** `Database::resetFailedLogin()` clears the counter and lockout immediately on valid login.
- **DoS-resistant:** Lockout applies only to the targeted account, not globally. Password reset by admin clears the lockout (admin can reset any user's password via the Users UI). The main admin cannot be permanently locked out because lockout expiry is automatic.
- `tools/verify-phase8-bruteforce.mjs` verifies the brute-force protection contracts and runs Phase 7 regression checks.

Validation target:

```sh
node tools/verify-phase8-bruteforce.mjs
node --check src/script.js
php -l src/Auth.php src/Database.php
```

Browser/runtime checks needed:

- Login with wrong password 5 times → 6th attempt is blocked even with correct password
- Wait for lockout to expire → login succeeds with correct password
- Successful login resets the counter (try 4 failures, then login, then 4 more — should not lock)
- Nonexistent username returns same response timing as existing-but-wrong-password

## Phase 9 status — Pantum printer SNMP fix

Implemented in this pass (backed by research from two Pantum-specific open-source projects):

Research sources:
- `github.com/bdomitil/stat-pantum` — Go CLI that queries Pantum printers via SNMP, confirmed working with proprietary enterprise OIDs.
- `github.com/Shikkigayafr/Pantum_M6550NW` — Zabbix template + troubleshooting guide for Pantum M6550NW, confirming standard MIB behavior and quirks.

Root cause:
- Pantum printers return toner level as a **percentage directly** (0-100) in the standard Printer-MIB supply level OID (`.1.3.6.1.2.1.43.11.1.1.9.1.1`), not as a raw current value.
- The generic code divided this percentage by the max-capacity value, producing wrong or zero results.
- Pantum also has a truncated SNMP agent — many standard OIDs return "No Such Object".

Fix:
- Added `getPantumConsumableLevels()` that queries the Pantum proprietary enterprise OID tree (.1.3.6.1.4.1.40093):
  - Toner percentage: `.1.3.6.1.4.1.40093.1.1.3.13.2` (returns 0-100 directly)
  - Serial number: `.1.3.6.1.4.1.40093.1.1.1.5` (added to serial OID fallback list)
- Wired into `getDirectSupplyLevels()` like the Brother handler: try proprietary OIDs first, fall back to standard MIB walk if they don't respond.
- Removed the old Pantum entry from the generic OID mapping table (it was just a copy of the standard map, which doesn't work for Pantum).

Validation target:

```sh
node tools/verify-phase9-pantum.mjs
php -l src/PrinterDiscovery.php
```

Browser/runtime checks needed with a real Pantum printer:

- Add a Pantum printer by IP → toner level should show correct percentage
- Verify serial number populates (from Pantum proprietary OID)
- Verify the printer shows "online" when powered on

