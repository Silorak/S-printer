# S-printer CSS DOX

## Purpose

`src/css/` is the presentation layer for S-printer: theme tokens, base reset, dashboard layout, printer cards, buttons, modals, settings UI, responsive behavior, and animations.

## Ownership

This document owns:

- `base.css` — fallbacks, theme variables, reset/base styles, light-theme overrides, focus rings.
- `layout.css` — header, search, main layout, sidebar, printer grid, refresh panel, empty/error/debug states.
- `components.css` — printer cards, status indicators, info blocks, level bars, action buttons, buy buttons, FAB, account dropdown, generic controls.
- `modals.css` — modal shell, printer form, settings modal, floor manager, toggles, form/button states.
- `responsive.css` — animations, responsive breakpoints, mobile layout, narrow viewport behavior, and deliberate late overrides.
- `responsive.css` also owns the `prefers-reduced-motion` media query that disables animations and transitions for users who request reduced motion.

## Local Contracts

- Preserve stylesheet load order from `index.php`:
  1. `css/base.css`
  2. `css/layout.css`
  3. `css/components.css`
  4. `css/modals.css`
  5. `css/responsive.css`
- `responsive.css` is loaded last and intentionally contains both media queries and late overrides for components such as `.theme-toggle` and `.fab`.
- CSS custom properties belong in `base.css`; prefer variables over repeated literal colors.
- Keep dark theme as the default under `:root` and light theme overrides under `.light-theme`.
- Important theme/custom properties include:
  - `--bg`, `--fg`, `--primary`, `--secondary`
  - `--cube-bg`, `--border`, `--input-bg`, `--text-secondary`
  - `--cyan`, `--magenta`, `--yellow`
  - `--ease`
- Class names are contracts with `index.php` and `script.js`; update all three layers if renaming.
- Preserve key visual-state classes:
  - `.light-theme`
  - `.loading-state`
  - `.offline-cube`
  - `.refreshing`
  - `.warning-level`
  - `.last-seen-levels`
  - `.hidden-by-filter`
  - `.visible-by-filter`
  - `.floor-empty-state`
  - `.search-no-results`
- Preserve status classes used by PHP/JS: `.online`, `.offline`, `.loading` on `.status-indicator`.
- Preserve level fill classes used by PHP/JS: `.black-fill`, `.cyan-fill`, `.magenta-fill`, `.yellow-fill`, plus any explicitly added color fill class.
- Preserve account menu classes used by PHP/JS: `.account-menu`, `.account-button`, `.account-dropdown`, `.account-role-badge`, `.account-menu-row`, and `.account-password-form`.
- Preserve utility classes used to avoid inline styles under CSP: `.db-loading-page` and `.visually-hidden`.
- Maintain stacking assumptions for sticky header, modal overlay/content, and floating action button.
- Maintain visible keyboard focus indicators and accessible contrast in both dark and light themes; text-field focus should stay compact and not create oversized surrounding boxes.
- Responsive breakpoints currently used and requiring checks are `1024px`, `768px`, `480px`, and `320px`.
- Dynamic level widths are data-driven by PHP/JS via inline `width` and `data-level`; CSS should not hardcode per-printer assumptions.

## Work Guidance

- Add component styles to the closest existing module rather than creating a new CSS file by default.
- Put global design tokens in `base.css`, structural layout in `layout.css`, reusable UI components in `components.css`, form/modal-specific rules in `modals.css`, and breakpoint/animation changes in `responsive.css`.
- When changing card markup, verify `.cube`, `.cube-bottom`, `.cube-footer`, `.levels-section`, `.status-indicator`, `.info-item`, and action button styling together.
- When changing modal markup, verify printer modal and settings modal; they share base modal styles but differ in layout and controls.
- Avoid weakening accessibility for animation-heavy states. Add `prefers-reduced-motion` handling when changing animations.
- Check both dark and light themes for each visible UI state touched by a change.
- When changing header/account UI, verify account dropdown open/close, logout, change-password form, settings button, and theme toggle immediately after login.

## Verification

After CSS changes, verify the relevant subset in browser:

- desktop dashboard layout
- 1024px, 768px, 480px, and 320px viewport widths
- dark/light theme toggle
- account dropdown with role badge, logout row, and change-password form
- printer card states: loading, online, offline, no data, last-known levels, warning/empty levels
- add/edit printer modal
- settings modal with floors and display tabs
- floor sidebar/pill layout and filter animations
- search no-results and floor empty states
- floating action button position and hit target
- keyboard focus visibility on buttons, inputs, selects, links, and modal controls

## Child DOX Index

No child DOX files currently. Add one only if a CSS subfolder becomes a durable boundary with its own rules.
