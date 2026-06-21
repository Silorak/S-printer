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
const auth = read('src/Auth.php');
const js = read('src/script.js');
const modalsCss = read('src/css/modals.css');
const apache = read('apache-vhost.conf');
const srcAgents = read('src/AGENTS.md');
const projectAnalysis = read('docs/project-analysis.md');
const researchDoc = read('docs/research-backed-fixes.md');

// Inline JavaScript blockers.
assert(!/\son[a-z]+\s*=\s*["']/i.test(index), 'index.php should not render inline event-handler attributes such as onclick=.');
assert(!/<script>/.test(index), 'index.php should not render executable inline <script> blocks.');
assert(index.includes('id="sprinterBootstrap"') && index.includes('type="application/json"'), 'index.php should expose bootstrap data as inert JSON, not executable inline JS.');
assert(js.includes('sprinterBootstrap'), 'script.js should read the inert sprinterBootstrap JSON element.');
assert(js.includes('window.__SPRINTER_BOOTSTRAP__'), 'script.js should still populate the existing bootstrap object contract for apiFetch().');
assert(!index.includes('window.__SPRINTER_BOOTSTRAP__ ='), 'index.php should not assign window.__SPRINTER_BOOTSTRAP__ inline.');

// Printer action buttons must be delegated through script.js, not inline handlers.
assert(index.includes('data-action="delete-printer"'), 'delete printer buttons should expose data-action for delegated JS handling.');
assert(index.includes('data-action="edit-printer"'), 'edit printer buttons should expose data-action for delegated JS handling.');
assert(index.includes('data-printer-info='), 'edit printer buttons should carry escaped data-printer-info JSON.');
assert(js.includes('setupPrinterActions'), 'script.js should wire delegated printer action handlers.');
assert(/setupPrinterActions\(\);[\s\S]*setupForm\(\);/.test(js), 'printer action handlers should be wired during DOMContentLoaded initialization.');

// Inline style/script cleanup for DB loading path and hidden import file.
assert(!/<style>/.test(index), 'index.php should not render inline <style> blocks.');
assert(!/style\s*=\s*["']/i.test(index), 'index.php should not render inline style attributes.');
assert(index.includes('class="db-loading-page"') || index.includes("class='db-loading-page'"), 'database loading page should use a CSS class instead of an inline body style.');
assert(index.includes('class="visually-hidden"') || index.includes("class='visually-hidden'"), 'hidden file inputs should use a CSS utility class instead of inline style.');
assert(modalsCss.includes('db-loading-page') && modalsCss.includes('visually-hidden'), 'modals.css should define db-loading-page and visually-hidden utilities.');

// CSP headers: scripts should no longer require unsafe-inline.
for (const [name, content] of [['src/index.php', index], ['src/Auth.php', auth], ['apache-vhost.conf', apache]]) {
  assert(!/script-src[^;\n\r]*'unsafe-inline'/.test(content), `${name} CSP should not allow unsafe-inline scripts.`);
  assert(/script-src[^;\n\r]*'self'/.test(content), `${name} CSP should keep scripts restricted to self.`);
}

// Docs/contracts should reflect the Phase 5 CSP contract.
assert(srcAgents.includes('inert JSON') && srcAgents.includes('delegated event listeners'), 'src/AGENTS.md should document inert bootstrap and delegated event listener contracts.');
assert(projectAnalysis.includes('Phase 5') && projectAnalysis.includes('CSP'), 'project-analysis.md should document Phase 5 CSP status.');
assert(researchDoc.includes('Phase 5') && researchDoc.includes('CSP'), 'research-backed-fixes.md should document Phase 5 CSP status.');

try {
  execFileSync('node', ['tools/verify-phase4-auth.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 4 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

try {
  execFileSync('node', ['--check', path.join(root, 'src', 'script.js')], { stdio: 'pipe' });
} catch (error) {
  failures.push(`node --check src/script.js failed:\n${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 5 CSP verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 5 CSP verification passed.');
