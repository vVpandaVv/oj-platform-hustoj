import { spawn } from 'node:child_process';
import { readdir, readFile, writeFile, mkdir, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const [mode, targetArg = '.', label = 'phpcs-workers'] = process.argv.slice(2);
if (!['check', 'style', 'fix'].includes(mode) || !/^[a-z0-9-]+$/.test(label)) throw new Error('Use check|style|fix target label.');
const target = path.resolve(root, targetArg);
if (target !== root && !target.startsWith(root + path.sep)) throw new Error('Target outside checkout.');
if (mode === 'fix' && !target.startsWith(path.join(root, '.cache') + path.sep)) throw new Error('Format an isolated copy only.');
const output = path.join(root, '_notes/flow-quality-1.0', label);
await mkdir(output, { recursive: true });
const files = [path.join(target, 'oj-ai-dashboard.php')];
async function walk(dir) {
  for (const item of await readdir(dir, { withFileTypes: true })) {
    if (item.isSymbolicLink()) throw new Error('Unexpected source symlink.');
    if (item.isDirectory()) await walk(path.join(dir, item.name));
    else if (item.isFile() && item.name.endsWith('.php')) files.push(path.join(dir, item.name));
  }
}
await walk(path.join(target, 'src'));
await walk(path.join(target, 'tests'));
const weighted = [];
for (const file of files) weighted.push({ file, size: (await stat(file)).size });
weighted.sort((a, b) => b.size - a.size);
const batches = Array.from({ length: 8 }, () => ({ files: [], bytes: 0 }));
for (const { file, size } of weighted) {
  batches.sort((a, b) => a.bytes - b.bytes);
  batches[0].files.push(file);
  batches[0].bytes += size;
}
const results = await Promise.all(batches.map(async (batch, i) => {
  const list = path.join(output, `${i}.files.txt`);
  await writeFile(list, batch.files.join('\n'));
  const args = ['-d', 'memory_limit=768M', path.join(root, 'vendor/squizlabs/php_codesniffer/bin', mode === 'fix' ? 'phpcbf' : 'phpcs'),
    '-q', '--no-colors', `--standard=${path.join(target, 'phpcs.xml.dist')}`, `--file-list=${list}`];
  if (mode !== 'fix') args.push('--report=json');
  // Syntax is not fixable. Avoid spawning php -l for every intermediate formatter pass;
  // the final check still runs every sniff, including native syntax validation.
  if (mode !== 'check') args.push('--exclude=Generic.PHP.Syntax');
  const started = Date.now();
  const result = await new Promise((resolve, reject) => {
    const child = spawn(process.env.OJAI_PHP || 'D:\\php\\php.exe', args, { cwd: target, windowsHide: true });
    let stdout = '', stderr = '';
    child.stdout.setEncoding('utf8').on('data', data => { stdout += data; });
    child.stderr.setEncoding('utf8').on('data', data => { stderr += data; });
    child.on('error', reject);
    child.on('close', code => resolve({ code, stdout, stderr }));
  });
  await writeFile(path.join(output, `${i}.${mode !== 'fix' ? 'json' : 'log'}`), result.stdout);
  await writeFile(path.join(output, `${i}.stderr.log`), result.stderr);
  console.log(`Worker ${i}: ${batch.files.length} files, exit ${result.code}, ${Math.round((Date.now() - started) / 1000)}s`);
  return { ...result, count: batch.files.length };
}));
if (mode !== 'fix') {
  const combined = { verification: { mode, nativeSyntaxChecked: mode === 'check' }, totals: { errors: 0, warnings: 0, fixable: 0 }, files: {} };
  for (const result of results) {
    if (![0, 1, 2].includes(result.code) || result.stderr.trim()) throw new Error('PHPCS worker failed. Inspect stderr.');
    const report = JSON.parse(result.stdout);
    for (const key of Object.keys(combined.totals)) combined.totals[key] += report.totals[key];
    for (const [file, data] of Object.entries(report.files)) {
      if (combined.files[file]) throw new Error('Overlapping worker source.');
      combined.files[file] = data;
    }
  }
  if (Object.keys(combined.files).length !== files.length) throw new Error('Incomplete PHPCS coverage.');
  await writeFile(output + '.json', JSON.stringify(combined, null, 2));
  console.log(JSON.stringify({ files: files.length, ...combined.totals }));
  process.exitCode = combined.totals.errors || combined.totals.warnings ? 1 : 0;
} else {
  await writeFile(output + '.json', JSON.stringify(results.map(({ code, count, stderr }) => ({ code, count, stderr })), null, 2));
  if (results.some(result => ![0, 1, 2, 3].includes(result.code) || result.stderr.trim())) process.exitCode = 1;
}
