"""Parse trusted shipped Python samples; never execute learner input."""
import ast
import json
import sys

samples = json.load(sys.stdin)
count = 0
for sample in samples:
    if sample['language'] == 'python' and sample['mode'] != 'keyword':
        ast.parse(sample['content'], filename=sample['id'], feature_version=(3, 12))
        count += 1
print(f'PASS: {count} Python 3.12 sample syntax checks (no execution).')
