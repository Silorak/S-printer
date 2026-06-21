import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');
const exists = rel => fs.existsSync(path.join(root, rel));

const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

const auth = exists('src/Auth.php') ? read('src/Auth.php') : '';
const db = read('src/Database.php');
const api = read('src/api.php');
const index = read('src/index.php');
const js = read('src/script.js');
const componentsCss = read('src/css/components.css');
const modalsCss = read('src/css/modals.css');
const projectAnalysis = read('docs/project-analysis.md');
const researchDoc = read('docs/research-backed-fixes.md');
const srcAgents = read('src/AGENTS.md');

// Auth/session/password/CSRF helper contracts.
assert(exists('src/Auth.php'), 'Auth.php should exist as the central auth/session helper.');
assert(auth.includes('declare(strict_types=1);'), 'Auth.php should use strict types.');
assert(auth.includes('sprinterStartSecureSession'), 'Auth.php should centralize hardened PHP session startup.');
assert(auth.includes('session_set_cookie_params'), 'Session startup should set cookie parameters.');
assert(auth.includes("'httponly' => true") || auth.includes('"httponly" => true'), 'Session cookies should be HttpOnly.');
assert(auth.includes("'samesite' => 'Lax'") || auth.includes('"samesite" => "Lax"'), 'Session cookies should use SameSite=Lax.');
assert(auth.includes('session_regenerate_id'), 'Login/register should regenerate session IDs.');
assert(auth.includes('password_hash'), 'Registration/user creation should use password_hash().');
assert(auth.includes('password_verify'), 'Login should use password_verify().');
assert(auth.includes('PASSWORD_ARGON2ID') || auth.includes('PASSWORD_DEFAULT'), 'Password hashing should use PHP password API algorithms.');
assert(auth.includes('random_bytes'), 'CSRF/session secrets should be generated with random_bytes().');
assert(auth.includes('hash_equals'), 'CSRF token validation should use hash_equals().');
assert(auth.includes('sprinterRequireCsrfToken'), 'Auth.php should expose CSRF validation.');
assert(auth.includes('sprinterRequirePermission'), 'Auth.php should expose permission enforcement.');
assert(auth.includes('SPRINTER_PERMISSIONS'), 'Auth.php should define the supported permission keys.');

// Database user/schema contracts.
assert(db.includes('CREATE TABLE IF NOT EXISTS users'), 'Database schema should create users table.');
assert(db.includes('password_hash VARCHAR(255)'), 'users table should store password_hash VARCHAR(255).');
assert(db.includes('is_admin TINYINT(1)'), 'users table should persist main-admin flag.');
assert(db.includes('can_add_printer') && db.includes('can_edit_printer') && db.includes('can_delete_printer'), 'users table should persist printer permissions.');
assert(db.includes('can_manage_floors') && db.includes('can_manage_settings') && db.includes('can_import_export'), 'users table should persist management permissions.');
assert(db.includes('is_active TINYINT(1)'), 'users table should support disabling users.');
assert(db.includes('hasUsers'), 'Database should expose hasUsers().');
assert(db.includes('createUser'), 'Database should expose createUser().');
assert(db.includes('getUserByUsername'), 'Database should expose getUserByUsername().');
assert(db.includes('getUserById'), 'Database should expose getUserById().');
assert(db.includes('getUsers'), 'Database should expose getUsers().');
assert(db.includes('updateUserPermissions'), 'Database should expose updateUserPermissions().');
assert(db.includes('recordUserLogin'), 'Database should record successful login timestamps.');

// Page routing/bootstrap contracts.
assert(index.includes("require_once 'Auth.php'"), 'index.php should load Auth.php.');
assert(index.includes('sprinterRenderAuthPage'), 'index.php should render first-admin registration/login pages.');
assert(index.includes('sprinterHandleAuthPost'), 'index.php should handle register/login/logout form posts.');
assert(index.includes('!$db->hasUsers()'), 'index.php should force first-admin registration only when no user exists.');
assert(index.includes('sprinterGetCurrentUser'), 'index.php should resolve current authenticated user.');
assert(index.includes('csrfToken'), 'index.php bootstrap should include CSRF token.');
assert(index.includes('currentUser'), 'index.php bootstrap should include current user metadata.');
assert(index.includes('currentPermissions'), 'index.php should compute current permissions for UI gating.');
assert(index.includes('usersTab'), 'settings modal should include a user management tab for the main admin.');
assert(index.includes('userCreateForm'), 'settings modal should include a user creation form.');
assert(index.includes('data-permission'), 'user management UI should expose permission toggles.');
assert(index.includes('can_import_export') && index.includes('can_manage_settings'), 'index.php should gate privileged UI with permissions.');
assert(index.includes('logoutForm'), 'index.php should expose logout.');
assert(index.includes('accountMenu') && index.includes('accountDropdown'), 'index.php should expose compact account menu markup.');
assert(index.includes('changePasswordForm'), 'index.php should expose own-password change form.');

