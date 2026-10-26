#!/usr/bin/env python3
"""Run the step debugger in the shipped Worker harness under real CPython.

The harness source is taken from resources/js/pyodide-worker.js exactly as the
Worker executes it (via node with a stub Pyodide), so this checks the shipped
Python, not a copy. Pyodide 0.26.4 runs Python 3.12; this uses the local CPython.
"""
import json, subprocess, sys
from pathlib import Path
sys.stdout.reconfigure(encoding='utf-8')
ROOT = Path(__file__).resolve().parents[1]
EXTRACT = r"""
const vm=require('node:vm'),fs=require('node:fs');let harness=null;
const pyodide={setStdin(){},runPythonAsync:code=>{harness=harness||code;return Promise.resolve();}};
const self={postMessage(){},loadPyodide:()=>Promise.resolve(pyodide)};
vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),{self,importScripts(){},TextEncoder,TextDecoder,Atomics,console});
self.onmessage({data:{type:'init',indexUrl:'x/'}});setTimeout(()=>process.stdout.write(harness),20);
"""
source = subprocess.run(['node', '-e', EXTRACT, str(ROOT / 'resources/js/pyodide-worker.js')],
                        capture_output=True, text=True, encoding='utf-8', check=True).stdout
ns = {}
exec(source, ns)
run, reset = ns['__ocl_run'], ns['__ocl_reset']
failures = 0


def check(cond, label):
    global failures
    print(('PASS ' if cond else 'FAIL ') + label)
    failures += 0 if cond else 1


def debug(code, actions, name='<cell #1>', initial='[]'):
    pauses, out = [], []
    queue = list(actions)

    def pause(snapshot_json):
        snap = json.loads(snapshot_json)
        pauses.append(snap)
        command = queue.pop(0) if queue else {'action': 'continue'}
        return json.dumps(command) if not isinstance(command, str) else command
    result = json.loads(run(code, out.append, pause, name, initial))
    return pauses, ''.join(out), result


code = """def add(a, b):
    total = a + b
    return total

x = 1
y = add(x, 2)
print(y)
"""
# Step over everything: lines 1,5,6,7 in the module only.
p, out, r = debug(code, [{'action': 'over'}] * 10)
check([s['line'] for s in p] == [1, 5, 6, 7], 'step over visits module lines only: %r' % [s['line'] for s in p])
check(out == '3\n' and r['error'] is None, 'output and no error')
check(p[0]['locals'] is None and p[0]['globals'] == [], 'module pause has no locals and empty globals at start')
g = {v['name']: v for v in p[3]['globals']}
check(g['x']['value'] == '1' and g['y']['value'] == '3' and g['y']['type'] == 'int', 'globals values at line 7')
check(g['add']['type'] == 'function' and g['add']['value'] == 'def add(a, b)', 'function shown as signature')

# Step into the call.
reset()
p, out, r = debug(code, [{'action': 'step'}] * 20, '<cell #2>')
lines = [(s['function'], s['line']) for s in p]
check(lines == [('<module>', 1), ('<module>', 5), ('<module>', 6), ('add', 2), ('add', 3), ('<module>', 7)], 'step into enters add(): %r' % lines)
inner = p[4]
loc = {v['name']: v['value'] for v in inner['locals']}
check(loc == {'a': '1', 'b': '2', 'total': '3'}, 'locals inside add: %r' % loc)
check([f['function'] for f in inner['stack']] == ['<module>', 'add'], 'call stack outermost first')

# Step out from inside the function returns to the caller's next line.
reset()
p, out, r = debug(code, [{'action': 'step'}] * 3 + [{'action': 'out'}, {'action': 'step'}], '<cell #3>')
lines = [(s['function'], s['line']) for s in p]
check(lines == [('<module>', 1), ('<module>', 5), ('<module>', 6), ('add', 2), ('<module>', 7)], 'step out: %r' % lines)

# Continue runs to the end; breakpoints still stop.
reset()
loop = "s = 0\nfor i in range(5):\n    s += i\nprint(s)\n"
p, out, r = debug(loop, [{'action': 'continue', 'breakpoints': [3]}] * 10, '<cell #4>')
check([s['line'] for s in p] == [1, 3, 3, 3, 3, 3], 'breakpoint hits every iteration: %r' % [s['line'] for s in p])
p, out, r = debug(loop, [{'action': 'continue'}], '<cell #5>')
check(len(p) == 1 and out == '10\n', 'continue without breakpoints finishes')

# Functions from an earlier (non-debug) run are never traced.
reset()
run('def helper(n):\n    return n * 2\n', lambda t: None)
p, out, r = debug('v = helper(4)\nprint(v)\n', [{'action': 'step'}] * 10, '<cell #6>')
check([(s['function'], s['line']) for s in p] == [('<module>', 1), ('<module>', 2)], 'earlier-cell function not stepped into')

