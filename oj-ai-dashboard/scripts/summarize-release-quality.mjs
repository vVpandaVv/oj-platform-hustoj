import crypto from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function relativeSource(file, root) {
  const relative = path.relative(root, path.resolve(root, file)).replaceAll('\\', '/');
  if (!relative || relative.startsWith('../') || path.isAbsolute(relative)) {
    throw new Error(`Source is outside the project: ${file}`);
  }
  return relative;
}

function scopeOf(file) {
  return file.startsWith('tests/') ? 'tests' : file.startsWith('src/') || file === 'oj-ai-dashboard.php' ? 'runtime' : 'tooling';
}

function phpcsCategory(message) {
  if (message.source.startsWith('WordPress.Security.')) return 'security_review';
  if (message.source.startsWith('WordPress.DB.')) return 'database_review';
  if (message.fixable) return 'tool_fixable';
  if (/Commenting|NamingConventions|Files\.FileName/.test(message.source)) return 'documentation_naming';
  return 'manual_review';
}

function phpstanCategory(identifier) {
  if (identifier === 'parameterByRef.type') return 'reference_contract';
  if (identifier === 'match.unhandled') return 'match_exhaustiveness';
  if (identifier === 'return.void') return 'callback_contract';
  if (/alreadyNarrowedType|impossibleType|alwaysTrue|alwaysFalse|offset|elseUnreachable|unusedType|arrayValues\.list/.test(identifier)) return 'type_control_flow';
  return 'other_review';
}

function group(items, key) {
  const groups = new Map();
  for (const item of items) {
    const name = item[key];
    const entry = groups.get(name) ?? { name, count: 0, errors: 0, warnings: 0, fixable: 0, scopes: {}, files: new Set(), examples: [] };
    entry.count++;
    entry.errors += Number(item.severity === 'error');
    entry.warnings += Number(item.severity === 'warning');
    entry.fixable += Number(item.fixable);
    entry.scopes[item.scope] = (entry.scopes[item.scope] ?? 0) + 1;
    entry.files.add(item.file);
    if (entry.examples.length < 3) entry.examples.push({ file: item.file, line: item.line, message: item.message });
    groups.set(name, entry);
  }
  return [...groups.values()].map(entry => ({ ...entry, files: [...entry.files].sort() })).sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
}

export function summarizeReports(phpcs, phpstan, root = projectRoot) {
  for (const [name, report] of Object.entries({ phpcs, phpstan })) {
    if (!report || typeof report.files !== 'object' || report.files === null || Array.isArray(report.files)) {
      throw new Error(`Invalid ${name} report: missing files object.`);
    }
  }
  const items = [];
  for (const [file, report] of Object.entries(phpcs.files)) {
    const relative = relativeSource(file, root);
    if (!Array.isArray(report.messages)) throw new Error(`Missing PHPCS messages: ${relative}`);
    for (const message of report.messages) {
      if (!['ERROR', 'WARNING'].includes(message.type) || typeof message.source !== 'string' || !message.source || typeof message.message !== 'string' || !Number.isInteger(message.line) || message.line < 1) {
        throw new Error(`Invalid PHPCS message: ${relative}`);
      }
      items.push({ tool: 'phpcs', file: relative, scope: scopeOf(relative), line: message.line, column: message.column, rule: message.source, category: phpcsCategory(message), severity: message.type.toLowerCase(), fixable: message.fixable === true, message: message.message });
    }
  }
  const styleItems = items.filter(item => item.tool === 'phpcs');
  for (const type of ['errors', 'warnings']) {
    const actual = styleItems.filter(item => item.severity === type.slice(0, -1)).length;
    if (phpcs.totals?.[type] !== actual) throw new Error(`PHPCS ${type} totals do not match message records.`);
  }
  for (const [file, report] of Object.entries(phpstan.files)) {
    const relative = relativeSource(file, root);
    if (!Array.isArray(report.messages)) throw new Error(`Missing PHPStan messages: ${relative}`);
    for (const message of report.messages) {
      if (typeof message.identifier !== 'string' || !message.identifier || typeof message.message !== 'string' || !Number.isInteger(message.line) || message.line < 1) {
        throw new Error(`Invalid PHPStan message: ${relative}`);
      }
      items.push({ tool: 'phpstan', file: relative, scope: scopeOf(relative), line: message.line, rule: message.identifier, category: phpstanCategory(message.identifier), severity: 'error', fixable: false, message: message.message });
    }
  }
  const typeItems = items.filter(item => item.tool === 'phpstan');
  if (phpstan.totals?.file_errors !== typeItems.length) throw new Error('PHPStan totals do not match message records.');
  if (!Array.isArray(phpstan.errors) || phpstan.totals?.errors !== phpstan.errors.length) throw new Error('PHPStan global errors do not match totals.');
  const summarize = (toolItems, globalErrors = []) => ({
    diagnostics: toolItems.length,
    filesWithDiagnostics: new Set(toolItems.map(item => item.file)).size,
    globalErrors,
    byScope: group(toolItems, 'scope'),
    byCategory: group(toolItems, 'category'),
    byRule: group(toolItems, 'rule'),
    byFile: group(toolItems, 'file'),
  });
  return {
    schema: 'oj_ai.quality_triage.v1',
    productionApproved: false,
    interpretation: 'Diagnostic counts are not counts of runtime defects. Classification does not waive any release gate. Tool-fixable does not mean verified safe.',
    phpcs: summarize(styleItems),
    phpstan: { ...summarize(typeItems, phpstan.errors), findings: typeItems },
  };
}

export async function main(args) {
  if (args.length !== 3) throw new Error('Usage: node scripts/summarize-release-quality.mjs <phpcs.json> <phpstan.json> <output.json>');
  const [stylePath, typePath, outputPath] = args.map(file => path.resolve(projectRoot, file));
  const outputRelative = relativeSource(outputPath, projectRoot);
  for (const input of [stylePath, typePath]) relativeSource(input, projectRoot);
  if ([stylePath, typePath].includes(outputPath)) throw new Error('Summary output must not overwrite an input report.');
  const style = await readFile(stylePath);
  const types = await readFile(typePath);
  const result = summarizeReports(JSON.parse(style), JSON.parse(types));
  result.inputs = [stylePath, typePath].map((file, index) => ({
    file: relativeSource(file, projectRoot),
    sha256: crypto.createHash('sha256').update(index === 0 ? style : types).digest('hex'),
  }));
  result.generatedAt = new Date().toISOString();
  result.workspaceVersion = JSON.parse(await readFile(path.join(projectRoot, 'package.json'), 'utf8')).version;
  result.workspaceVersionIsNotInputProvenance = true;
  await writeFile(outputPath, `${JSON.stringify(result, null, 2)}\n`);
  console.log(JSON.stringify({ output: outputRelative, productionApproved: false, phpcs: result.phpcs.diagnostics, phpstan: result.phpstan.diagnostics }));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main(process.argv.slice(2));
}
