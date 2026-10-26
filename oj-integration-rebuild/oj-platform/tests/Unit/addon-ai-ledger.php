<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\Kernel\Integration\Operations\PlatformAddonAiRequestLedger;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestPending;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiRequestOutcomeUncertain;
$root=dirname(__DIR__,2);require_once $root.'/src/Kernel/Autoloader.php';\CodingPanda\OjPlatform\Kernel\Autoloader::register($root);
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok){throw new RuntimeException($message);}++$checks;};
final class LedgerDatabaseFixture implements ApplicationDatabase
{
    public function __construct(public PDO $pdo){}
    public bool $capacityFull=false;
    public function rows(string $sql,array $values=[]):array{$sql=str_replace([' FOR UPDATE','OCTET_LENGTH('],['','LENGTH('],$sql);$s=$this->pdo->prepare(preg_replace('/%[ds]/','?',$sql));$s->execute($values);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function row(string $sql,array $values=[]):?array{return $this->rows($sql,$values)[0]??null;}
    public function value(string $sql,array $values=[]):mixed{if(str_contains($sql,'GET_LOCK(')||str_contains($sql,'RELEASE_LOCK(')){return 1;}if($this->capacityFull&&str_contains($sql,'SUM(')){return 67108864;}return array_values($this->row($sql,$values)??[null])[0];}
    public function insert(string $table,array $data,array $formats=[]):int{$s=$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$this->pdo->lastInsertId();}
    public function update(string $table,array $data,array $where,array $formats=[],array $whereFormats=[]):int{$s=$this->pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn($k)=>$k.'=?',array_keys($data))).' WHERE '.implode(' AND ',array_map(static fn($k)=>$k.'=?',array_keys($where))));$s->execute([...array_values($data),...array_values($where)]);return $s->rowCount();}
    public function delete(string $table,array $where,array $whereFormats=[]):int{$s=$this->pdo->prepare('DELETE FROM '.$table.' WHERE '.implode(' AND ',array_map(static fn($k)=>$k.'=?',array_keys($where))));$s->execute(array_values($where));return $s->rowCount();}
    public function beginTransaction():void{$this->pdo->beginTransaction();}
    public function commit():void{$this->pdo->commit();}
    public function rollBack():void{if($this->pdo->inTransaction()){$this->pdo->rollBack();}}
}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE addon_ai_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,request_hash TEXT UNIQUE,addon_id TEXT,actor_user_id INTEGER,input_hash TEXT,status TEXT,lease_token TEXT,lease_expires_at TEXT,result_json TEXT,created_at TEXT,updated_at TEXT)');
$db=new LedgerDatabaseFixture($pdo);$ledger=new PlatformAddonAiRequestLedger($db);$calls=0;
$operation=static function()use(&$calls):array{++$calls;return ['output'=>['lesson'=>'example'],'provider'=>'fixture','model'=>'fixture','fingerprint'=>'fixture'];};
$first=$ledger->execute('oj-primm',7,'run:1',str_repeat('a',64),$operation);
$check($first['output']['lesson']==='example'&&$calls===1,'First request dispatches once.');
$check($ledger->execute('oj-primm',7,'run:1',str_repeat('a',64),$operation)===$first&&$calls===1,'Same bound key reuses its committed result without provider call.');
$db->capacityFull=true;
try{$ledger->execute('oj-primm',7,'capacity',str_repeat('a',64),$operation);$check(false,'Capacity must block.');}catch(DomainException){$check($calls===1,'A full ledger rejects new dispatch before provider billing.');}
$db->capacityFull=false;
try{$ledger->execute('oj-primm',7,'run:1',str_repeat('b',64),$operation);$check(false,'Input drift must fail.');}catch(DomainException){$check($calls===1,'Same key refuses changed input/profile without dispatch.');}
$ledger->execute('oj-other',7,'run:1',str_repeat('a',64),$operation);
$ledger->execute('oj-primm',8,'run:1',str_repeat('a',64),$operation);
$check($calls===3,'Addon and actor are independent ledger boundaries.');
$hash=hash('sha256','oj-primm:7:active');
$db->insert('addon_ai_requests',['request_hash'=>$hash,'addon_id'=>'oj-primm','actor_user_id'=>7,'input_hash'=>str_repeat('c',64),'status'=>'inflight','lease_token'=>'worker','lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+300),'result_json'=>'','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]);
try{$ledger->execute('oj-primm',7,'active',str_repeat('c',64),$operation);$check(false,'Concurrent request must wait.');}catch(AiRequestPending){$check($calls===3,'An inflight request never dispatches a concurrent provider operation.');}
$db->update('addon_ai_requests',['lease_expires_at'=>gmdate('Y-m-d H:i:s',time()-1)],['request_hash'=>$hash]);
try{$ledger->execute('oj-primm',7,'active',str_repeat('c',64),$operation);$check(false,'Expired external outcome must not auto replay.');}catch(AiRequestOutcomeUncertain){$check($calls===3&&$db->row('SELECT status FROM addon_ai_requests WHERE request_hash=%s',[$hash])['status']==='uncertain','Lost worker outcome is fenced permanently for that key during retention.');}
try{$ledger->execute('oj-primm',7,'throws',str_repeat('d',64),static function():array{throw new RuntimeException('provider timeout');});}catch(RuntimeException){}
try{$ledger->execute('oj-primm',7,'throws',str_repeat('d',64),$operation);$check(false,'Provider timeout must not replay.');}catch(AiRequestOutcomeUncertain){$check($calls===3,'A provider error is not silently billed again under the same key.');}
try{$ledger->execute('oj-primm',9,'erase-during-call',str_repeat('e',64),static function()use($ledger):array{$ledger->eraseActor(9);return ['output'=>'late'];});$check(false,'Erased actor cannot commit late response.');}catch(DomainException){$check(!$ledger->hasActor(9),'Account erasure fences late responses without row recreation.');}
$check($ledger->hasActor(7),'Completed result remains bounded to its actor.');
$ledger->eraseActor(7);$check(!$ledger->hasActor(7)&&$ledger->hasActor(8),'Erasure removes only the selected actor records.');
$pdo->exec("UPDATE addon_ai_requests SET updated_at=datetime('now','-8 days')");
$cleanup=$ledger->cleanup();$check($cleanup['deleted']===1&&!$ledger->hasActor(8),'Independent cleanup expires terminal cached results after seven days.');
$stored=(string)$pdo->query('SELECT COUNT(*) FROM addon_ai_requests')->fetchColumn();$check($stored==='0','Cleanup leaves no expired result payload.');
echo "Addon AI durable request ledger: {$checks} checks passed.\n";
