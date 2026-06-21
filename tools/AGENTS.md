# S-printer Verification Tools DOX

## Purpose

`tools/` contains static verification scripts and the dynamic security scanner. These scripts enforce the project's phase-by-phase contracts (correctness, security, accessibility, vendor SNMP support) and can be run without Docker or PHP.

## Ownership

This document owns:

- `verify-phase1.mjs` — Input validation and security contracts
- `verify-phase2.mjs` — Renderer and DOM-safety contracts
- `verify-phase3.mjs` — Performance, cache, and cancellation contracts
- `verify-phase4-auth.mjs` — Auth, session, and permission contracts
- `verify-phase5-csp.mjs` — CSP hardening (no inline scripts) contracts
- `verify-phase6-a11y.mjs` — Accessibility (ARIA, focus trap, reduced-motion) contracts
- `verify-phase7-security.mjs` — Security hardening (atomic registration, no hash leak, cache locks) contracts
- `verify-phase8-bruteforce.mjs` — Brute-force lockout and timing oracle contracts
- `verify-phase9-pantum.mjs` — Pantum proprietary SNMP OID contracts
- `verify-phase10-simplify.mjs` — Database.php query helper + line count contracts
- `security-scanner.mjs` — Dynamic OWASP WSTG-based HTTP attack scanner (requires Docker)

## Local Contracts

- All verifiers are Node.js ES modules (`.mjs`) using the global `fetch` API (Node 18+).
- Static verifiers read source files as text and assert on patterns — they do NOT execute PHP, connect to databases, or probe SNMP.
- The dynamic security scanner (`security-scanner.mjs`) sends real HTTP requests and requires the Docker app to be running.
- Every verifier exits with code 0 on success, 1 on failure.
- Verifier output must be human-readable: PASS/FAIL per assertion, not just a bare exit code.
- Static verifiers should also run Phase N-1 regression checks to catch cross-phase regressions.
- New verifiers follow the naming convention: `verify-phase<N>-<short-name>.mjs`.

## Work Guidance

- When adding a new phase, write the verifier FIRST (RED state — it fails because the code isn't there yet), then implement until it passes (GREEN), then update DOX.
- Do not remove assertions from existing verifiers — add more. Regression coverage accumulates.
- If a verifier assertion becomes outdated (e.g., a function was intentionally renamed), update the assertion AND the DOX together.

## Verification

```sh
node tools/verify-phase1.mjs
node tools/verify-phase2.mjs
node tools/verify-phase3.mjs
node tools/verify-phase4-auth.mjs
node tools/verify-phase5-csp.mjs
node tools/verify-phase6-a11y.mjs
node tools/verify-phase7-security.mjs
node tools/verify-phase8-bruteforce.mjs
node tools/verify-phase9-pantum.mjs
```

Or run all at once:

```sh
for f in tools/verify-phase*.mjs; do node "$f" || break; done
```

The dynamic scanner requires Docker:

```sh
docker compose up -d
node tools/security-scanner.mjs
```

## Child DOX Index

No child DOX files currently. Add one only if a tools subfolder becomes a durable boundary with its own rules.
