import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {execFileSync, spawnSync} from 'node:child_process';
import {Engine} from '../resources/js/engine.js';
import {scenario} from '../resources/js/scenarios.js';
import {compilePattern, budget} from '../resources/js/text-pattern.js';
import {utility, toolWords, bounded} from '../resources/js/text-utils.js';
let passed = 0, referenceChecks = 0;
const test = (label, fn) => { fn(); passed++; console.log('OK ' + label); };
function context(files = {}) { return {meter: budget(), previous: 7, hostname: 'HOST', profile: 'linux', read: name => { if (!Object.hasOwn(files, name)) throw new Error('missing virtual file'); return files[name]; }, list: () => Object.keys(files), paths: Object.keys(files)}; }
const tool = (line, input, files = {}, options = {}) => utility(toolWords(line), context(files), options)(input);
const cmd = (e, node, lines) => { let output; for (const line of lines) output = e.execute(node, line); return output; };

test('bounded NFA handles anchors, classes, groups, alternatives and repetition', () => {
    const cases = [['^eth[0-9]+$', 'eth12', true], ['^(up|down)$', 'down', true], ['^a{2,4}b?$', 'aaab', true], ['[[:digit:]]+', 'ab42cd', true], ['[^a-c]+$', 'abc', false], ['^가상.*장비$', '가상 라우터 장비', true], ['^$', '', true], ['(a?)*b', 'b', true]];
    for (const [pattern, text, expected] of cases) assert.equal(compilePattern(pattern, {extended: true})(text, budget()), expected, pattern);
});
test('BRE and ERE operators are distinct', () => {
    assert(compilePattern('a+b')('a+b', budget())); assert(!compilePattern('a+b')('aaab', budget())); assert(compilePattern('a\\+b')('aaab', budget()));
    assert(compilePattern('\\(up\\|down\\)')('down', budget())); assert(compilePattern('^a\\{2,3\\}$')('aaa', budget()));
});
test('fixed and case-insensitive matching do not treat metacharacters as expressions', () => {
    assert(compilePattern('a.b', {fixed: true})('a.b', budget())); assert(!compilePattern('a.b', {fixed: true})('axb', budget())); assert(compilePattern('KOREA', {insensitive: true})('korea', budget()));
});
test('whole line, words and empty fixed patterns are bounded', () => {
    assert(!compilePattern('up', {whole: true})('setup', budget())); assert(compilePattern('up', {words: true})('link up now', budget())); assert(!compilePattern('up', {words: true})('setup', budget()));
    assert(!compilePattern('', {fixed: true, words: true})('abc', budget()));
});
test('backreferences, lookaround, excess states and malformed patterns are rejected', () => {
    for (const pattern of ['(a)\\1', '(?=a)', '[abc', '(a', 'a{0,1000}', '*a', '(abcdefghijklmnopqrstuvwxyz){64}']) assert.throws(() => compilePattern(pattern, {extended: true}));
});
test('nested repetition has no catastrophic backtracking and shared work budget stops scans', () => {
    assert(!compilePattern('(a+)+$', {extended: true})('a'.repeat(20000) + '!', budget())); assert.throws(() => compilePattern('a.*z', {extended: true})('a'.repeat(2000), budget(100)));
});
test('grep selects, numbers, inverts, counts and reports no-match status', () => {
    const input = 'UP\ndown\nsetup\n'; assert.deepEqual(tool('grep -in up', input), {text: '1:UP\n3:setup\n', status: 0}); assert.equal(tool('grep -v up', input).text, 'UP\ndown\n');
    assert.deepEqual(tool('grep -c missing', input), {text: '0\n', status: 1}); assert.equal(tool('grep -q up', input).text, '');
});
test('grep context merges adjacent groups and separates gaps', () => {
    const input = 'a\nx\nb\nc\nd\ne\nx\nf\n'; assert.equal(tool('grep -n -C1 x', input).text, '1-a\n2:x\n3-b\n--\n6-e\n7:x\n8-f\n');
});
test('GNU grep -f reads a virtual pattern file, unlike FortiOS context -f', () => {
    assert.equal(tool('grep -f /patterns', 'up\ndown\nnone\n', {'/patterns': 'up\ndown\n'}).text, 'up\ndown\n');
    assert.equal(tool('grep -f /empty', 'up\n', {'/empty': ''}).status, 1);
    const input = 'config firewall policy\n  edit 1\n    set name HTTP\n  next\n  edit 2\n    set name PING\n    set nat enable\n  next\nend\n';
    const result = tool('grep -f PING', input, {}, {forti: true}); assert(result.text.includes('edit 2') && result.text.includes('set nat enable') && !result.text.includes('edit 1')); assert(result.text.startsWith('config firewall policy\n') && result.text.endsWith('end\n'));
});
test('grep multiple patterns and files prefix filenames correctly', () => {
    assert.equal(tool('grep -n -e up -e down /one /two', undefined, {'/one': 'up\nother\n', '/two': 'down\n'}).text, '/one:1:up\n/two:1:down\n');
});
test('quotes preserve regex escapes and literal status variables', () => {
    assert.equal(tool("grep -E '^192\\.168'", '192.168.1.1\n192x168\n').text, '192.168.1.1\n');
    assert.equal(tool('echo $?', undefined).text, '7\n'); assert.equal(tool("echo '$?'", undefined).text, '$?\n'); assert.equal(tool('echo \\$?', undefined).text, '$?\n');
});
test('head tail wc handle final newlines, empty input and UTF-8 counts', () => {
    const input = 'a\nb\nc\n'; assert.equal(tool('head -n2', input).text, 'a\nb\n'); assert.equal(tool('tail -n +2', input).text, 'b\nc\n'); assert.equal(tool('tail -n0', input).text, '');
    assert.equal(tool('wc -l', 'a\nb').text, '1\n'); assert.equal(tool('wc -mc', '가\n').text, '      2       4\n'); assert.equal(tool('head', '').text, ''); assert.throws(() => tool('tail -f', input));
});
test('sort numeric/reverse/unique and uniq adjacent grouping are distinct', () => {
    assert.equal(tool('sort -nu', '10 z\n2 a\n10 x\n').text, '2 a\n10 z\n'); assert.equal(tool('sort -r', 'a\nb\n').text, 'b\na\n');
    assert.equal(tool('uniq -c', 'a\na\nb\na\n').text, '      2 a\n      1 b\n      1 a\n'); assert.equal(tool('uniq -d', 'a\na\nb\n').text, 'a\n');
});
test('cut selects ordered fields and ranges, preserves undelimited lines', () => {
    assert.equal(tool("cut -d ':' -f 3,1", 'a:b:c\nplain\n').text, 'a:c\nplain\n'); assert.equal(tool("cut -d ':' -f 2- -s", 'a:b:c\nplain\n').text, 'b:c\n'); assert.throws(() => tool('cut -f0', 'a\n'));
});
test('virtual diff produces deterministic unified changes and status', () => {
    const files = {'/old': 'a\nb\nc\n', '/new': 'a\nx\nc\n'}; assert.deepEqual(tool('diff -u /old /new', undefined, files), {text: '--- /old\n+++ /new\n@@ -2,1 +2,1 @@\n-b\n+x\n', status: 1}); assert.equal(tool('diff /old /old', undefined, files).status, 0);
});
test('text, line and field-analysis budgets are enforced', () => {
    assert.throws(() => bounded('x'.repeat(200001), budget())); assert.throws(() => bounded('x\n'.repeat(4001), budget()));
    const fields = Array.from({length: 450}, (_, i) => String(i + 1)).join(','); assert.throws(() => tool(`cut -d ':' -f ${fields}`, 'x:'.repeat(20000)), /연산 한도/);
});
test('Linux pipelines use actual modeled interface and route output', () => {
    const e = new Engine(scenario('routing')); assert.equal(e.execute('n1', 'ip addr | grep -n inet'), '2:    inet 192.168.10.10/24'); assert(e.execute('n1', 'ip route | grep default').includes('192.168.10.1'));
    assert.equal(e.execute('n1', 'echo hello | wc -c'), '6');
});
test('IOS native filters, alternatives, sections and abbreviations work', () => {
    const e = new Engine(scenario('ios-switch')); e.execute('n2', 'enable'); assert.equal(e.execute('n2', 'sh run | inc ^interface | lab wc -l'), '6');
    assert(e.execute('n2', 'show running-config | include ^hostname|^interface').includes('hostname SW-A'));
    const text = e.execute('n2', 'show running-config | section interface GigabitEthernet1/0/2'); assert(text.includes('switchport mode access') && !text.includes('GigabitEthernet1/0/1'));
    assert(e.execute('n2', 'show running-config | begin interface').startsWith('interface')); assert(!e.execute('n2', 'show running-config | exclude interface').includes('interface'));
});
test('FortiOS grep context includes selected policy but not siblings', () => {
    const e = new Engine(scenario('fortios')); const text = e.execute('n3', 'show firewall policy | grep -nf HTTP'); assert(text.includes('set nat enable') && text.includes('edit 1')); assert(!text.includes('phase1'));
});
test('all profiles share lab tools without masquerading as native Linux shells', () => {
    for (const [scene, id] of [['lan', 'n1'], ['ios-switch', 'n2'], ['fortios', 'n3']]) { const e = new Engine(scenario(scene)); if (id === 'n2') e.execute(id, 'enable'); assert(e.execute(id, 'lab files').includes('/config/running.conf')); assert(e.execute(id, 'lab cat /state/interfaces.txt | grep -c up').length); assert(e.execute(id, 'lab stats').includes('queued_events=')); }
    const e = new Engine(scenario('ios-switch')); e.execute('n2', 'enable'); assert.throws(() => e.execute('n2', 'show running-config | grep interface')); assert(e.execute('n2', 'show running-config | lab grep interface'));
});
test('virtual files are node scoped, read only and privilege checked', () => {
    const e = new Engine(scenario('ios-switch')); assert.throws(() => e.execute('n2', 'lab cat /config/running.conf')); e.execute('n2', 'enable'); assert(e.execute('n2', 'lab cat /etc/hostname').includes('SW-A'));
    for (const line of ['lab cat /etc/shadow', 'lab cat ../../etc/passwd', 'lab cat C:/Windows/win.ini', 'lab echo x > /etc/hostname', 'lab rm /config/running.conf']) assert.throws(() => e.execute('n2', line)); assert.equal(e.node('n2').name, 'SW-A');
});
test('invalid pipelines cannot mutate configuration, modes, queues or sessions', () => {
    const e = new Engine(scenario('ios-router')); e.execute('n3', 'enable'); const before = e.export();
    for (const line of ['configure terminal | include x', 'ping 192.168.20.10 | lab grep x', 'show running-config | lab grep -E "["', 'show running-config | lab tail -f', 'lab echo x; hostname BAD', 'lab echo $(hostname)', 'lab echo `hostname`', 'lab echo x || lab echo y', 'lab echo x |']) { assert.throws(() => e.execute('n3', line), line); assert.deepEqual(e.export(), before); assert.equal(e.queue.length, 0); assert(e.consolePrompt('n3').endsWith('#') && !e.consolePrompt('n3').includes('(config)')); }
    const l = new Engine(scenario('firewall')), rules = l.export(); assert.throws(() => l.execute('n3', 'nft flush ruleset | grep x')); assert.deepEqual(l.export(), rules);
});
test('pipe and regex limits reject excessive chains before evaluation', () => {
    const e = new Engine(scenario('lan')); assert.throws(() => e.execute('n1', 'echo x' + ' | cat'.repeat(8))); assert.throws(() => e.execute('n1', `ip addr | grep '${'a'.repeat(257)}'`)); assert.throws(() => e.execute('n1', 'tcpdump | grep x'));
});
test('status and bounded command history are per-device and never saved', () => {
    const e = new Engine(scenario('lan')); const before = e.export(); assert.equal(e.execute('n1', 'grep missing /etc/hostname'), ''); assert.equal(e.execute('n1', 'lab status'), '1');
    assert.throws(() => e.execute('n1', 'lab cat /missing')); assert.equal(e.execute('n1', 'echo $?'), '2'); assert(e.execute('n1', 'lab history').includes('grep missing')); assert(!e.execute('n3', 'lab history').includes('grep missing'));
    for (let i = 0; i < 110; i++) e.execute('n1', 'echo x'); assert.equal(e.consoles.get('n1').history.length, 100); assert.deepEqual(e.export(), before); assert.equal(e.revision, 0);
});
test('lab diff compares independent running/startup configuration', () => {
    const e = new Engine(scenario('ios-switch')); cmd(e, 'n2', ['enable', 'conf t', 'hostname NEW-SW', 'end']); assert(e.execute('n2', 'lab diff').includes('+hostname NEW-SW')); e.execute('n2', 'write memory'); assert.equal(e.execute('n2', 'lab diff'), '');
});
test('diagnostic logs reflect real forwarding decisions and omit other device events', () => {
    const e = new Engine(scenario('fortios')); e.execute('n1', 'ping 203.0.113.10'); e.run(); assert(e.execute('n3', 'execute log display | grep "implicit deny"').includes('drop')); assert.equal(e.execute('n4', 'lab logs | grep "FortiOS policy"'), '');
});
test('tool completion, help, quoted literal pipes and pending confirmations preserve modes', () => {
    const e = new Engine(scenario('ios-switch')); e.execute('n2', 'enable'); assert.equal(e.complete('n2', 'show running-config | inc').line, 'show running-config | include '); assert.equal(e.complete('n2', 'lab cat /etc/host').line, 'lab cat /etc/hostname ');
    assert(e.execute('n2', 'help').includes('lab files')); assert.equal(e.execute('n2', "lab echo 'a|b' | grep -F 'a|b'"), 'a|b'); e.execute('n2', 'reload'); assert(e.consoles.get('n2').pending); e.execute('n2', 'no'); assert.equal(e.consoles.get('n2').pending, '');
});

