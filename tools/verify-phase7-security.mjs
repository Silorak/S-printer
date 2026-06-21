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

const api = read('src/api.php');
const auth = read('src/Auth.php');
const db = read('src/Database.php');
const cache = read('src/CacheManager.php');
const srcAgents = read('src/AGENTS.md');
const projectAnalysis = read('docs/project-analysis.md');
const researchDoc = read('docs/research-backed-fixes.md');

// H1: First-admin registration must be atomic (TOCTOU-safe).
assert(db.includes('createFirstAdminAtomic') || db.includes('FOR UPDATE'), 'Database should provide atomic first-admin creation using SELECT ... FOR UPDATE or equivalent.');
assert(!/hasUsers\(\)[\s\S]{0,80}createUser\(/.test(auth), 'Auth.php should not use non-atomic check-then-create for first-admin registration.');

// H2: getUsers() must not use SELECT * (which leaks password_hash).
assert(!/SELECT \* FROM users ORDER BY/.test(db), 'Database::getUsers() should not use SELECT * — must exclude password_hash.');
assert(db.includes('password_hash') === false || db.match(/SELECT[^;]*FROM users/g)?.some(m => !m.includes('password_hash')) === true, 'getUsers() query must exclude password_hash column.');

// M2: Import error must not leak raw DB internals.
assert(!/sendError\('Import failed: ' \. \$e->getMessage/.test(api), 'Import error path should not expose raw exception messages to clients.');
assert(api.includes('error_log') && /import/i.test(api), 'Import error should be logged server-side with a generic client message.');

// M3: API must send Cache-Control: no-store.
assert(api.includes('Cache-Control') && api.includes('no-store'), 'API responses should include Cache-Control: no-store to prevent caching of authenticated data.');

// M5: Outer handler must catch Throwable, not just Exception.
assert(/catch \(Throwable/.test(api), 'API outer exception handler should catch Throwable, not just Exception.');
assert(!/catch \(Exception \$e\) \{\s*\n\s*\/\/ Log detailed error server-side.*An unexpected error occurred/s.test(api), 'API outer handler should catch Throwable, not Exception.');

// M7: Cache file writes must use LOCK_EX.
assert(cache.includes('LOCK_EX'), 'CacheManager::set() should use LOCK_EX to prevent corrupt-file race.');

// M8: Cache directory permissions should not be 0777.
assert(!cache.includes('0777'), 'CacheManager should not create cache directory with world-writable 0777 permissions.');

// Docs should reflect Phase 7 security hardening.
assert(srcAgents.includes('atomic first-admin') || srcAgents.includes('FOR UPDATE'), 'src/AGENTS.md should document atomic first-admin registration.');
assert(projectAnalysis.includes('Phase 7') || projectAnalysis.includes('security hardening'), 'project-analysis.md should document Phase 7 security hardening.');
assert(researchDoc.includes('Phase 7') || researchDoc.includes('security hardening'), 'research-backed-fixes.md should document Phase 7 security hardening.');

try {
  execFileSync('node', ['tools/verify-phase6-a11y.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 6 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 7 security verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 7 security verification passed.');
