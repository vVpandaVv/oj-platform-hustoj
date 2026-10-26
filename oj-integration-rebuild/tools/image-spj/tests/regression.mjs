import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdirSync, mkdtempSync, symlinkSync, existsSync, utimesSync, readdirSync, rmSync, chmodSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { performance } from 'node:perf_hooks';
import { signature, chunk, pixels, fillRect, png, envelope, drawing } from './png-fixtures.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const build = resolve(root, '../../build/image-spj');
const compiler = process.argv[2] ?? 'g++';
const testProfiles = process.platform !== 'win32' || process.argv.includes('--profiles');
const env = { ...process.env };
const pathKey = Object.keys(env).find((key) => key.toLowerCase() === 'path') ?? 'PATH';
if (compiler.includes('/') || compiler.includes('\\')) {
  env[pathKey] = `${dirname(compiler)}${process.platform === 'win32' ? ';' : ':'}${env[pathKey] ?? ''}`;
}
function execute(file, args, options = {}) {
  const result = spawnSync(file, args, { cwd: build, env, encoding: 'utf8', timeout: 120000, ...options });
  if (result.error) throw result.error;
  return result;
}
mkdirSync(build, { recursive: true });
const reportPath = join(build, 'test-results.json');
let passed = 0;
writeFileSync(reportPath, JSON.stringify({ status: 'running', platform: process.platform, compiler }));
process.on('uncaughtException', (error) => {
  writeFileSync(reportPath, JSON.stringify({ status: 'incomplete', passed,
    platform: process.platform, compiler, error: error.message, error_code: error.code ?? null,
    note: 'Do not interpret a compile success or partial run as full acceptance.' }, null, 2));
  console.error(error);
  process.exitCode = 1;
});
const generated = execute(process.execPath, [join(root, 'build.mjs')]);
assert.equal(generated.status, 0, generated.stderr);
const extension = process.platform === 'win32' ? '.exe' : '';
function compile(name, defines = []) {
  const executable = `${name}${extension}`;
  const result = execute(compiler, ['-std=c++17', '-O2', '-Wall', '-Wextra', '-Werror',
    ...defines, 'spj.cpp', '-o', executable]);
  assert.equal(result.status, 0, `Compilation failed: ${result.stdout}\n${result.stderr}`);
  console.log(`BUILD PASS ${name}`);
  return join(build, executable);
}
const normal = compile('spj');
const strict = testProfiles ? compile('spj-strict', ['-DOJ_IMAGE_COLOR_TOLERANCE=0']) : null;
const relaxed = testProfiles ? compile('spj-radius1', ['-DOJ_IMAGE_SPATIAL_RADIUS=1']) : null;
const fixtures = mkdtempSync(join(build, 'regression-'));
const original = drawing(), reference = png(64, 64, original);
let count = 0;
function check(name, expected, actual, code, reason, binary = normal) {
  const directory = join(fixtures, String(++count));
  mkdirSync(directory);
  writeFileSync(join(directory, 'case.in'), '');
  if (expected !== null) writeFileSync(join(directory, 'case.out'), expected);
  if (actual !== null) writeFileSync(join(directory, 'user.out'), actual);
  const result = execute(binary, ['case.in', 'case.out', 'user.out'], { cwd: directory, timeout: 10000 });
  assert.equal(result.status, code, `${name}: ${result.stderr}`);
  assert.match(result.stderr, reason, name);
  assert.equal(result.stdout, '', `${name}: diagnostics must not use stdout`);
  passed++;
  console.log(`PASS ${name}`);
  return directory;
}
check('identical binary PNG named .out', reference, reference, 0, / AC /);
check('different compression and metadata', reference, png(64, 64, original, { level: 0, metadata: true }), 0, / AC /);
check('RGB versus RGBA', reference, png(64, 64, original, { type: 2 }), 0, / AC /);
check('grayscale versus RGBA', reference, png(64, 64, original, { type: 0 }), 0, / AC /);
check('UTF-8 reference envelope', envelope(reference), reference, 0, / AC /);
check('CRLF reference envelope', Buffer.from(envelope(reference).toString().replace(/\n/g, '\r\n')), reference, 0, / AC /);
const transparent = Buffer.from(original);
for (let i = 0; i < transparent.length; i += 4) {
  if (transparent[i] === 255) { transparent[i] = 20; transparent[i + 1] = 90; transparent[i + 2] = 180; transparent[i + 3] = 0; }
}
check('transparent white-background equivalent', reference, png(64, 64, transparent), 0, / AC /);
const minor = Buffer.from(original);
for (let i = 0; i < minor.length; i += 4) if (minor[i] === 0) minor[i] = minor[i + 1] = minor[i + 2] = 16;
check('color tolerance boundary', reference, png(64, 64, minor), 0, / AC /);
const major = Buffer.from(minor);
for (let i = 0; i < major.length; i += 4) if (major[i] === 16) major[i] = 17;
check('color tolerance exceeded', reference, png(64, 64, major), 1, /pixels_differ/);
if (strict) check('strict profile rejects small color change', reference, png(64, 64, minor), 1, /pixels_differ/, strict);
const missing = Buffer.from(original);
fillRect(missing, 64, 16, 16, 32, 2, [255, 255, 255, 255]);
check('missing edge', reference, png(64, 64, missing), 1, /pixels_differ/);
const extra = Buffer.from(original);
fillRect(extra, 64, 8, 8, 1, 1, [0, 0, 0, 255]);
check('one extra pixel on white background', reference, png(64, 64, extra), 1, /pixels_differ/);
check('one missing pixel on white background', png(64, 64, extra), reference, 1, /pixels_differ/);
check('blank output image', reference, png(64, 64, pixels(64, 64)), 1, /pixels_differ/);
check('blank reference accepts blank output', png(64, 64, pixels(64, 64)), png(64, 64, pixels(64, 64)), 0, / AC /);
const red = Buffer.from(original);
for (let i = 0; i < red.length; i += 4) if (red[i] === 0) red[i] = 255;
check('wrong color', reference, png(64, 64, red), 1, /pixels_differ/);
const shifted = pixels(64, 64);
for (let y = 0; y < 64; y++) original.copy(shifted, (y * 64 + 1) * 4, y * 64 * 4, (y * 64 + 63) * 4);
check('default rejects one-pixel translation', reference, png(64, 64, shifted), 1, /pixels_differ/);
if (relaxed) {
  check('explicit radius1 permits one-pixel translation', reference, png(64, 64, shifted), 0, / AC /, relaxed);
  check('radius1 still rejects isolated extra mark', reference, png(64, 64, extra), 1, /pixels_differ/, relaxed);
}
check('different dimensions', reference, png(32, 32, drawing(32)), 1, /dimensions_mismatch/);
check('empty student stdout', reference, Buffer.alloc(0), 1, /output_file_size_limit_or_empty/);
check('missing student stdout', reference, null, 1, /output_/);
check('student printed text', reference, Buffer.from('Hello\n'), 1, /not_png/);
check('student printed before PNG', reference, Buffer.concat([Buffer.from('debug\n'), reference]), 1, /not_png/);
check('student printed after PNG', reference, Buffer.concat([reference, Buffer.from('\n')]), 1, /invalid_png_end/);
check('student cannot send reference text envelope', reference, envelope(reference), 1, /not_png/);
check('corrupt reference is checker error', Buffer.from('not a PNG'), reference, 3, /CHECKER_ERROR.*reference_/);
check('missing reference is checker error', null, reference, 3, /CHECKER_ERROR.*reference_/);
check('invalid base64 padding', Buffer.from('OJ_IMAGE_PNG_BASE64_V1\nAA=A\n'), reference, 3, /invalid_reference_base64/);
check('noncanonical base64 padding', Buffer.from('OJ_IMAGE_PNG_BASE64_V1\nAB==\n'), reference, 3, /invalid_reference_base64/);
check('truncated PNG', reference, reference.subarray(0, reference.length - 6), 1, /output_/);
const badCrc = Buffer.from(reference);
badCrc[badCrc.length - 1] ^= 1;
check('corrupt CRC', reference, badCrc, 1, /png_crc_error/);
const oversizedHeader = Buffer.alloc(13);
oversizedHeader.writeUInt32BE(99999, 0); oversizedHeader.writeUInt32BE(99999, 4);
oversizedHeader[8] = 8; oversizedHeader[9] = 6;
const oversized = Buffer.concat([signature, chunk('IHDR', oversizedHeader), chunk('IDAT'), chunk('IEND')]);
check('oversized dimensions rejected before decode', reference, oversized, 1, /image_dimensions_limit/);
check('oversized encoded output', reference, Buffer.alloc(8 * 1024 * 1024 + 1), 1, /file_size_limit_or_empty/);
const animated = Buffer.concat([reference.subarray(0, 33), chunk('acTL', Buffer.alloc(8)), reference.subarray(33)]);
check('animated PNG rejected', reference, animated, 1, /animated_png_not_supported/);
const duplicate = Buffer.concat([reference.subarray(0, 33), reference.subarray(8, 33), reference.subarray(33)]);
check('duplicate PNG header', reference, duplicate, 1, /duplicate_png_header/);
const corruptData = Buffer.concat([reference.subarray(0, 33), chunk('IDAT', Buffer.from([0, 0, 0])), chunk('IEND')]);
check('invalid compressed stream', reference, corruptData, 1, /png_decode_/);
check('identical damaged files never bypass decoding', badCrc, badCrc, 3, /CHECKER_ERROR/);
const orderA = pixels(64, 64), orderB = pixels(64, 64);
fillRect(orderA, 64, 5, 5, 10, 10, [255, 0, 0, 255]);
fillRect(orderA, 64, 30, 30, 10, 10, [0, 0, 255, 255]);
fillRect(orderB, 64, 30, 30, 10, 10, [0, 0, 255, 255]);
fillRect(orderB, 64, 5, 5, 10, 10, [255, 0, 0, 255]);
check('drawing order with same final pixels', png(64, 64, orderA), png(64, 64, orderB, { level: 1 }), 0, / AC /);
const overlapA = pixels(64, 64), overlapB = pixels(64, 64);
fillRect(overlapA, 64, 10, 10, 20, 20, [255, 0, 0, 255]);
fillRect(overlapA, 64, 20, 20, 20, 20, [0, 0, 255, 255]);
fillRect(overlapB, 64, 20, 20, 20, 20, [0, 0, 255, 255]);
fillRect(overlapB, 64, 10, 10, 20, 20, [255, 0, 0, 255]);
check('overlapping order changes final pixels', png(64, 64, overlapA), png(64, 64, overlapB), 1, /pixels_differ/);
const preflightFile = join(fixtures, 'reference.out');
writeFileSync(preflightFile, envelope(reference));
const preflight = execute(normal, ['--check-reference', 'reference.out'], { cwd: fixtures });
assert.equal(preflight.status, 0, preflight.stderr);
assert.match(preflight.stderr, /REFERENCE_OK/);
console.log('PASS reference preflight'); count++; passed++;
const prepareInput = join(fixtures, 'reference.png'), prepareOutput = join(fixtures, 'prepared.out');
writeFileSync(prepareInput, reference);
const prepared = execute(process.execPath, [join(root, 'prepare-reference.mjs'), prepareInput, prepareOutput]);
assert.equal(prepared.status, 0, prepared.stderr);
check('prepared text reference', readFileSync(prepareOutput), reference, 0, / AC /);
const repeated = execute(process.execPath, [join(root, 'prepare-reference.mjs'), prepareInput, prepareOutput]);
assert.notEqual(repeated.status, 0, 'Reference preparation must not overwrite data silently');
console.log('PASS reference overwrite protection'); count++; passed++;
{
  const captureRoot = join(fixtures, 'capture');
  const captureDir = join(captureRoot, '7702');
  const candidatePath = join(captureRoot, '_capture', '7702.txt');
  mkdirSync(captureDir, { recursive: true });
  mkdirSync(join(captureRoot, '_capture'));
  const inCapture = (args) => execute(normal, args, { cwd: captureDir, timeout: 10000 });
  writeFileSync(join(captureDir, 'cap.in'), '\n');
  writeFileSync(join(captureDir, 'cap.out'), 'placeholder\n');
  writeFileSync(join(captureDir, 'user.out'), reference);
  const arm = () => writeFileSync(join(captureDir, 'capture-armed.txt'), 'armed\n');
  const idle = inCapture(['cap.in', 'cap.out', 'user.out']);
  assert.equal(idle.status, 3, 'Without a marker the invalid reference must stay a checker error');
  assert.equal(existsSync(candidatePath), false);
  arm();
  const captured = inCapture(['cap.in', 'cap.out', 'user.out']);
  assert.equal(captured.status, 0, captured.stderr);
  assert.match(captured.stderr, /CAPTURED case=cap /);
  assert.equal(existsSync(join(captureDir, 'capture-armed.txt')), true, 'The read-only problem folder keeps its marker');
  assert.deepEqual(readdirSync(captureDir).sort(), ['cap.in', 'cap.out', 'capture-armed.txt', 'user.out'], 'Nothing is written to the problem folder');
  assert.deepEqual(readdirSync(join(captureRoot, '_capture')).sort(), ['7702.last.txt', '7702.txt'], 'No temporary file is left behind');
  const candidate = readFileSync(candidatePath, 'latin1').split('\n');
  assert.equal(candidate[0], 'OJ_IMAGE_CAPTURE_V1 cap');
  assert.equal(candidate[1], 'OJ_IMAGE_PNG_BASE64_V1');
  assert.ok(Buffer.from(candidate.slice(2).join(''), 'base64').equals(reference));
  assert.equal(inCapture(['cap.in', 'cap.out', 'user.out']).status, 3, 'A marker that already produced a candidate is spent');
  const later = new Date(Date.now() + 5000);
  utimesSync(join(captureDir, 'capture-armed.txt'), later, later);
  assert.equal(inCapture(['cap.in', 'cap.out', 'user.out']).status, 0, 'Re-arming allows another capture');
  rmSync(candidatePath);
  const stale = new Date(Date.now() - 20 * 60 * 1000);
  utimesSync(join(captureDir, 'capture-armed.txt'), stale, stale);
  assert.equal(inCapture(['cap.in', 'cap.out', 'user.out']).status, 3, 'An expired marker must be ignored');
  arm();
  writeFileSync(join(captureDir, 'user.out'), 'not an image');
  const garbage = inCapture(['cap.in', 'cap.out', 'user.out']);
  assert.equal(garbage.status, 3, 'Garbage output falls through to the normal path (placeholder reference is invalid)');
  assert.equal(existsSync(candidatePath), false, 'Garbage never becomes a candidate');
  writeFileSync(join(captureDir, 'user.out'), reference);
  rmSync(join(captureRoot, '_capture'), { recursive: true });
  const missingDir = inCapture(['cap.in', 'cap.out', 'user.out']);
  assert.equal(missingDir.status, 3, 'A missing capture folder is a checker error, not a silent pass');
  assert.match(missingDir.stderr, /capture_dir_not_writable/);
  console.log('PASS reference capture mode'); count++; passed++;
}
{
  const verdictRoot = join(fixtures, 'verdict');
  const problemDir = join(verdictRoot, '7703');
  const logPath = join(verdictRoot, '_capture', '7703.last.txt');
  mkdirSync(problemDir, { recursive: true });
  const inVerdict = (args) => execute(normal, args, { cwd: problemDir, timeout: 10000 });
  writeFileSync(join(problemDir, 'v.in'), '\n');
  writeFileSync(join(problemDir, 'v.out'), envelope(reference));
  writeFileSync(join(problemDir, 'user.out'), reference);
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 0);
  assert.equal(existsSync(join(verdictRoot, '_capture')), false, 'Without the capture folder nothing is created');
  mkdirSync(join(verdictRoot, '_capture'));
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 0);
  const accepted = readFileSync(logPath, 'utf8');
  assert.match(accepted, /^\d+ OJ_IMAGE_SPJ\/1 AC reason=within_tolerance /);
  writeFileSync(join(problemDir, 'user.out'), png(64, 64, shifted));
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 1);
  assert.match(readFileSync(logPath, 'utf8'),
    /WA reason=pixels_differ case=v width=64 height=64 unmatched_expected=\d+ unmatched_actual=\d+ diff_bbox=\d+,\d+,\d+,\d+ max_delta=\d+/);
  assert.ok(readFileSync(join(verdictRoot, '_capture', '7703.actual.png')).equals(png(64, 64, shifted)), 'The rejected image is kept');
  writeFileSync(join(problemDir, 'user.out'), png(32, 32, drawing(32)));
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 1);
  assert.match(readFileSync(logPath, 'utf8'), /WA reason=dimensions_mismatch case=v expected=64x64 actual=32x32/);
  writeFileSync(join(problemDir, 'user.out'), reference);
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 0);
  assert.equal(existsSync(join(verdictRoot, '_capture', '7703.actual.png')), false, 'An accepted image drops the kept one');
  writeFileSync(join(problemDir, 'user.out'), 'not an image');
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 1);
  assert.match(readFileSync(logPath, 'utf8'), /WA reason=output_/);
  writeFileSync(join(problemDir, 'v.out'), 'broken reference\n');
  writeFileSync(join(problemDir, 'user.out'), reference);
  assert.equal(inVerdict(['v.in', 'v.out', 'user.out']).status, 3);
  assert.match(readFileSync(logPath, 'utf8'), /CHECKER_ERROR reason=reference_/);
  assert.deepEqual(readdirSync(join(verdictRoot, '_capture')), ['7703.last.txt'], 'No temporary file is left behind');
  console.log('PASS verdict log'); count++; passed++;
}
{
  const settingsDir = join(fixtures, 'settings', '7704');
  mkdirSync(settingsDir, { recursive: true });
  const inSettings = (args) => execute(normal, args, { cwd: settingsDir, timeout: 10000 });
  const grey = png(64, 64, pixels(64, 64, [200, 200, 200, 255]));
  const lighter = png(64, 64, pixels(64, 64, [240, 240, 240, 255]));
  writeFileSync(join(settingsDir, 's.in'), '\n');
  writeFileSync(join(settingsDir, 's.out'), envelope(grey));
  writeFileSync(join(settingsDir, 'user.out'), lighter);
  const settingsPath = join(settingsDir, 'image-spj-settings.txt');
  const run = () => inSettings(['s.in', 's.out', 'user.out']);
  assert.equal(run().status, 1, 'A color difference of 40 fails under the default tolerance');
  assert.match(run().stderr, /max_delta=40 color_tolerance=16 /);
  writeFileSync(settingsPath, '# comment\ncolor_tolerance=48\n');
  const relaxedRun = run();
  assert.equal(relaxedRun.status, 0, relaxedRun.stderr);
  assert.match(relaxedRun.stderr, /color_tolerance=48 /);
  writeFileSync(settingsPath, 'color_tolerance=32\r\n');
  assert.equal(run().status, 1, 'A tolerance below the difference still rejects');
  writeFileSync(settingsPath, 'color_tolerance=96\n');
  assert.equal(run().status, 0, 'The cap itself is accepted');
  for (const bad of ['color_tolerance=97\n', 'color_tolerance=-1\n', 'color_tolerance=\n', 'color_tolerance=4x\n',
    'spatial_radius=2\n', 'color_tolerance=1000\n', `color_tolerance=48\n${'#'.repeat(300)}\n`]) {
    writeFileSync(settingsPath, bad);
    const rejected = run();
    assert.equal(rejected.status, 3, `Invalid settings must be a checker error: ${JSON.stringify(bad.slice(0, 24))}`);
    assert.match(rejected.stderr, /CHECKER_ERROR reason=settings_invalid/);
  }
  rmSync(settingsPath);
  assert.equal(run().status, 1, 'Without a settings file the default applies again');
  console.log('PASS per-problem color tolerance'); count++; passed++;
}
let posix = 'Not run on Windows';
if (process.platform !== 'win32') {
  symlinkSync('reference.out', join(fixtures, 'linked.out'));
  const linked = execute(normal, ['--check-reference', 'linked.out'], { cwd: fixtures });
  assert.equal(linked.status, 3);
  assert.match(linked.stderr, /symlink/);
  console.log('PASS POSIX symlink rejected'); count++; passed++;
  posix = 'Symlink rejection passed; production sandbox acceptance still required';
  if (typeof process.getuid === 'function' && process.getuid() !== 0) {
    const unreadableDir = join(fixtures, 'settings', '7704');
    const unreadable = join(unreadableDir, 'image-spj-settings.txt');
    writeFileSync(unreadable, 'color_tolerance=64\n');
    chmodSync(unreadable, 0);
    const blocked = execute(normal, ['s.in', 's.out', 'user.out'], { cwd: unreadableDir, timeout: 10000 });
    chmodSync(unreadable, 0o644);
    assert.equal(blocked.status, 1, 'An unreadable settings file falls back to the strict default instead of failing');
    assert.match(blocked.stderr, /color_tolerance=16 .* settings=unreadable/);
    console.log('PASS POSIX unreadable settings fall back'); count++; passed++;
  }
}

