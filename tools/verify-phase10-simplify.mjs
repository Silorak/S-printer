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

const db = read('src/Database.php');

// Phase 10: The query helper must exist
assert(db.includes('private function run('), 'Database should have a private run() helper that wraps try/prepare/execute/catch.');
assert(db.includes('private function fetchAll('), 'Database should have a private fetchAll() helper for SELECT queries.');
assert(db.includes('private function fetchOne('), 'Database should have a private fetchOne() helper for single-row SELECT queries.');

// CRUD methods should use the helpers, not inline try/catch
const tryCatchCount = (db.match(/catch\s*\(\s*\\PDOException/g) || []).length;
assert(tryCatchCount <= 5, `Database should have at most 5 inline try/catch blocks (run() helper, constructor, initSchema, createFirstAdminAtomic, recordFailedLogin). Found: ${tryCatchCount}. All other methods should use the run/fetchAll/fetchOne helpers.`);

// All public methods should still exist (no methods lost in refactor)
const requiredMethods = [
    'getPrinters', 'addPrinter', 'updatePrinter', 'updatePrinterFully',
    'hasLevelsChanged', 'updatePrinterLevelsIfChanged', 'updatePrinterLevels',
    'deletePrinter', 'getPrinterByIp', 'getFloors', 'addFloor', 'updateFloor',
    'deleteFloor', 'getSettings', 'getSetting', 'setSetting', 'hasUsers',
    'getUserByUsername', 'getUserById', 'getUsers', 'createFirstAdminAtomic',
    'createUser', 'recordFailedLogin', 'resetFailedLogin', 'isUserLocked',
    'updateUserPermissions', 'updateUserPasswordHash', 'recordUserLogin',
    'normalizeForJsonComparison', 'initSchema', 'getConnection', 'getLastError',
    'beginTransaction', 'commit', 'rollBack'
];
for (const method of requiredMethods) {
    assert(db.includes(`function ${method}(`), `Database must still have method: ${method}()`);
}

// Line count should be reduced
const lineCount = db.split('\n').length;
assert(lineCount <= 600, `Database.php should be under 600 lines after refactor (was 799). Currently: ${lineCount}`);

// Regression: run Phase 9
try {
    execFileSync('node', ['tools/verify-phase9-pantum.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
    failures.push(`Phase 9 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
    console.error(`Phase 10 simplification verification failed (${failures.length}):`);
    for (const failure of failures) console.error(`- ${failure}`);
    process.exit(1);
}

console.log('Phase 10 simplification verification passed.');