# Errors still produce a traceback and tracing is switched off afterwards.
reset()
p, out, r = debug('a = 1\nb = a / 0\n', [{'action': 'step'}] * 5, '<cell #7>')
check(r['error'] and 'ZeroDivisionError' in r['error'], 'exception reported')
check(sys.gettrace() is None, 'trace function removed after run')

# Large / odd values are summarized, never crash.
reset()
big = "big = list(range(10000))\ntext = 'x' * 5000\nclass Bad:\n    def __repr__(self):\n        raise RuntimeError('nope')\nbad = Bad()\nimport math\nm = {'k': [1, [2, [3, [4]]]]}\npass\n"
p, out, r = debug(big, [{'action': 'over'}] * 20, '<cell #8>')
g = {v['name']: v for v in p[-1]['globals']}
check(r['error'] is None, 'no error with odd values')
check(g['big']['size'] == 'len=10000' and len(g['big']['value']) < 200, 'big list truncated: %s' % g['big']['value'][:60])
check(len(g['text']['value']) <= 210, 'long string truncated')
check(g['bad']['value'].startswith('<Bad instance'), 'broken __repr__ handled: ' + g['bad']['value'])
check('math' not in g, 'modules hidden')
check(g['Bad']['value'] == 'class Bad', 'class shown')

# Garbage replies from the UI fall back to continue.
reset()
p, out, r = debug(loop, ['not json'], '<cell #9>')
check(len(p) == 1 and out == '10\n', 'malformed command continues')
p, out, r = debug(loop, [None], '<cell #10>')
check(len(p) == 1 and out == '10\n', 'EOF reply continues')

# Recursion: depth-aware step over inside a recursive function.
reset()
rec = "def f(n):\n    if n == 0:\n        return 0\n    return n + f(n - 1)\n\nprint(f(3))\n"
p, out, r = debug(rec, [{'action': 'step'}] * 3 + [{'action': 'over'}] * 10, '<cell #11>')
lines = [(s['function'], s['line'], len(s['stack'])) for s in p]
check(lines[:4] == [('<module>', 1, 1), ('<module>', 6, 1), ('f', 2, 2), ('f', 4, 2)] and out == '6\n', 'recursive step over stays at depth: %r' % lines)

# Initial breakpoints run straight to the first one instead of line 1.
reset()
p, out, r = debug(loop, [{'action': 'continue', 'breakpoints': [4]}], '<cell #12>', '[3]')
check([s['line'] for s in p] == [3, 4] and out == '10\n', 'initial breakpoint skips to line 3, then 4: %r' % [s['line'] for s in p])
p, out, r = debug(loop, [], '<cell #13>', '[true, -1, "3", 99]')
check(len(p) == 0 and out == '10\n', 'invalid or unreachable breakpoints never pause')

# A __repr__ defined in the debugged cell is described without a nested pause.
reset()
own = "class P:\n    def __init__(self, v):\n        self.v = v\n    def __repr__(self):\n        return 'P(' + str(self.v) + ')'\n\np = P(3)\nq = 1\n"
p, out, r = debug(own, [{'action': 'over'}] * 10, '<cell #14>')
check([s['line'] for s in p] == [1, 7, 8], 'no pause inside __repr__ while describing variables: %r' % [s['line'] for s in p])
check({v['name']: v['value'] for v in p[-1]['globals']}.get('p') == 'P(3)', 'cell-defined __repr__ used for the value')

# The end snapshot reports final values, or the failing frame and line.
reset()
p, out, r = debug(loop, [{'action': 'continue'}], '<cell #15>')
end = r['debugEnd']
check(end['event'] == 'finished' and {v['name']: v['value'] for v in end['globals']}.get('s') == '10', 'finished snapshot has final globals')
reset()
crash = "def div(a, b):\n    c = a * 2\n    return c / b\n\nx = div(4, 0)\n"
p, out, r = debug(crash, [{'action': 'continue'}], '<cell #16>')
end = r['debugEnd']
check(end['event'] == 'exception' and end['line'] == 3 and end['function'] == 'div', 'exception snapshot points at the failing line: %r' % {k: end.get(k) for k in ('event', 'line', 'function')})
check({v['name']: v['value'] for v in end['locals']} == {'a': '4', 'b': '0', 'c': '8'}, 'exception snapshot keeps locals')
check([f['line'] for f in end['stack']] == [5, 3], 'exception stack lines: %r' % end['stack'])
check(r['error'] and 'ZeroDivisionError' in r['error'], 'traceback still reported')
check('__ocl_debug_exec' not in r['error'] and r['error'].count('<cell #16>') == 2, 'debugger frame left out of the traceback: %r' % r['error'])
check('debugEnd' not in json.loads(run('z = 1', lambda t: None)), 'plain runs carry no debug data')

print('failures:', failures)
sys.exit(1 if failures else 0)
