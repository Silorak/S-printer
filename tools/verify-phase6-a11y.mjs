import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

const index = read('src/index.php');
const js = read('src/script.js');
const baseCss = read('src/css/base.css');
const componentsCss = read('src/css/components.css');
const responsiveCss = read('src/css/responsive.css');
const modalsCss = read('src/css/modals.css');
const srcAgents = read('src/AGENTS.md');
const cssAgents = read('src/css/AGENTS.md');
const projectAnalysis = read('docs/project-analysis.md');
const researchDoc = read('docs/research-backed-fixes.md');

// Modal close controls must be buttons, not spans.
assert(!/<span[^>]*class="[^"]*close-modal/.test(index), 'Modal close controls should be <button>, not <span>.');
assert(/<button[^>]*close-modal/.test(index), 'Modal close controls should use <button> elements.');
assert(index.includes('aria-label="Close"'), 'Modal close buttons should have aria-label="Close".');

// Modal ARIA dialog semantics.
assert(index.includes('role="dialog"'), 'Modals should declare role="dialog".');
assert(index.includes('aria-modal="true"'), 'Modals should declare aria-modal="true".');
assert(index.includes('aria-labelledby'), 'Modals should reference their title via aria-labelledby.');

// Status indicator should have screen-reader text or ARIA label.
assert(index.includes('role="img"') && /status-indicator/.test(index), 'Status indicator should carry ARIA semantics or text.');

// Level bars should use progressbar role with ARIA values.
assert(js.includes('role'), 'script.js level renderer should assign role attributes.');
assert(js.includes('progressbar'), 'script.js level bars should use role="progressbar".');
assert(js.includes('aria-valuenow'), 'script.js level bars should expose aria-valuenow.');
assert(js.includes('aria-valuemin'), 'script.js level bars should expose aria-valuemin.');
assert(js.includes('aria-valuemax'), 'script.js level bars should expose aria-valuemax.');

// Focus trap and Escape-to-close for modals.
assert(js.includes('setupFocusTrap') || js.includes('trapFocus') || js.includes('focusTrap'), 'script.js should implement a focus trap for modals.');

// prefers-reduced-motion CSS handling.
const allCss = baseCss + componentsCss + responsiveCss + modalsCss;
assert(allCss.includes('prefers-reduced-motion'), 'CSS should include prefers-reduced-motion media query.');

// Auto-sort by lowest level.
assert(js.includes('sortCubesByLowestLevel') || js.includes('lowestLevel'), 'script.js should implement lowest-level-first sorting.');
assert(js.includes('data-min-level') || js.includes('minLevel'), 'script.js should track per-cube minimum level for sorting.');

// Docs should reflect Phase 6.
assert(srcAgents.includes('focus trap') || srcAgents.includes('role="dialog"'), 'src/AGENTS.md should document modal accessibility contracts.');
assert(projectAnalysis.includes('Phase 6') && projectAnalysis.includes('accessibility'), 'project-analysis.md should document Phase 6 accessibility status.');
assert(researchDoc.includes('Phase 6') && researchDoc.includes('accessibility'), 'research-backed-fixes.md should document Phase 6 accessibility status.');

try {
  execFileSync('node', ['tools/verify-phase5-csp.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 5 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 6 accessibility verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 6 accessibility verification passed.');
