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

const auth = read('src/Auth.php');
const db = read('src/Database.php');
const api = read('src/api.php');
const srcAgents = read('src/AGENTS.md');
const projectAnalysis = read('docs/project-analysis.md');
const researchDoc = read('docs/research-backed-fixes.md');

// Schema: failed-attempt tracking columns must exist.
assert(db.includes('failed_attempts') && db.includes('locked_until'), 'Database schema should include failed_attempts and locked_until columns for brute-force protection.');

// DB methods for lockout management.
assert(db.includes('recordFailedLogin'), 'Database should expose recordFailedLogin() to increment failed attempt counters.');
assert(db.includes('resetFailedLogin'), 'Database should expose resetFailedLogin() to clear lockout state after successful login.');
assert(db.includes('isUserLocked'), 'Database should expose isUserLocked() to check lockout status before login.');

// Auth.php must enforce lockout in the login flow.
assert(auth.includes('MAX_LOGIN_ATTEMPTS') || auth.includes('SPRINTER_MAX_LOGIN_ATTEMPTS'), 'Auth.php should define a maximum login attempts constant.');
assert(auth.includes('isUserLocked'), 'Auth.php sprinterLogin() should check lockout status before attempting verification.');
assert(auth.includes('recordFailedLogin'), 'Auth.php should record failed login attempts to the database.');
assert(auth.includes('resetFailedLogin'), 'Auth.php should reset failed login state after successful authentication.');

// Timing oracle mitigation: dummy password_verify for nonexistent users.
assert(auth.includes('DUMMY') || auth.includes('dummy_hash') || auth.includes('dummyHash'), 'Auth.php should run a dummy password_verify for nonexistent users to prevent timing-based username enumeration.');

// Exponential backoff on lockout.
assert(auth.includes('LOCKOUT') && (auth.includes('pow') || auth.includes('* 2') || auth.includes('exponential')), 'Auth.php should use exponential backoff for lockout duration.');

// API: brute-force on API-level auth should not exist (API requires session cookie, not password), but check that the pattern is documented.
assert(srcAgents.includes('brute-force') || srcAgents.includes('lockout'), 'src/AGENTS.md should document brute-force protection contracts.');
assert(projectAnalysis.includes('Phase 8') || projectAnalysis.includes('brute-force'), 'project-analysis.md should document Phase 8 brute-force protection.');
assert(researchDoc.includes('Phase 8') || researchDoc.includes('brute-force'), 'research-backed-fixes.md should document Phase 8 brute-force protection.');

try {
  execFileSync('node', ['tools/verify-phase7-security.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 7 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 8 brute-force verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 8 brute-force verification passed.');
