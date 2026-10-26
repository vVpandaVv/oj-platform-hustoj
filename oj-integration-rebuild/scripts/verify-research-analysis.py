"""Generate synthetic regression oracles with statsmodels, independently of PHP.

Usage: python verify-research-analysis.py [--packages /path/to/test/dependencies]
Only artificial scores are generated; no OJ connection is made.
"""
import argparse
import json
import sys
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--packages')
args = parser.parse_args()
if args.packages:
    sys.path.insert(0, args.packages)
import numpy as np
import scipy
from scipy.stats import t
import statsmodels
import statsmodels.api as sm

root = Path(__file__).resolve().parents[1]
fixtures = []
for name, delta, cluster in [('positive', 7, False), ('negative', -7, False), ('cluster', 5, True), ('uncertain', 0, False)]:
    rng = np.random.default_rng(1313)
    rows = []
    for c in range(12 if cluster else 1):
        for level_i, level in enumerate(['beginner', 'intermediate', 'advanced']):
            for i in range(4 if cluster else 20):
                arm = 'A' if (c % 2 == 0 if cluster else i % 2 == 0) else 'B'
                pre = float(25 + 16 * level_i + rng.uniform(-12, 12))
                post = float(15 + .67 * pre + (delta + (level_i - 1) * 2 if arm == 'A' else 0) + rng.normal(0, 4) + (c % 3 - 1))
                rows.append(dict(participant=f'SYNTHETIC-{len(rows)+1}', arm=arm, level=level, class_code=f'C-{c}',
                                 pretest_score=pre, posttest_score=post, gain=post-pre))
    levels = sorted({r['level'] for r in rows})
    pre_mean = np.mean([r['pretest_score'] for r in rows])
    expected = {}
    for interaction in [False, True]:
        x = []
        for r in rows:
            a = float(r['arm'] == 'A')
            v = [1, a, (r['pretest_score']-pre_mean)/100] + [float(r['level'] == l) for l in levels[1:]]
            if interaction:
                v += [a*float(r['level'] == l) for l in levels[1:]]
            x.append(v)
        kwargs = dict(cov_type='cluster', cov_kwds=dict(groups=[r['class_code'] for r in rows], use_correction=True, df_correction=True)) if cluster else dict(cov_type='HC3')
        fit = sm.OLS([r['posttest_score'] for r in rows], x).fit(use_t=True, **kwargs)
        def contrast(w):
            test = fit.t_test(w)
            low, high = np.asarray(test.conf_int()).ravel()
            return dict(estimate=float(np.asarray(test.effect).item()), se=float(np.asarray(test.sd).item()), lower=float(low), upper=float(high), df=float(test.df_denom))
        w = np.zeros(len(x[0])); w[1] = 1
        if not interaction:
            expected['primary'] = contrast(w)
            a = [r['posttest_score'] for r in rows if r['arm']=='A']
            b = [r['posttest_score'] for r in rows if r['arm']=='B']
            sd = np.sqrt(((len(a)-1)*np.var(a,ddof=1)+(len(b)-1)*np.var(b,ddof=1))/(len(a)+len(b)-2))
            expected['standardized_difference'] = expected['primary']['estimate']/sd
        else:
            weights = {}
            for l in levels:
                w = np.zeros(len(x[0])); w[1] = 1
                if l != levels[0]: w[3+len(levels)-1+levels[1:].index(l)] = 1
                weights[l] = w
            expected['levels'] = {l: contrast(w) for l,w in weights.items()}
            expected['differences'] = [dict(left=l,right=r,effect=contrast(weights[l]-weights[r])) for i,l in enumerate(levels) for r in levels[i+1:]]
    fixtures.append(dict(name=name, allocation='class' if cluster else 'stratified', rows=rows, expected=expected))

out = dict(synthetic=True, oracle=f'statsmodels {statsmodels.__version__}; scipy {scipy.__version__}',
           t_critical={str(df): float(t.ppf(.975,df)) for df in [1,2,5,10,30,100,1000]}, fixtures=fixtures)
target = root/'oj-research/tests/Fixtures/research-analysis.json'
target.parent.mkdir(parents=True, exist_ok=True)
target.write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(f'Independent synthetic oracle saved: {target}; {len(fixtures)} scenarios')
