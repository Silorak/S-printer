# S-printer Analysis and Research Docs DOX

## Purpose

`docs/` contains durable analysis documents, security fix logs, and architecture narrative. These are not source code — they are project knowledge artifacts that track what was done, why, and what remains.

## Ownership

This document owns:

- `project-analysis.md` — Architecture analysis, security status by phase, verification status, and remaining work.
- `research-backed-fixes.md` — Detailed fix log with OWASP/PHP/MariaDB/W3C references per fix. Organized by phase.

## Local Contracts

- All fix entries must cite the authoritative source used (OWASP cheat sheet, PHP manual, MySQL/MariaDB KB, W3C spec, real-world open-source project).
- Phase status sections must be updated when a phase is completed or when a new phase starts.
- "Remaining work" or "known issues" sections must be kept current — do not list items that have been fixed.
- Architecture narrative lives in `architecture.md` / `architektura.md` (root level), not here. This folder is for operational analysis, not thesis text.

## Work Guidance

- After completing a phase, update both `project-analysis.md` (status) and `research-backed-fixes.md` (detailed fix entry with sources).
- When researching a fix, record the source URL and the key finding, not just "looked it up".
- Distinguish between "fixed" items and "documented but not yet fixed" items explicitly.

## Verification

No automated verification for docs content. Manual review: ensure phase status sections match actual verifier results (`node tools/verify-phase*.mjs`).

## Child DOX Index

No child DOX files currently. Add one only if a docs subfolder becomes a durable boundary with its own rules.
