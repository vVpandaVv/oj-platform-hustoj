<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use CodingPanda\OjPlatform\AddonApi\V1 as Api;

spl_autoload_register(static function($class){$prefix='CodingPanda\\OjPlatform\\';if(str_starts_with($class,$prefix)){$file=dirname(EDU_GAMES_PATH).'/oj-integration-rebuild/oj-platform/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;}});
function do_action($name,...$args){$GLOBALS['typing_audit'][]=array($name,$args);}
function rest_url($path){return '/'.$path;}

final class Typing_Test_DB {
    public string $prefix='wp_',$last_error='';public int $insert_id=0;public bool $fail=false;public PDO $db;
    public function __construct(string $file=':memory:'){$this->db=new PDO('sqlite:'.$file);$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE IF NOT EXISTS wp_edu_games_typing_evaluations (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INTEGER,title TEXT,status TEXT,config_json TEXT,audit_json TEXT,created_at TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS wp_edu_games_typing_targets (evaluation_id INTEGER,user_id INTEGER,groups_json TEXT,PRIMARY KEY(evaluation_id,user_id))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS wp_edu_games_typing_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,token TEXT UNIQUE,user_id INTEGER,evaluation_id INTEGER,attempt_no INTEGER,challenge TEXT,version TEXT,status TEXT,revision INTEGER DEFAULT 0,last_request TEXT DEFAULT "",state_json TEXT,metrics_json TEXT,deadline_at TEXT,effective_cpm REAL DEFAULT 0,input_accuracy REAL DEFAULT 0,qualified INTEGER DEFAULT 0,started_at TEXT,completed_at TEXT,UNIQUE(evaluation_id,user_id,attempt_no))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS wp_edu_games_scores (id INTEGER PRIMARY KEY,game_slug TEXT,score INTEGER,user_id INTEGER)');
    }
    public function prepare(string $sql,...$args): string {if(count($args)===1&&is_array($args[0]))$args=$args[0];$i=0;return preg_replace_callback('/%[sdf]/',function($m)use($args,&$i){$v=$args[$i++];return $m[0]==='%s'?$this->db->quote((string)$v):($m[0]==='%d'?(string)(int)$v:(string)(float)$v);},$sql);}
    private function run(string $sql): PDOStatement|false {$this->last_error='';if($this->fail){$this->last_error='injected';return false;}try{return $this->db->query($sql==='START TRANSACTION'?'BEGIN':$sql);}catch(PDOException $e){$this->last_error=$e->getMessage();return false;}}
    public function get_var($sql){$q=$this->run($sql);return $q?$q->fetchColumn():null;}
    public function get_row($sql,$format){$q=$this->run($sql);return $q?($q->fetch(PDO::FETCH_ASSOC)?:null):null;}
    public function get_results($sql,$format){$q=$this->run($sql);return $q?$q->fetchAll(PDO::FETCH_ASSOC):null;}
    public function query($sql){$q=$this->run($sql);return $q?$q->rowCount():false;}
    public function insert(string $table,array $row): int|false {$sql='INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';try{if($this->fail)return false;$q=$this->db->prepare($sql);$q->execute(array_values($row));$this->insert_id=(int)$this->db->lastInsertId();return 1;}catch(PDOException $e){$this->last_error=$e->getMessage();return false;}}
}
function typing_test_runtime(): void {
    $gateway=new class implements Api\AddonAssignmentRosterGatewayV1 {
        public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $q): Api\AddonContextPageV1 {return new Api\AddonContextPageV1(true,[new Api\AddonContextSummaryV1('group','g1','1반','active','r1'),new Api\AddonContextSummaryV1('group','g2','2반','active','r1')],'',null);}
        public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $q): Api\AddonAssignmentLearnerPageV1 {
            if(get_current_user_id()!==1||!in_array($q->contextId(),array('g1','g2'),true)||$q->expectedContextRevision()!=='r1')return new Api\AddonAssignmentLearnerPageV1(false,[],'',null,new Api\AddonApiProblemV1('addon_context_denied','denied','typing-test',false));
            $users=$q->contextId()==='g1'?range(7,36):range(37,66);
            return new Api\AddonAssignmentLearnerPageV1(true,array_map(static fn($id)=>new Api\AddonAssignmentLearnerV1($id,'m'.$id),$users),'','roster1',null);
        }
        public function authorize(Api\AddonContextAccessRequestV1 $r): Api\AddonContextAccessDecisionV1 {throw new LogicException('unused');}
        public function snapshot(Api\AddonContextSnapshotRequestV1 $r): Api\AddonContextSnapshotResultV1 {throw new LogicException('unused');}
    };
    $runtime=new class($gateway) implements Api\AddonRuntimeContextV1 {
        public function __construct(private Api\AddonContextGatewayV1 $gateway){}
        public function contexts(): Api\AddonContextGatewayV1{return $this->gateway;}
        public function snapshot(): Api\AddonRuntimeSnapshotV1{throw new LogicException('unused');}
        public function aiTasks(): Api\AddonAiTaskGatewayV1{throw new LogicException('unused');}
    };
    (new ReflectionProperty(EduGames\Addon\AiModelLab\Runtime_Bridge::class,'runtime'))->setValue(null,$runtime);
}
