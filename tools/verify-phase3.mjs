import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const api = read('src/api.php');
const db = read('src/Database.php');
const js = read('src/script.js');
const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

// API/SNMP refresh efficiency contracts.
assert(api.includes('REFRESH_CACHE_TTL_SECONDS'), 'api.php should define a short refresh cache TTL.');
assert(api.includes('refresh_printer_'), 'refresh_printer should use per-printer refresh cache keys.');
assert(/new CacheManager\('cache',\s*REFRESH_CACHE_TTL_SECONDS\)/.test(api), 'refresh_printer should use CacheManager with the short refresh TTL.');
assert(/\$refreshCache->get\(\$cacheKey\)/.test(api), 'refresh_printer should read from per-printer cache before SNMP.');
assert(/\$refreshCache->set\(\$cacheKey,\s*\$data\)/.test(api), 'refresh_printer should cache fresh SNMP response data.');
assert(api.includes('updatePrinterLevelsIfChanged'), 'refresh_printer should skip DB level writes when levels are unchanged.');
assert(api.includes('invalidateRuntimeCache'), 'mutations should use centralized cache invalidation.');
assert(/function invalidateRuntimeCache[\s\S]*->clear\(\)/.test(api), 'cache invalidation should clear per-printer refresh caches.');

// Database unchanged-write skip contracts.
assert(db.includes('updatePrinterLevelsIfChanged'), 'Database.php should expose updatePrinterLevelsIfChanged().');
assert(db.includes('hasLevelsChanged'), 'Database.php should compare stored and new level JSON before writing.');
assert(db.includes('normalizeForJsonComparison'), 'Database.php should canonicalize level data before comparison.');
assert(/updatePrinterLevelsIfChanged[\s\S]*hasLevelsChanged/.test(db), 'updatePrinterLevelsIfChanged() should call hasLevelsChanged().');
assert(/hasLevelsChanged[\s\S]*json_decode/.test(db), 'hasLevelsChanged() should decode stored JSON before comparison.');

// Browser cancellation / refresh ordering contracts.
assert(js.includes('AbortController'), 'script.js should use AbortController for stale refresh cancellation.');
assert(js.includes('activeRefreshController'), 'script.js should track the active refresh controller.');
assert(js.includes('cancelActiveRefreshes'), 'script.js should centralize refresh cancellation.');
assert(/(?:fetch|apiFetch)\([^\n]+,\s*\{\s*signal\s*\}/.test(js), 'refresh fetch should receive an AbortSignal.');
assert(js.includes("e.name === 'AbortError'"), 'refreshPrinterCard should handle AbortError without marking printers offline.');
assert(js.includes('activeCardRefreshTokens'), 'script.js should guard stale per-card cleanup with refresh tokens.');
assert(js.includes('WeakMap'), 'per-card refresh tokens should not retain removed DOM nodes.');
assert(js.includes('sortCubesForRefresh'), 'refreshAllPrinters should sort printer cards before refreshing.');
assert(/sortCubesForRefresh[\s\S]*matchesActiveFloor/.test(js), 'refresh sorting should prioritize the active floor / visible cards.');

// DOM reuse contracts.
assert(js.includes('getLevelsRenderSignature'), 'level rendering should compute a signature to skip unchanged DOM work.');
assert(js.includes('stableStringify'), 'level render signatures should use stable serialization.');
assert(js.includes('updateLevelBar'), 'level renderer should update existing level-bar nodes when possible.');
assert(js.includes('syncLevelBars'), 'level renderer should synchronize existing level bars instead of always rebuilding.');
assert(js.includes('dataset.levelKey'), 'level bars should have stable keys for reuse.');
assert(!/function renderLevelsSection[\s\S]*levelsSection\.replaceChildren\(fragment\);[\s\S]*levelsSection\.replaceChildren\(fragment\);[\s\S]*levelsSection\.replaceChildren\(fragment\);/.test(js),
  'renderLevelsSection should not rebuild all render modes with unconditional replaceChildren(fragment).');

try {
  execFileSync('node', ['tools/verify-phase2.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 2 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 3 verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 3 verification passed.');
