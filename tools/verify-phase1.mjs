import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const api = fs.readFileSync(path.join(root, 'src', 'api.php'), 'utf8');
const js = fs.readFileSync(path.join(root, 'src', 'script.js'), 'utf8');

const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

// PHP/API Phase 1 hardening contracts.
assert(api.includes('MAX_JSON_BODY_BYTES'), 'api.php should define a JSON body size limit.');
assert(api.includes('readJsonInput'), 'api.php should centralize JSON input parsing.');
assert(api.includes('validateIpOrFail'), 'api.php should centralize IP validation.');
assert(api.includes('sanitizePrinterPayload'), 'api.php should sanitize printer payloads before DB writes/import.');
assert(api.includes('sanitizePurchaseUrl'), 'api.php should validate and restrict purchase_url values.');
assert(api.includes("getenv('SNMP_COMMUNITY')"), 'api.php should pass SNMP_COMMUNITY into PrinterDiscovery.');

// JS correctness / safety contracts.
const settingsAssignIndex = js.indexOf('window.appSettings = {', js.indexOf('async function saveDisplaySettings'));
const applyIndex = js.indexOf('applyDisplaySettings();', js.indexOf('async function saveDisplaySettings'));
assert(settingsAssignIndex !== -1 && applyIndex !== -1 && settingsAssignIndex < applyIndex,
  'saveDisplaySettings should update window.appSettings before applyDisplaySettings().');
assert(/async function deletePrinter[\s\S]*confirm\(/.test(js), 'deletePrinter should confirm before destructive delete.');
assert(/delete-floor-btn[\s\S]*confirm\(/.test(js), 'Floor deletion should confirm before destructive delete.');
assert(js.includes('getActiveFloorId'), 'script.js should centralize active-floor lookup for filter/search consistency.');
assert(js.includes('matchesActiveFloor'), 'script.js should filter search results by active floor.');
assert(!js.includes('li.innerHTML = `\n                        <input type="text" class="floor-name-input" value="${escapeHtml(name)}">'),
  'New floor insertion should not use innerHTML with user-controlled floor names.');
assert(js.includes('MAX_IMPORT_FILE_BYTES'), 'script.js should enforce a client-side import file size limit.');
assert(js.includes('isRefreshingAllPrinters'), 'refreshAllPrinters should guard against overlapping refreshes.');

// Existing API action parity contract.
const apiActions = new Set([...api.matchAll(/case '([^']+)'/g)].map(match => match[1]));
const jsActions = new Set([...js.matchAll(/api\.php\?action=([a-z_]+)/g)].map(match => match[1]));
jsActions.add('add_printer');
jsActions.add('edit_printer');
for (const action of jsActions) {
  assert(apiActions.has(action), `JS calls api.php?action=${action}, but api.php has no matching case.`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 1 verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 1 verification passed.');
