import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const resourcesDir = path.join(root, 'resources');
const errors = [];

async function listFiles(dir, extensions) {
  try {
    const entries = await readdir(dir, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
      const fullPath = path.join(dir, entry.name);

      if (entry.isDirectory()) {
        files.push(...await listFiles(fullPath, extensions));
        continue;
      }

      if (extensions.includes(path.extname(entry.name))) {
        files.push(fullPath);
      }
    }

    return files;
  } catch (error) {
    if (error.code === 'ENOENT') {
      return [];
    }
    throw error;
  }
}

const jsFiles = await listFiles(path.join(resourcesDir, 'js'), ['.js', '.mjs']);
const cssFiles = await listFiles(path.join(resourcesDir, 'css'), ['.css']);

for (const file of jsFiles) {
  const result = spawnSync(process.execPath, ['--check', file], { encoding: 'utf8' });
  if (result.status !== 0) {
    errors.push(`JS syntax check failed: ${path.relative(root, file)}\n${result.stderr || result.stdout}`);
  } else {
    console.log(`[OK] JS syntax: ${path.relative(root, file)}`);
  }
}

for (const file of cssFiles) {
  const source = await readFile(file, 'utf8');
  const opens = (source.match(/{/g) || []).length;
  const closes = (source.match(/}/g) || []).length;

  if (opens !== closes) {
    errors.push(`CSS brace check failed: ${path.relative(root, file)}`);
  } else {
    console.log(`[OK] CSS brace check: ${path.relative(root, file)}`);
  }
}

if (errors.length > 0) {
  for (const error of errors) {
    console.error(error);
  }
  process.exit(1);
}

console.log(`Asset lint passed: ${jsFiles.length} JS file(s), ${cssFiles.length} CSS file(s).`);
