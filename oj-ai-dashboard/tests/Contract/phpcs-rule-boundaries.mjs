import assert from 'node:assert/strict';
import { copyFile, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const temp = await mkdtemp(path.join(os.tmpdir(), 'ojai-phpcs-boundaries-'));
// An ancestor named tests must not disable security rules in the fixture's src.
const fixture = path.join(temp, 'tests', 'checkout');
const files = new Map();
async function source(relative, content) {
  const target = path.join(fixture, relative);
  await mkdir(path.dirname(target), { recursive: true });
  await writeFile(target, `<?php\n${content}\n`);
  files.set(relative, target);
}
try {
  for (const relative of [
    'phpcs.xml.dist', 'scripts/phpcs/bootstrap.php',
    'scripts/phpcs/OJAI/Sniffs/Files/AutoloadPathSniff.php',
    'scripts/phpcs/OJAI/Sniffs/Commenting/ThrowsContractSniff.php',
    'scripts/phpcs/OJAI/Sniffs/ControlStructures/LiteralComparisonSniff.php',
  ]) {
    const target = path.join(fixture, relative);
    await mkdir(path.dirname(target), { recursive: true });
    await copyFile(path.join(root, relative), target);
  }
  await source('src/Core/Example.php', 'namespace OJAI\\Dashboard\\Core;\nclass Example { public function value() { return [ self::class, new class {} ]; } }');
  await source('src/Core/Port.php', 'namespace OJAI\\Dashboard\\Core;\ninterface Port {}');
  await source('src/Core/Helper.php', 'namespace OJAI\\Dashboard\\Core;\ntrait Helper {}');
  await source('src/Core/State.php', 'namespace OJAI\\Dashboard\\Core;\nenum State { case Ready; }');
  await source('src/Core/wrong.php', 'namespace OJAI\\Dashboard\\Core;\nclass Wrong {}');
  await source('src/Core/Foreign.php', 'namespace Another;\nclass Foreign {}');
  await source('src/Core/Empty.php', 'namespace OJAI\\Dashboard\\Core;');
  await source('src/Core/Multiple.php', 'namespace OJAI\\Dashboard\\Core;\nclass Multiple {} class Extra {}');
  const unsafe = `$loose = $_GET['value'];
echo $loose;
set_error_handler( function () {} );
$fixture = file_get_contents( __FILE__ );
$database = new \\PDO( 'sqlite::memory:' );
$wpdb->get_results( "SELECT * FROM table_name WHERE id = '$loose'" );
throw new \\RuntimeException( $loose );`;
  await source('src/Core/Unsafe.php', `namespace OJAI\\Dashboard\\Core;\nclass Unsafe {}\n${unsafe}`);
  await source('tests/Contract/unsafe.php', unsafe);
  await source('misc/Bad_Name.php', 'class Unscoped {}');
  await source('tests/Contract/documented-throws.php', '/** Failure boundary. @throws \\Throwable Validation or dependency failure. */\nfunction documented_failure() { throw new \\RuntimeException(); }');
  await source('tests/Contract/missing-throws.php', '/** Failure boundary. */\nfunction missing_failure() { throw new \\RuntimeException(); }');
  await source('tests/Contract/deferred-throws.php', '/** Register deferred work. */\nfunction deferred_failure() { return function () { throw new \\RuntimeException(); }; }');
  await source('tests/Contract/literal-comparison.php', '$value = 1; if ($value === null) {}');
  await source('tests/Contract/evaluated-comparison.php', '$values = array(); if ($values[0] === count($values)) {}');
  const result = spawnSync(process.env.OJAI_PHP || 'php', [
    path.join(root, 'vendor/squizlabs/php_codesniffer/bin/phpcs'),
    '-q', '--no-colors', `--standard=${path.join(fixture, 'phpcs.xml.dist')}`,
    '--report=json', ...files.values(),
  ], { cwd: temp, encoding: 'utf8', timeout: 120000, maxBuffer: 8 * 1024 * 1024 });
  if (result.error) throw result.error;
  assert.ok([1, 2].includes(result.status), result.stdout + result.stderr);
  const report = JSON.parse(result.stdout);
  function codes(relative) {
    const entry = Object.entries(report.files).find(([file]) => path.resolve(file) === files.get(relative));
    assert.ok(entry, `Missing PHPCS evidence for ${relative}`);
    return entry[1].messages.map(message => message.source);
  }
  function detected(relative, prefix) {
    return codes(relative).some(code => code.startsWith(prefix));
  }
  for (const name of ['Example', 'Port', 'Helper', 'State']) {
    assert.equal(detected(`src/Core/${name}.php`, 'OJAI.Files.AutoloadPath.'), false, name);
    assert.equal(detected(`src/Core/${name}.php`, 'WordPress.Files.FileName.'), false, name);
  }
  for (const [name, diagnostic] of [
    ['wrong', 'PathMismatch'], ['Foreign', 'NamespaceMismatch'],
    ['Empty', 'DeclarationCount'], ['Multiple', 'DeclarationCount'],
  ]) {
    assert.ok(detected(`src/Core/${name}.php`, `OJAI.Files.AutoloadPath.${diagnostic}`), name);
  }
  assert.ok(detected('misc/Bad_Name.php', 'WordPress.Files.FileName.'));
  assert.equal(detected('tests/Contract/documented-throws.php', 'OJAI.Commenting.ThrowsContract.'), false);
  assert.ok(detected('tests/Contract/missing-throws.php', 'OJAI.Commenting.ThrowsContract.Missing'));
  assert.equal(detected('tests/Contract/deferred-throws.php', 'OJAI.Commenting.ThrowsContract.'), false);
  assert.ok(detected('tests/Contract/literal-comparison.php', 'OJAI.ControlStructures.LiteralComparison.LiteralOnRight'));
  assert.equal(detected('tests/Contract/evaluated-comparison.php', 'OJAI.ControlStructures.LiteralComparison.'), false);
  for (const diagnostic of [
    'WordPress.NamingConventions.PrefixAllGlobals.',
    'WordPress.Security.EscapeOutput.ExceptionNotEscaped',
    'WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler',
    'WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents',
    'WordPress.DB.RestrictedClasses.mysql__PDO',
  ]) {
    assert.ok(detected('src/Core/Unsafe.php', diagnostic), `Runtime rule lost: ${diagnostic}`);
    assert.equal(detected('tests/Contract/unsafe.php', diagnostic), false, `CLI exception missing: ${diagnostic}`);
  }
  for (const relative of ['src/Core/Unsafe.php', 'tests/Contract/unsafe.php']) {
    assert.ok(detected(relative, 'WordPress.Security.EscapeOutput.OutputNotEscaped'), relative);
    assert.ok(detected(relative, 'WordPress.DB.PreparedSQL.'), relative);
  }
  console.log('PHPCS rule boundaries PASS: exact autoload paths, case, declaration types, CLI isolation, output and SQL protections.');
} finally {
  assert.equal(path.dirname(temp), path.resolve(os.tmpdir()));
  assert.ok(path.basename(temp).startsWith('ojai-phpcs-boundaries-'));
  await rm(temp, { recursive: true, force: true });
}
