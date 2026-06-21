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

const discovery = read('src/PrinterDiscovery.php');
const srcAgents = read('src/AGENTS.md');
const researchDoc = read('docs/research-backed-fixes.md');

// Pantum proprietary OIDs (enterprise 40093).
assert(discovery.includes('getPantumConsumableLevels'), 'PrinterDiscovery should have a dedicated getPantumConsumableLevels() method using Pantum enterprise OIDs.');
assert(discovery.includes('40093'), 'PrinterDiscovery should use Pantum enterprise OID tree .1.3.6.1.4.1.40093.');
assert(discovery.includes('1.3.6.1.4.1.40093.1.1.3.13.2'), 'PrinterDiscovery should query Pantum proprietary toner percentage OID.');

// Pantum should be handled like Brother — try proprietary OIDs first, then fall back.
assert(discovery.includes("'PANTUM'") && discovery.includes('getPantumConsumableLevels'), 'getDirectSupplyLevels() should try Pantum proprietary OIDs before falling back to generic MIB walk.');

// Pantum serial number OID should be in getPrinterInfo or detectPrinter.
assert(discovery.includes('1.3.6.1.4.1.40093.1.1.1.5'), 'PrinterDiscovery should query Pantum proprietary serial number OID.');

// Pantum toner OID returns percentage directly (0-100), not current/max pair.
assert(discovery.includes('pantum') === false || discovery.match(/pantum[\s\S]{0,500}percentage/i) !== null, 'Pantum toner handling should recognize that the proprietary OID returns percentage directly.');

// Docs.
assert(srcAgents.includes('Pantum') || srcAgents.includes('pantum'), 'src/AGENTS.md should document Pantum OID contracts.');
assert(researchDoc.includes('Phase 9') || researchDoc.includes('Pantum'), 'research-backed-fixes.md should document Phase 9 Pantum SNMP fix.');

try {
  execFileSync('node', ['tools/verify-phase8-bruteforce.mjs'], { cwd: root, stdio: 'pipe' });
} catch (error) {
  failures.push(`Phase 8 verifier failed:\n${error.stdout?.toString() || ''}${error.stderr?.toString() || error.message}`);
}

if (failures.length > 0) {
  console.error(`Phase 9 Pantum verification failed (${failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log('Phase 9 Pantum verification passed.');
