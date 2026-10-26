"""Rehearse old ZIP -> new ZIP -> full DB restore on a disposable loopback DB only."""
from pathlib import Path
from datetime import datetime,timezone,timedelta
import argparse,hashlib,json,os,subprocess,sys
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')
parser=argparse.ArgumentParser()
for key in ('php','php-ext','wp','platform','old-plugin','new-plugin','db-bin','config','work-dir'):
    parser.add_argument('--'+key,type=Path,required=True)
parser.add_argument('--db-port',type=int,choices=(33368,33388),default=33368)
parser.add_argument('--fresh-sample-keys',nargs='+')
a=parser.parse_args();work=a.work_dir.resolve();work.mkdir(parents=True,exist_ok=True)
config=json.loads(a.config.read_text(encoding='utf-8'))
if config['host']!='127.0.0.1' or int(config['port'])!=a.db_port:raise RuntimeError('Isolated loopback DB required')
state=work/'rehearsal-state.json'
if state.exists():raise RuntimeError('Use a new rehearsal work directory')
# Credential file is private test input; never print or include it in release evidence.
key=str(config['password'])
if not key.isalnum():raise RuntimeError('Use generated alphanumeric test credentials')
# Only the disposable child process receives the secret; no password in arguments.
# This guarded loopback fixture has no TLS certificate or Windows Schannel credentials.
db_env=dict(os.environ,MYSQL_PWD=key)
def db_command(name):
    return [str((a.db_bin/name).resolve()),'--no-defaults','--skip-ssl','--host=127.0.0.1','--port='+str(a.db_port),'--user='+config['user'],'--protocol=tcp']
env=dict(os.environ,OCL_TEST_CONFIG=str(a.config.resolve()),OCL_TEST_WP=str(a.wp.resolve()),OCL_TEST_PLATFORM=str(a.platform.resolve()),OCL_REHEARSAL_STATE=str(state),OCL_REHEARSAL_DB_PORT=str(a.db_port))
php=[str(a.php.resolve()),'-d','extension_dir='+str(a.php_ext.resolve()),'-d','extension=mysqli','-d','extension=openssl','-d','extension=mbstring','-d','memory_limit=256M',str(Path(__file__).with_suffix('.php').resolve())]
phases=[]
def phase(name,plugin,alternate_state=None):
    phase_env=dict(env,OCL_TEST_PLUGIN=str(plugin.resolve()))
    if alternate_state is not None:phase_env.update(OCL_REHEARSAL_STATE=str(alternate_state),OCL_REHEARSAL_FRESH_SAMPLE_KEYS=json.dumps(a.fresh_sample_keys))
    result=subprocess.run(php+[name],env=phase_env,capture_output=True,text=True,encoding='utf-8',timeout=120)
    if result.returncode:raise RuntimeError('Rehearsal '+name+' failed: '+result.stderr+' '+result.stdout)
    report=json.loads(result.stdout)
    if alternate_state is None:phases.append(report)
    print('PASS '+name+': '+str(len(report['checks']))+' checks',flush=True)
    return report
def dbname():
    import re
    name=json.loads(state.read_text(encoding='utf-8'))['database']
    if not re.fullmatch('ocl_test_ops_[0-9]{14}_[a-f0-9]{6}',name):raise RuntimeError('Unexpected DB')
    return name
def snapshot(name):
    file=work/(name+'.sql')
    with file.open('wb') as out:
        result=subprocess.run(db_command('mariadb-dump.exe')+['--single-transaction','--routines','--events','--triggers','--hex-blob','--skip-dump-date',dbname()],env=db_env,stdout=out,stderr=subprocess.PIPE,timeout=120,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    if result.returncode:raise RuntimeError('Backup failed: '+result.stderr.decode('utf-8',errors='replace').replace(key,'[redacted]')[-1500:])
    if file.stat().st_size<1000:raise RuntimeError('Empty backup')
    print('PASS '+name+' database backup',flush=True);return file
def restore(file):
    with file.open('rb') as data:
        result=subprocess.run(db_command('mariadb.exe')+[dbname()],env=db_env,stdin=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=120,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    if result.returncode:raise RuntimeError('Restore failed: '+result.stderr.decode('utf-8',errors='replace').replace(key,'[redacted]')[-1500:])
    print('PASS database restore from '+file.name,flush=True)
try:
    phase('install',a.old_plugin);before=snapshot('before-upgrade')
    phase('upgrade',a.new_plugin);after=snapshot('after-upgrade')
    restore(before);phase('verify-old',a.old_plugin)
    restore(after);phase('verify-new',a.new_plugin)
    report={'date':datetime.now(timezone(timedelta(hours=9))).date().isoformat(),'date_timezone':'Asia/Seoul','from_version':phases[0]['plugin'],'to_version':phases[1]['plugin'],'scope':'isolated local WordPress, actual ZIP files and full MariaDB dumps; no production server or full platform login','phases':phases,'checks':sum(len(p['checks']) for p in phases)+4,'backup_sha256':{f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in (before,after)},'restore_verified':True}
    if a.fresh_sample_keys:
        report['fresh_install']=phase('fresh-install',a.new_plugin,work/'fresh-install-state.json')
        report['fresh_install']['expected_sample_keys']=a.fresh_sample_keys
    report['total_checks']=report['checks']+len(report.get('fresh_install',{}).get('checks',[]))
    (work/'operations-rehearsal.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
finally:
    db_env.pop('MYSQL_PWD',None)