// API authorization contracts.
assert(api.includes("require_once 'Auth.php'"), 'api.php should load Auth.php.');
assert(api.includes('sprinterRequireApiUser'), 'api.php should require an authenticated user before API actions.');
assert(api.includes('sprinterRequireCsrfToken'), 'api.php should validate CSRF tokens for API calls.');
assert(api.includes('sprinterRequirePermission($currentUser'), 'api.php should enforce permissions per action.');
assert(api.includes("case 'get_users'"), 'api.php should expose get_users for main admin UI.');
assert(api.includes("case 'create_user'"), 'api.php should expose create_user for main admin UI.');
assert(api.includes("case 'update_user_permissions'"), 'api.php should expose update_user_permissions for main admin UI.');
assert(api.includes("case 'change_own_password'"), 'api.php should expose change_own_password for current user password changes.');
assert(/case 'add_printer':[\s\S]*can_add_printer/.test(api), 'add_printer should require can_add_printer.');
assert(/case 'edit_printer':[\s\S]*can_edit_printer/.test(api), 'edit_printer should require can_edit_printer.');
assert(/case 'delete_printer':[\s\S]*can_delete_printer/.test(api), 'delete_printer should require can_delete_printer.');
assert(/case 'import_printers':[\s\S]*can_import_export/.test(api), 'import_printers should require can_import_export.');
assert(/case 'export_printers':[\s\S]*can_import_export/.test(api), 'export_printers should require can_import_export.');
assert(/case 'add_floor':[\s\S]*can_manage_floors/.test(api), 'add_floor should require can_manage_floors.');
assert(/case 'save_settings':[\s\S]*can_manage_settings/.test(api), 'save_settings should require can_manage_settings.');

// Browser contracts.
assert(js.includes('apiFetch'), 'script.js should centralize API fetches.');
assert(js.includes('X-CSRF-Token'), 'script.js should send CSRF tokens in API requests.');
assert(js.includes('hasPermission'), 'script.js should expose permission checks for UI behavior.');
assert(js.includes('setupAccountMenu'), 'script.js should wire account menu before awaited initialization.');
assert(/setupAccountMenu\(\);[\s\S]*await initializeAppData\(\)/.test(js), 'account menu should be wired before awaited app-data initialization.');
assert(js.includes('setupUserManagement'), 'script.js should wire user management UI.');
assert(js.includes('create_user'), 'script.js should call create_user for admin-created basic users.');
assert(js.includes('update_user_permissions'), 'script.js should update user permission toggles.');
assert(js.includes('change_own_password'), 'script.js should call change_own_password from the account menu.');
assert(js.includes('setCsrfToken'), 'script.js should update the CSRF token after password rotation.');
assert(js.includes('input[name="csrf_token"]'), 'script.js should update hidden CSRF form fields after password rotation.');
assert(!/fetch\('api\.php|fetch\(`api\.php|fetch\("api\.php/.test(js), 'script.js should use apiFetch() instead of direct api.php fetch calls.');

// CSS/docs contracts.
assert(modalsCss.includes('auth-shell') && modalsCss.includes('user-management'), 'modals.css should style auth and user-management UI.');
assert(componentsCss.includes('account-dropdown') && componentsCss.includes('account-role-badge'), 'components.css should style account dropdown and role badge UI.');
assert(srcAgents.includes('Auth.php') && srcAgents.includes('users('), 'src/AGENTS.md should document auth helper and users schema.');
assert(projectAnalysis.includes('Authentication') && projectAnalysis.includes('first-admin'), 'project-analysis.md should document auth status and first-admin flow.');
assert(researchDoc.includes('Phase 4') && researchDoc.includes('Authentication'), 'research-backed-fixes.md should record auth research/phase.');

try {
  execFileSync('node', ['tools/verify-phase3.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 3 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 4 auth verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 4 auth verification passed.');
