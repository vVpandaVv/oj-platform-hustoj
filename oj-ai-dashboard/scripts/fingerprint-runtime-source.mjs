import crypto from 'node:crypto';
import { readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const addonRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const workspaceRoot = path.resolve(addonRoot, '..');
const platformRoot = path.join(workspaceRoot, 'oj-integration-rebuild', 'oj-platform');
const excludedDirectories = new Set(['node_modules', 'vendor']);

const targets = [
  {
    id: 'addon',
    root: addonRoot,
    inputs: ['oj-ai-dashboard.php', 'composer.json', 'package.json', 'config', 'src', 'modules', 'resources'],
  },
  {
    id: 'platform',
    root: platformRoot,
    inputs: ['oj-platform.php', 'composer.json', 'package.json', 'config', 'src', 'modules', 'resources'],
  },
];

async function collect(root, relativePath, files) {
  const absolutePath = path.join(root, relativePath);
  let info;
  try {
    info = await stat(absolutePath);
  } catch (error) {
    if (error.code === 'ENOENT') return;
    throw error;
  }

  if (info.isFile()) {
    files.push(relativePath.replaceAll(path.sep, '/'));
    return;
  }

  const entries = await readdir(absolutePath, { withFileTypes: true });
  for (const entry of entries) {
    if (entry.isDirectory() && excludedDirectories.has(entry.name)) continue;
    await collect(root, path.join(relativePath, entry.name), files);
  }
}

async function fingerprint(target) {
  const files = [];
  for (const input of target.inputs) {
    await collect(target.root, input, files);
  }
  files.sort();

  const hash = crypto.createHash('sha256');
  for (const relativePath of files) {
    hash.update(relativePath, 'utf8');
    hash.update(Buffer.from([0]));
    hash.update(await readFile(path.join(target.root, relativePath)));
    hash.update(Buffer.from([0]));
  }

  return {
    id: target.id,
    root: path.relative(workspaceRoot, target.root).replaceAll(path.sep, '/'),
    files: files.length,
    sha256: hash.digest('hex'),
  };
}

const result = {
  algorithm: 'sha256-path-nul-bytes-nul-v1',
  scope: 'entrypoint + package metadata + config/src/modules/resources; node_modules/vendor excluded',
  targets: [],
};

for (const target of targets) {
  result.targets.push(await fingerprint(target));
}

if (process.argv.includes('--json')) {
  console.log(JSON.stringify(result, null, 2));
} else {
  console.log(`${result.algorithm}: ${result.scope}`);
  for (const target of result.targets) {
    console.log(`${target.id}: ${target.files} files, ${target.sha256}`);
  }
}
