import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');
const exists = rel => fs.existsSync(path.join(root, rel));

const index = read('src/index.php');
const api = read('src/api.php');
const js = read('src/script.js');
const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

// Phase 2 renderer ownership / duplication contracts.
assert(exists('src/Formatting.php'), 'src/Formatting.php should centralize display-format helpers.');
assert(index.includes("require_once 'Formatting.php';"), 'index.php should load shared formatting helpers.');
assert(api.includes("require_once 'Formatting.php';"), 'api.php should load shared formatting helpers.');
assert(!index.includes('function cleanSerialNumber('), 'index.php should not own duplicate serial cleanup logic.');
assert(!js.includes('function cleanSerialNumber('), 'script.js should not own duplicate serial cleanup logic.');
assert(api.includes('serial_number_display'), 'api.php should return serial_number_display for refreshed printer cards.');
assert(index.includes('cleanSerialNumberForDisplay'), 'index.php should use shared serial display helper.');

assert(!index.includes('function renderLevelsSectionHtml('), 'index.php should not own duplicate level rendering.');
assert(index.includes('data-initial-status'), 'index.php should expose initial level status as data for JS renderer.');
assert(index.includes('data-initial-levels'), 'index.php should expose initial levels as data for JS renderer.');
assert(index.includes('data-initial-last-levels'), 'index.php should expose last-known levels as data for JS renderer.');
assert(index.includes('window.__SPRINTER_BOOTSTRAP__'), 'index.php should bootstrap settings/floors instead of forcing startup fetches.');

assert(js.includes('hydrateInitialLevelSections'), 'script.js should hydrate initially rendered level sections.');
assert(/function renderLevelsSection\s*\(\s*levelsSection\s*,\s*status\s*,\s*levels\s*,\s*lastLevels\s*\)/.test(js),
  'script.js renderLevelsSection should render into a DOM element, not return HTML strings.');
assert(js.includes('replaceChildren'), 'script.js level rendering should replace children with DOM nodes.');
assert(!js.includes('levelsSection.innerHTML = renderLevelsSection'), 'refresh path should not inject level HTML strings.');
assert(!/function renderLevelsSection[\s\S]*return html;/.test(js), 'renderLevelsSection should not build and return HTML strings.');
assert(js.includes('window.__SPRINTER_BOOTSTRAP__'), 'script.js should read server bootstrap data.');
assert(!js.includes("Promise.all([\n            fetch('api.php?action=get_settings')"),
  'script.js should not always fetch settings/floors on startup when bootstrap data exists.');

try {
  execFileSync('node', ['tools/verify-phase1.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 1 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 2 verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 2 verification passed.');
