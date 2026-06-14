# Research-backed Fix Plan for S-printer

This document records the research, comparisons, and selected implementation phases for security, duplication, performance, heap/allocation, and deployment fixes.

## Sources used

| Area | Source | Relevant guidance |
|---|---|---|
| Input validation | OWASP Input Validation Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html | Validate untrusted input as early as possible; server-side validation is mandatory because client-side validation can be bypassed; prefer syntactic + semantic validation and allowlists. |
| DOM XSS | OWASP DOM Based XSS Prevention Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html | Prefer safe DOM population methods/properties like `textContent`; avoid dangerous sinks such as `innerHTML` for untrusted data. |
| `innerHTML` risk | MDN `Element.innerHTML` — https://developer.mozilla.org/en-US/docs/Web/API/Element/innerHTML | `innerHTML` is an injection sink and can be an XSS vector; use `textContent` for plain text. |
| DOM batching | MDN `DocumentFragment` — https://developer.mozilla.org/en-US/docs/Web/API/DocumentFragment | Fragments are useful for building nodes outside the active DOM; MDN cautions performance gains are often overstated, so optimize for readability unless measured. |
| SSRF / network probing | OWASP SSRF Prevention Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html | Validate IPs, prefer allowlists for trusted destinations, and treat server-side network access as a defended boundary. |
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

### 6. Docker readiness

| Option | Pros | Cons | Decision |
|---|---|---|---|
| Keep current `depends_on` | Simple | Docker docs say it does not wait for readiness | Reject long-term |
| Add DB healthcheck + `condition: service_healthy` | Official Compose pattern; better startup reliability | Requires `mariadb-admin` command availability in DB image | Select Phase 4 |
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

### Phase 4 — deployment/accessibility hardening

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