const benchmarks = [];
for (const size of [512, 1024, 2048]) {
  const data = drawing(size), encoded = png(size, size, data);
  writeFileSync(join(fixtures, 'bench.out'), envelope(encoded));
  writeFileSync(join(fixtures, 'bench-user.out'), encoded);
  const totals = [], comparisons = [], elapsed = [];
  for (let i = 0; i < 11; i++) {
    const before = performance.now();
    const result = execute(normal, ['case.in', 'bench.out', 'bench-user.out'], { cwd: fixtures, timeout: 10000 });
    const wall = performance.now() - before;
    assert.equal(result.status, 0, result.stderr);
    if (i === 0) continue;
    totals.push(Number(result.stderr.match(/total_ms=([\d.]+)/)[1]));
    comparisons.push(Number(result.stderr.match(/compare_ms=([\d.]+)/)[1]));
    elapsed.push(wall);
  }
  const stats = (values) => {
    const sorted = [...values].sort((a, b) => a - b);
    return { median: sorted[Math.floor(sorted.length / 2)], max: sorted.at(-1) };
  };
  const entry = { size, samples: totals.length, internal_ms: stats(totals), compare_ms: stats(comparisons), process_wall_ms: stats(elapsed) };
  benchmarks.push(entry);
  console.log(`BENCH ${size}x${size}: ${JSON.stringify(entry)}`);
}
const report = { status: 'completed', passed, platform: process.platform, compiler, posix, profiles_tested: testProfiles, benchmarks,
  notes: 'Local synthetic images only. No Linux HUSTOJ, renderer, queue or concurrency measurements.' };
writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`);
console.log(`PASS ${count} checks. Report: ${join(build, 'test-results.json')}`);
