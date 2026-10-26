"""Exercise actual Platform services on a private, disposable loopback MariaDB instance."""
from pathlib import Path
from datetime import datetime,timezone,timedelta
import argparse,hashlib,json,os,secrets,shutil,socket,subprocess,sys,tempfile,time
sys.stdout.reconfigure(encoding='utf-8')
ROOT=Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser()
p.add_argument('--wp',type=Path,default=ROOT/'_notes/tmp/oj-ml-1.0-integration/wp71/wordpress')
p.add_argument('--php',type=Path,default=ROOT/'_notes/tmp/oj-ml-1.0-integration/php83/php.exe')
p.add_argument('--platform',type=Path,default=ROOT/'oj-integration-rebuild/oj-platform')
p.add_argument('--plugin',type=Path,default=ROOT/'oj-code-lab')
p.add_argument('--db-bin',type=Path,default=ROOT/'_notes/tmp/oj-ml-1.0-integration/db/mariadb-11.4.10-winx64/bin')
p.add_argument('--report',type=Path,default=ROOT/'_notes/oj-code-lab-platform-acceptance-20260909.json')
a=p.parse_args()
for key in ('wp','php','platform','plugin','db_bin','report'):setattr(a,key,getattr(a,key).resolve())
PORT=33388
with socket.socket() as probe:
 if probe.connect_ex(('127.0.0.1',PORT))==0:raise RuntimeError('Dedicated test port is already occupied; no process touched')
tmp=(ROOT/'_notes/tmp').resolve();tmp.mkdir(parents=True,exist_ok=True)
work=Path(tempfile.mkdtemp(prefix='ocl-platform-acceptance-',dir=tmp)).resolve()
if work.parent!=tmp or not work.name.startswith('ocl-platform-acceptance-'):raise RuntimeError('Unsafe test directory')
started=datetime.now(timezone.utc).isoformat()
password=secrets.token_hex(24);db=None;report={'passed':False,'checks':[]}
flags=getattr(subprocess,'CREATE_NO_WINDOW',0)
def run(cmd,**kw):return subprocess.run([str(v) for v in cmd],capture_output=True,text=True,encoding='utf-8',errors='replace',creationflags=flags,**kw)
try:
 data=work/'data';log=work/'db.log'
 init=run([a.db_bin/'mariadb-install-db.exe','--datadir='+str(data),'--password='+password,'--port='+str(PORT)],timeout=90)
 if init.returncode:raise RuntimeError('Private MariaDB initialization failed')
 with log.open('wb') as out:
  db=subprocess.Popen([str(a.db_bin/'mariadbd.exe'),'--datadir='+str(data),'--bind-address=127.0.0.1','--port='+str(PORT),'--max-allowed-packet=67108864','--console'],stdout=out,stderr=subprocess.STDOUT,creationflags=flags)
 for _ in range(100):
  if db.poll() is not None:raise RuntimeError('Private MariaDB stopped during startup')
  with socket.socket() as probe:
   if probe.connect_ex(('127.0.0.1',PORT))==0:break
  time.sleep(.1)
 else:raise RuntimeError('Private MariaDB startup timed out')
 private_report=work/'result.json'
 env=dict(os.environ,OCL_PLATFORM_TEST_PASSWORD=password,OCL_PLATFORM_TEST_WP=str(a.wp),OCL_PLATFORM_TEST_ROOT=str(a.platform),OCL_PLATFORM_TEST_PLUGIN=str(a.plugin),OCL_PLATFORM_TEST_WORK=str(work),OCL_PLATFORM_TEST_REPORT=str(private_report))
 cmd=[a.php,'-d','extension_dir='+str(a.php.parent/'ext')]
 for ext in ('mysqli','openssl','mbstring','zip','fileinfo','curl'):cmd+=['-d','extension='+ext]
 cmd+=['-d','memory_limit=512M',Path(__file__).with_suffix('.php')]
 result=run(cmd,env=env,timeout=180)
 if private_report.exists():report=json.loads(private_report.read_text(encoding='utf-8'))
 if result.returncode:
  report['runner_error']=result.stderr.replace(password,'[redacted]')[-3000:]
  if not report['runner_error']:report['runner_error']=result.stdout.replace(password,'[redacted]')[-3000:]
 else:print(result.stdout.strip())
except Exception as error:report['runner_error']=str(error).replace(password,'[redacted]')
finally:
 if db is not None and db.poll() is None:
  db.terminate()
  try:db.wait(timeout=15)
  except subprocess.TimeoutExpired:db.kill();db.wait(timeout=10)
 report['started_at_utc']=started
 report['completed_at_utc']=datetime.now(timezone.utc).isoformat()
 report['date']=datetime.now(timezone(timedelta(hours=9))).date().isoformat()
 report['date_timezone']='Asia/Seoul'
 report['isolated_port']=PORT
 report['test_environment_cleaned']=False
 if work.parent!=tmp or not work.name.startswith('ocl-platform-acceptance-'):raise RuntimeError('Unsafe cleanup target')
 for attempt in range(5):
  try:shutil.rmtree(work);report['test_environment_cleaned']=True;break
  except OSError:
   if attempt==4:raise
   time.sleep(.3)
 a.report.parent.mkdir(parents=True,exist_ok=True)
 a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(json.dumps({'passed':report.get('passed',False),'checks':len(report.get('checks',[])),'report':str(a.report),'cleaned':report['test_environment_cleaned'],'error':report.get('runner_error',report.get('error'))},ensure_ascii=False))
sys.exit(0 if report.get('passed') else 1)
