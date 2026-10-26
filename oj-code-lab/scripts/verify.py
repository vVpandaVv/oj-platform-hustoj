#!/usr/bin/env python3
"""Local syntax, metadata, encoding and regression gates; no live services."""
from pathlib import Path
import argparse, json, re, shutil, subprocess, sys
sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")
ROOT = Path(__file__).resolve().parents[1]

def tool(name, override=None):
    found = override or shutil.which(name)
    if not found and name == 'php' and Path('D:/php/php.exe').is_file():
        found = 'D:/php/php.exe'
    if not found:
        raise RuntimeError(f'Required runtime not found: {name}')
    return found

def run(command):
    result = subprocess.run(command, capture_output=True, text=True, encoding='utf-8', errors='replace')
    if result.returncode:
        raise RuntimeError(f'Command failed: {command}\n{result.stdout}\n{result.stderr}')
    return result.stdout.strip()

def verify(php=None, node=None):
    php, node = tool('php', php), tool('node', node)
    text_files=[]
    for p in ROOT.rglob('*'):
        if p.is_file() and p.suffix in {'.php','.js','.cjs','.css','.csv','.md','.txt','.json','.py'}:
            data=p.read_bytes()
            if data.startswith((b'\xef\xbb\xbf',b'\xff\xfe',b'\xfe\xff')):
                raise RuntimeError(f'BOM not allowed: {p}')
            data.decode('utf-8', errors='strict')
            text_files.append(p)
    php_files=sorted(ROOT.rglob('*.php'))
    js_files=sorted(ROOT.rglob('*.js'))+sorted(ROOT.rglob('*.cjs'))
    for p in php_files: run([php,'-l',str(p)])
    for p in js_files: run([node,'--check',str(p)])
    main=(ROOT/'oj-code-lab.php').read_text(encoding='utf-8')
    version=re.search(r'^ \* Version: (\S+)',main,re.M).group(1)
    constant=re.search(r"define\('OJ_CODE_LAB_VERSION', '([^']+)'\)",main).group(1)
    package=json.loads(run([php,'-r',"echo json_encode(require $argv[1]);",str(ROOT/'plugin-package.php')]))
    if not version == constant == package['version']:
        raise RuntimeError('Plugin header, constant and package version differ')
    for key in ['id','name','version','main_file','requires','soft_requires','provides']:
        if key not in package: raise RuntimeError(f'Missing package field: {key}')
    if package['id'] != ROOT.name or not (ROOT/package['main_file']).is_file():
        raise RuntimeError('Invalid plugin package identity/main file')
    # Baseline content hashes make any bundled-data update deliberate.
    import hashlib
    inventory=json.loads((ROOT/'docs/dataset-inventory.json').read_text(encoding='utf-8'))
    actual={p.name for p in (ROOT/'resources/sample-datasets').glob('*.csv')}
    if actual != {item['file'] for item in inventory}:
        raise RuntimeError('Dataset inventory does not match bundled files')
    for item in inventory:
        p=ROOT/'resources/sample-datasets'/item['file']
        if hashlib.sha256(p.read_bytes()).hexdigest()!=item['sha256']:
            raise RuntimeError(f'Dataset checksum changed: {p.name}')
    package_output=run([sys.executable,str(ROOT/'tests/build-release.test.py')])
    dataset_read_output=run([php,str(ROOT/'tests/dataset-read-failures.php')])
    kaggle_output=run([php,str(ROOT/'tests/kaggle-pagination.php')])
    archive_output=run([php,str(ROOT/'tests/dataset-archive.php')])
    formats_output=run([php,str(ROOT/'tests/dataset-formats.php')])
    php_output=run([php,str(ROOT/'tests/regression.php')])
    preflight_output=run([php,str(ROOT/'tests/operations-preflight.test.php')])
    debugger_output=run([sys.executable,'-I',str(ROOT/'tests/debugger-harness.py')])
    js_output=run([node,'--test',str(ROOT/'tests/lab.test.cjs'),str(ROOT/'tests/runtime.test.cjs'),str(ROOT/'tests/dashboard.test.cjs'),str(ROOT/'tests/cell-editor.test.cjs'),str(ROOT/'tests/datasets-admin.test.cjs'),str(ROOT/'tests/markdown.test.cjs'),str(ROOT/'tests/text-cell.test.cjs')])
    return {'version':version,'php_runtime':run([php,'-r','echo PHP_VERSION;']),
            'node_runtime':run([node,'--version']),'utf8_files':len(text_files),
            'php_lint_files':len(php_files),'js_syntax_files':len(js_files),
            'dataset_files':len(inventory),'package_regression':package_output,'dataset_read_regression':dataset_read_output,'kaggle_pagination':kaggle_output,'dataset_archive':archive_output,'dataset_formats':formats_output,'php_regression':php_output,'js_regression':js_output,'operations_preflight':preflight_output,'debugger_harness':debugger_output,
            'scope':'Local unit doubles only; no real WordPress, DB, browser or Kaggle acceptance.'}

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php');parser.add_argument('--node');parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    try:
        result=verify(args.php,args.node)
        output=json.dumps(result,ensure_ascii=False,indent=2)
        if args.report:
            args.report.parent.mkdir(parents=True,exist_ok=True)
            args.report.write_text(output+'\n',encoding='utf-8')
        print(output)
    except (RuntimeError,ValueError,AttributeError,KeyError) as exc:
        print(str(exc),file=sys.stderr);sys.exit(1)
