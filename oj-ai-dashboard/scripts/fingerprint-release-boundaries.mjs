import crypto from 'node:crypto';
import { readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const defaultPlatformRoot = path.resolve(root, '..', 'oj-integration-rebuild', 'oj-platform');
const verifyIndex = process.argv.indexOf('--verify');
const platformIndex = process.argv.indexOf('--platform-root');
const manifestPath = path.resolve(
  root,
  verifyIndex >= 0 && process.argv[verifyIndex + 1]
    ? process.argv[verifyIndex + 1]
    : 'resources/contracts/release-boundary-fingerprint-v1.json'
);
const platformRoot = path.resolve(
  platformIndex >= 0 && process.argv[platformIndex + 1]
    ? process.argv[platformIndex + 1]
    : defaultPlatformRoot
);

async function collectFiles(base, relativePath, files) {
  const absolutePath = path.join(base, relativePath);
  const info = await stat(absolutePath);
  if (info.isFile()) {
    files.push(relativePath.replaceAll(path.sep, '/'));
    return;
  }

  const entries = await readdir(absolutePath, { withFileTypes: true });
  for (const entry of entries) {
    await collectFiles(base, path.join(relativePath, entry.name), files);
  }
}

async function phpFiles(base, inputs) {
  const files = [];
  for (const input of inputs) {
    await collectFiles(base, input, files);
  }
  return [...new Set(files.filter((file) => file.endsWith('.php')))].sort();
}

async function fingerprint(base, files) {
  const hash = crypto.createHash('sha256');
  for (const relativePath of files) {
    hash.update(relativePath, 'utf8');
    hash.update(Buffer.from([0]));
    hash.update(await readFile(path.join(base, relativePath)));
    hash.update(Buffer.from([0]));
  }
  return {
    file_count: files.length,
    files,
    sha256: hash.digest('hex'),
  };
}

const addonApiFiles = await phpFiles(root, ['src/Platform', 'src/Security/Addon_Capabilities.php']);
addonApiFiles.unshift('oj-ai-dashboard.php');

const schemaFiles = await phpFiles(root, ['src/Database']);
const restSupportFiles = await phpFiles(root, ['src/Rest']);
const runtimeFiles = await phpFiles(root, ['src']);
const routeControllerFiles = [];
for (const relativePath of runtimeFiles) {
  const source = await readFile(path.join(root, relativePath), 'utf8');
  if (source.includes('register_rest_route')) routeControllerFiles.push(relativePath);
}
const routeFiles = [...new Set([...restSupportFiles, ...routeControllerFiles])].sort();
const platformApiFiles = await phpFiles(platformRoot, ['src/AddonApi/V1']);

const packageMetadata = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'));
const addonVersion = packageMetadata.version;
// Observe validation packages too; --verify still requires the exact approved manifest.
if (typeof addonVersion !== 'string' || !/^\d+\.\d+\.\d+(?:-rc\.\d+)?$/.test(addonVersion)) {
  throw new Error('Add-on package version could not be read.');
}
const addonEntrypoint = await readFile(path.join(root, 'oj-ai-dashboard.php'), 'utf8');
const addonHeaderVersion = addonEntrypoint.match(/^ \* Version:\s*([^\s]+)$/m)?.[1];
const addonConstantVersion = addonEntrypoint.match(/OJ_AI_DASHBOARD_VERSION',\s*'([^']+)'/)?.[1];
if (addonHeaderVersion !== addonVersion || addonConstantVersion !== addonVersion) {
  throw new Error('Add-on package, header, and runtime versions do not match.');
}

const platformEntrypoint = await readFile(path.join(platformRoot, 'oj-platform.php'), 'utf8');
const apiVersionMatch = platformEntrypoint.match(/OJ_PLATFORM_ADDON_API_VERSION',\s*'([^']+)'/);
if (!apiVersionMatch) throw new Error('OJ Platform Addon API version could not be read.');

const result = {
  contract: 'oj_ai.release_boundary_fingerprint@1',
  algorithm: 'sha256-path-nul-bytes-nul-v1',
  addon_version: addonVersion,
  platform_addon_api_version: apiVersionMatch[1],
  groups: {
    addon_api: await fingerprint(root, addonApiFiles),
    database_schema: await fingerprint(root, schemaFiles),
    rest_routes: await fingerprint(root, routeFiles),
    platform_addon_api: await fingerprint(platformRoot, platformApiFiles),
  },
};

if (verifyIndex >= 0) {
  const expected = JSON.parse(await readFile(manifestPath, 'utf8'));
  if (JSON.stringify(expected) !== JSON.stringify(result)) {
    console.error('Release boundary fingerprint drifted.');
    for (const group of Object.keys(result.groups)) {
      const wanted = expected.groups?.[group];
      const actual = result.groups[group];
      if (JSON.stringify(wanted) !== JSON.stringify(actual)) {
        console.error(`- ${group}: expected ${wanted?.file_count ?? '?'} files / ${wanted?.sha256 ?? '?'}`);
        console.error(`  actual   ${actual.file_count} files / ${actual.sha256}`);
      }
    }
    process.exit(1);
  }
  console.log(`Release boundary fingerprint verified: ${manifestPath}`);
} else {
  console.log(JSON.stringify(result, null, 2));
}
