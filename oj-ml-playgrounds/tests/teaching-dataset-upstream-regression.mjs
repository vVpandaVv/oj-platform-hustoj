import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInThisContext } from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
const php = process.env.OJ_ML_TEST_PHP;
assert(frontend && php, 'Set OJ_ML_UPSTREAM_FRONTEND and OJ_ML_TEST_PHP to installed local test runtimes.');
const requirePackage = createRequire(path.join(frontend, 'package.json'));
const ts = requirePackage('typescript');
const modules = new Map();
// Compile the ACTUAL upstream modules, not a reimplementation of PapaParse or its options.
function sourceModule(filename) {
  const absolute = path.resolve(filename);
  if (modules.has(absolute)) return modules.get(absolute).exports;
  assert(absolute.startsWith(path.join(frontend, 'src') + path.sep), 'Only local upstream source modules are loaded.');
  const module = { exports: {} };
  modules.set(absolute, module);
  const compiled = ts.transpileModule(readFileSync(absolute, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
  }).outputText;
  const localRequire = specifier => specifier.startsWith('.')
    ? sourceModule(path.resolve(path.dirname(absolute), specifier + '.ts'))
    : requirePackage(specifier);
  runInThisContext('(function(require,module,exports){' + compiled + '\n})', { filename: absolute })(localRequire, module, module.exports);
  return module.exports;
}
const { parseCsvText } = sourceModule(path.join(frontend, 'src/data/csv.ts'));
const { toCanonicalCsv } = sourceModule(path.join(frontend, 'src/data/serialize.ts'));
const fixtures = spawnSync(php, [path.join(here, 'teaching-dataset-canonical-fixtures.php')], { encoding: 'utf8' });
assert.equal(fixtures.status, 0, fixtures.stderr || fixtures.stdout);
const cases = JSON.parse(fixtures.stdout);
for (const fixture of cases) {
  const bytes = Buffer.from(fixture.base64, 'base64');
  const grid = parseCsvText(new TextDecoder().decode(bytes));
  const canonical = Buffer.from(toCanonicalCsv(grid));
  assert.deepEqual(canonical, bytes, 'Actual upstream CSV byte roundtrip: ' + fixture.index);
  assert.equal(createHash('sha256').update(canonical).digest('hex'), fixture.sha256);
  assert.equal(grid.length - 1, fixture.rowCount);
  assert.equal(grid[0].length, fixture.columnCount);
}
console.log('Teaching dataset actual upstream roundtrip: ' + (cases.length * 4) + ' checks passed (' + cases.length + ' fixtures).');
