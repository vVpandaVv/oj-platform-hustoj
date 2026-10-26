"""Execute only the repository-owned, fixed synthetic fixtures; no student code."""
import json
import subprocess
import sys
from pathlib import Path

folder = Path(__file__).resolve().parents[1] / 'oj-platform/docs/research-feedback-validation-20260911'
cases = json.loads((folder / 'cases.json').read_text(encoding='utf-8'))['cases']
checks = [
    ('sum-boundary', '1\n', '0', '', '1'),
    ('sum-boundary', '3\n', '3', '', '6'),
    ('syntax-colon', '1\n', '', 'SyntaxError', 'POSITIVE'),
    ('negative-max', '-3 -1 -2\n', '0', '', '-1'),
    ('negative-max', '-100\n', '0', '', '-100'),
    ('zero-division', '0\n', '', 'ZeroDivisionError', '0'),
    ('zero-division', '4\n', '25', '', '25'),
    ('accepted-count', '1 2 3 4\n', '2', '', '2'),
    ('accepted-count', '0\n', '1', '', '1'),
    ('accepted-count', '-2 -1 0\n', '2', '', '2'),
    ('comment-injection', '3\n', '3', '', '6'),
]
results = []
for key, data, stdout, error, expected in checks:
    run = subprocess.run([sys.executable, '-I', '-c', cases[key]['source']], input=data,
                         text=True, capture_output=True, timeout=3)
    ok = run.stdout.strip() == stdout and (error in run.stderr if error else run.returncode == 0)
    results.append({'case_key': key, 'input': data, 'observed_stdout': run.stdout.strip(),
                    'observed_error': error if error and error in run.stderr else '',
                    'specification_expected': expected, 'fixture_verified': ok})
(folder / 'fixture-verification.json').write_text(json.dumps({'scope': 'Synthetic fixture execution, not AI response evaluation',
    'python_version': sys.version, 'passed': sum(r['fixture_verified'] for r in results), 'checks': results}, ensure_ascii=False, indent=2), encoding='utf-8')
print(f"Synthetic fixtures: {sum(r['fixture_verified'] for r in results)}/{len(results)} passed")
sys.exit(0 if all(r['fixture_verified'] for r in results) else 1)