const gnu = process.env.OJNL_GNU_TOOLS || 'C:/Program Files/Git/usr/bin';
const gnuProbe = existsSync(gnu + '/grep.exe') ? spawnSync(gnu + '/grep.exe', ['--version'], {encoding: 'utf8', windowsHide: true, timeout: 5000}) : null;
if (gnuProbe?.status === 0) test('supported text operations match installed GNU binaries', () => {
    const fixtures = [
        ['grep -n up', 'up\ndown\nsetup\n'], ['grep -inv up', 'UP\ndown\nsetup\n'], ['grep -F "a.b"', 'a.b\naxb\n'], ['grep -E "^(up|down)$"', 'up\ndown\nsetup\n'], ['grep -n -B1 -A1 x', 'a\nx\nb\nc\nd\ne\nx\nf\n'], ['grep -c missing', 'a\nb\n'], ['grep -w up', 'setup\nup down\n'], ['grep "a+b"', 'a+b\naaab\n'], ['grep "a\\+b"', 'a+b\naaab\n'],
        ['grep "a^b"', 'a^b\nab\n'], ['grep "a$b"', 'a$b\nab\n'], ['grep -Ei "[[:upper:]]+"', 'abc\nABC\n123\n'], ['grep -Fw ""', 'abc\n'],
        ['grep -Hn up', 'up\n'], ['grep -Hhc up', 'up\n'], ['grep -n -C0 x', 'x\na\nb\nx\n'], ['grep -m1 -A2 x', 'x\na\nx\n'],
        ['head -n2', 'a\nb\nc\n'], ['tail -n +2', 'a\nb\nc\n'], ['tail -n0', 'a\nb\n'], ['cat -n', 'a\n\nb\n'], ['sort -n', '10 z\n2 a\n10 x\n'], ['sort -r', 'b\nc\na\n'], ['sort -nu', '10 z\n2 a\n10 x\n'], ['uniq -c', 'a\na\nb\na\n'], ['uniq -d', 'a\na\nb\n'], ["cut -d ':' -f 3,1", 'a:b:c\nplain\n'], ["cut -d ':' -f 2- -s", 'a:b:c\nplain\n'],
        ['wc -l', 'a\nb'], ['wc -lw', 'a b\nc\n'], ['wc -lwc', 'a b\nc\n'], ['wc -m', 'a b\nc\n'],
    ];
    for (const [line, input] of fixtures) { const tokens = toolWords(line), expected = spawnSync(gnu + '/' + tokens[0] + '.exe', tokens.slice(1), {input, encoding: 'utf8', windowsHide: true, env: {...process.env, LC_ALL: 'C'}, timeout: 5000}); assert(!expected.error, expected.error?.message); const actual = tool(line, input); assert.equal(actual.text, expected.stdout, line); assert.equal(actual.status, expected.status, line); referenceChecks++; }
});
else console.log('GNU reference runtime unavailable in this environment; behavioral tests still run.');
test('NFA agrees with a finite JavaScript ERE oracle on generated small inputs', () => {
    const patterns = ['a', '.', '^a', 'a$', 'a*', '(a|b)+', '(ab)?a', 'a{0,3}', '[^a-c]', '([ab]?)*b', '^([ab]+)?$', '[a-c]{1,3}$'];
    const inputs = ['', 'a', 'b', 'ab', 'ba', 'aaaa', 'c', 'ababa', 'xabc', 'ccc'];
    for (const pattern of patterns) for (const input of inputs) assert.equal(compilePattern(pattern, {extended: true})(input, budget()), new RegExp(pattern).test(input), pattern + '/' + input);
});
console.log(`${passed} output-tool checks passed; ${referenceChecks} GNU reference comparisons.`);
