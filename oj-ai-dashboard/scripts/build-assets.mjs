import { mkdir, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const resourcesDir = path.join(root, 'resources');
const buildDir = path.join(root, 'build');

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
        const info = await stat(fullPath);
        files.push({
          path: path.relative(root, fullPath).replaceAll(path.sep, '/'),
          size: info.size,
        });
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
const builtJsFiles = await listFiles(path.join(resourcesDir, 'assets'), ['.js', '.mjs']);
const builtCssFiles = await listFiles(path.join(resourcesDir, 'assets'), ['.css']);

await mkdir(buildDir, { recursive: true });
await writeFile(
  path.join(buildDir, 'asset-manifest.json'),
  `${JSON.stringify({
    generatedAt: new Date().toISOString(),
    js: [...jsFiles, ...builtJsFiles],
    css: [...cssFiles, ...builtCssFiles],
  }, null, 2)}\n`,
  'utf8',
);

console.log(`Asset build completed: ${jsFiles.length + builtJsFiles.length} JS file(s), ${cssFiles.length + builtCssFiles.length} CSS file(s).`);
