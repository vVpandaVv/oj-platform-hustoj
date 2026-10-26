<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Rankings\Leaderboard_Service as Rankings;
use EduGames\Rankings\Run_Leaderboard;
use EduGames\Progress\Store;
use EduGames\Progress\Validator;
use EduGames\Reports\Report;
use EduGames\Reports\Roster;
use EduGames\Rest\Controllers\Progress_Controller;
use EduGames\Rest\Controllers\Report_Controller;
use CodingPanda\OjPlatform\AddonApi\V1 as Api;

// Executes the paging/report queries in memory; this is not a MySQL migration test.
final class Query_DB {
    public string $prefix='wp_', $last_error='';
    public PDO $db;
    public bool $fail=false;
    public function __construct(){ $this->db=new PDO('sqlite::memory:'); }
    public function prepare(string $sql,...$args): string {
        if(count($args)===1&&is_array($args[0]))$args=$args[0];$i=0;
        return preg_replace_callback('/%[sdf]/',function($m)use($args,&$i){$v=$args[$i++];return $m[0]==='%s'?$this->db->quote((string)$v):($m[0]==='%d'?(string)(int)$v:(string)(float)$v);},$sql);
    }
    private function run(string $sql): PDOStatement|false {
        $this->last_error='';
        if($this->fail){$this->last_error='injected failure';return false;}
        // Only the upsert dialect differs. Rank/report SELECTs run unmodified.
        $sql=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(user_id,game_slug,record_key) DO UPDATE SET',$sql);
        $sql=str_replace(array('GREATEST(','LEAST(','VALUES(best_value)','VALUES(updated_at)'),array('MAX(','MIN(','excluded.best_value','excluded.updated_at'),$sql);
        try{return $this->db->query($sql);}catch(PDOException $e){$this->last_error=$e->getMessage();return false;}
    }
    public function get_results($sql,$format){$q=$this->run($sql);return $q?$q->fetchAll(PDO::FETCH_ASSOC):null;}
    public function get_row($sql,$format){$q=$this->run($sql);return $q?($q->fetch(PDO::FETCH_ASSOC)?:null):null;}
    public function get_var($sql){$q=$this->run($sql);return $q?($q->fetchColumn()??null):null;}
    public function query($sql){$q=$this->run($sql);return $q?$q->rowCount():false;}
    public function insert(string $table,array $row): int {
        $sql='INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
        $this->db->prepare($sql)->execute(array_values($row));return (int)$this->db->lastInsertId();
    }
}
function get_date_from_gmt($date){return $date;}
function get_gmt_from_date($date){return $date;}
$checks=0;
function check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $call): void {try{$call();}catch(InvalidArgumentException){check(true,'rejected');return;}throw new RuntimeException('Expected validation failure');}
final class Request extends WP_REST_Request {
    public function __construct(string $game,private array $params=[],private string $method='GET',array $data=[]){parent::__construct($game,$data);}
    public function get_param($key){return $this->params[$key]??null;}
    public function get_params(){return $this->params;}
    public function get_method(){return $this->method;}
}
$wpdb=new Query_DB();
$wpdb->db->exec('CREATE TABLE wp_edu_games_scores (id INTEGER PRIMARY KEY, user_id INTEGER, game_slug TEXT, player_name TEXT, score INTEGER, rounds_played INTEGER DEFAULT 1, correct_count INTEGER DEFAULT 1, duration_seconds INTEGER DEFAULT 300, accuracy REAL DEFAULT 100, created_at TEXT DEFAULT "2026-09-12 12:00:00", meta TEXT DEFAULT "{}")');
$wpdb->db->exec('CREATE TABLE wp_edu_games_teams (id INTEGER PRIMARY KEY, name TEXT)');
$wpdb->db->exec('CREATE TABLE wp_edu_games_team_members (team_id INTEGER, user_id INTEGER)');
$wpdb->db->exec('CREATE TABLE wp_edu_games_learning_runs (id INTEGER PRIMARY KEY, user_id INTEGER, game_slug TEXT, content_version TEXT, mode TEXT, level TEXT, score REAL, started_at TEXT DEFAULT "2026-09-12 12:00:00", completed_at TEXT DEFAULT "2026-09-12 12:05:00", state_json TEXT DEFAULT "{}")');
$wpdb->db->exec('CREATE TABLE wp_edu_games_progress (user_id INTEGER, game_slug TEXT, record_key TEXT, best_value INTEGER, updated_at TEXT, PRIMARY KEY(user_id,game_slug,record_key))');
for($id=1;$id<=180;$id++)$wpdb->insert('wp_edu_games_scores',array('user_id'=>$id,'game_slug'=>'binary-conversion','player_name'=>'Student '.$id,'score'=>180-$id));
$wpdb->insert('wp_edu_games_scores',array('user_id'=>1,'game_slug'=>'binary-conversion','player_name'=>'Older attempt','score'=>1));
$p=Rankings::page('binary-conversion',2,20);
check($p['pagination']['total']===180&&count($p['leaderboard'])===20,'page sizes');
check($p['leaderboard'][0]['rank']===21&&$p['leaderboard'][0]['userId']===21,'global rank offset');
$p=Rankings::page('binary-conversion',99,50);
check($p['pagination']['page']===4&&count($p['leaderboard'])===30&&$p['leaderboard'][29]['score']===0,'last page includes zero');
check(count(Rankings::for_game('binary-conversion'))===180,'legacy API remains complete');
check(Rankings::page('binary-conversion',2,20,range(1,30))['pagination']['total']===30,'member scope');
check(Rankings::page('binary-conversion',1,20,[])['pagination']['total']===0,'empty scope');
$wpdb->insert('wp_edu_games_teams',array('id'=>1,'name'=>'Class A'));
for($id=1;$id<=60;$id++)$wpdb->insert('wp_edu_games_team_members',array('team_id'=>1,'user_id'=>$id));
$p=EduGames\Groups\Group_Ranking_Service::page('binary-conversion');
check($p['standings'][0]['memberCount']===60&&$p['standings'][0]['averageScore']===149.5,'team average uses all scoring members');
$version=EduGames\Boards\Catalog::version('outlier-detection');
$wpdb->insert('wp_edu_games_learning_runs',array('user_id'=>7,'game_slug'=>'outlier-detection','content_version'=>EduGames\Boards\Catalog::VERSION,'mode'=>'strategy','level'=>'old-board','score'=>1000));
for($id=1;$id<=30;$id++)$wpdb->insert('wp_edu_games_learning_runs',array('user_id'=>$id,'game_slug'=>'outlier-detection','content_version'=>$version,'mode'=>'strategy','level'=>'1','score'=>$id<=19?1000:900));
$p=Run_Leaderboard::page('outlier-detection',$version,'strategy','1',2,20);
check(count($p['leaderboard'])===10&&$p['leaderboard'][0]['rank']===20,'ties span page boundary');
check(!isset($p['leaderboard'][0]['user_id']),'ranking anonymized');
check(Run_Leaderboard::page('outlier-detection','old','strategy','1')['pagination']['total']===0,'version separation');

$sample=EduGames\Games\Modules\Image_Data\Image_Data_Module::rules()['samples'][0];
$pixels=str_split(implode('',$sample['data']));
$payload=array('sampleId'=>$sample['id'],'pixels'=>$pixels);
check(Validator::entries('image-data',$payload)===array($sample['id']=>1),'canonical image');
$wrong=$payload;$wrong['pixels'][0]=$pixels[0]==='0'?'1':'0';rejects(fn()=>Validator::entries('image-data',$wrong));
$wrong['pixels']=array('unexpected'=>$pixels);rejects(fn()=>Validator::entries('image-data',$wrong));
$typing=EduGames\Games\Modules\Code_Typing\Code_Typing_Module::rules();
$code=array_values(array_filter($typing['samples'],fn($s)=>$s['mode']==='short'))[0];
$key=$code['language'].':short:'.$code['id'];$typed=array('key'=>$key,'sampleId'=>$code['id'],'input'=>$code['content'],'seconds'=>60);
check(Validator::entries('code-typing',$typed)[$key]===count(preg_split('//u',$code['content'],-1,PREG_SPLIT_NO_EMPTY)),'practice speed');
rejects(fn()=>Validator::entries('code-typing',array_replace($typed,array('input'=>'bad'))));
rejects(fn()=>Validator::entries('code-typing',array_replace($typed,array('seconds'=>0))));
$examId=$wpdb->insert('wp_edu_games_scores',array('user_id'=>7,'game_slug'=>'code-typing','score'=>300,'meta'=>json_encode(array('language'=>'python','mode'=>'assessment','cpm'=>123.4))));
check(Validator::entries('code-typing',array('scoreId'=>$examId))===array('python:assessment:300'=>123),'exam uses complete stored session, including partial final sample');
$other=$wpdb->insert('wp_edu_games_scores',array('user_id'=>8,'game_slug'=>'device-matching','score'=>500,'meta'=>json_encode(array('completedStages'=>4,'totalStars'=>11,'mistakes'=>3))));
rejects(fn()=>Validator::entries('device-matching',array('scoreId'=>$other)));
$own=$wpdb->insert('wp_edu_games_scores',array('user_id'=>7,'game_slug'=>'device-matching','score'=>500,'meta'=>json_encode(array('completedStages'=>4,'totalStars'=>11,'mistakes'=>3))));
check(Validator::entries('device-matching',array('scoreId'=>$own))===array('stars'=>11,'mistakes'=>3),'own completed card record');
foreach([2,8,4]as$value)check(Store::put(7,'device-matching','stars',$value),'upsert');
foreach([9,2,5]as$value)Store::put(7,'device-matching','mistakes',$value);
check((array)Store::read(7,'device-matching')['records']===array('mistakes'=>2,'stars'=>8),'atomic max/min');
check((array)Store::read(8,'device-matching')['records']===[],'account isolation');
check(Progress_Controller::handle(new Request('image-data',['accountUserId'=>8]))->data['status']===403,'stale login rejected');
check(Progress_Controller::handle(new Request('image-data',['accountUserId'=>[]]))->data['status']===403,'malformed account rejected');
$saved=Progress_Controller::handle(new Request('image-data',['accountUserId'=>7],'POST',$payload));
check((array)$saved['records']===array($sample['id']=>1),'progress endpoint saves');

$p=Report::read(array_merge(range(1,30),[181]),'binary-conversion','arcade','','',2,20);
check($p['participants']===30&&$p['pagination']['total']===31&&count($p['students'])===11,'report roster paging');
check($p['students'][10]['attempts']===0&&$p['students'][10]['best']===null,'nonparticipant included');
check(Report::read([7],'binary-conversion','arcade','2026-09-13 00:00:00','',1,20)['participants']===0,'date filter');
$p=Report::read([7,181],'outlier-detection','strategy','','',1,20);
check($p['students'][0]['cleared']===1&&$p['students'][0]['stars']===3&&$p['students'][1]['stars']===0,'board mastery');
check($p['students'][0]['attempts']===1,'old outlier season stays out of current classroom report');
$state=array('ids'=>['a','b'],'records'=>[['firstCorrect'=>false,'result'=>['correct'=>true],'hintUsed'=>false,'skill'=>'test','attempts'=>2]]);
$wpdb->insert('wp_edu_games_learning_runs',array('user_id'=>7,'game_slug'=>'code-typing','content_version'=>EduGames\Learning\Catalog::VERSION,'mode'=>'assessment','level'=>'all','score'=>0,'state_json'=>json_encode($state)));
$p=Report::read([7],'code-typing','assessment','','',1,20);
check($p['students'][0]['first']===0&&$p['students'][0]['final']===1&&$p['students'][0]['total']===2,'first and corrected answers separate');
check(!EduGames\Support\Capabilities::can_manage(),'student cannot manage reports');
check(is_wp_error(Roster::learners('class','1','r1')),'unavailable roster fails closed');
$wpdb->fail=true;
check(is_wp_error(Rankings::page('binary-conversion')),'ranking query failure visible');
check(is_wp_error(Store::read(7,'image-data')),'progress query failure visible');
check(is_wp_error(Report::read([7],'binary-conversion','arcade','','',1,20)),'report query failure visible');
$wpdb->fail=false;

// Use the installed public Addon API contracts, not platform database tables.
spl_autoload_register(static function($class){$prefix='CodingPanda\\OjPlatform\\';if(str_starts_with($class,$prefix)){ $file=dirname(EDU_GAMES_PATH).'/oj-integration-rebuild/oj-platform/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file; }});
$gateway=new class implements Api\AddonAssignmentRosterGatewayV1 {
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 {
        return new Api\AddonContextPageV1(true,[new Api\AddonContextSummaryV1('class','1','Class A','active','r1')],'',null);
    }
    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $q): Api\AddonAssignmentLearnerPageV1 {
        if($q->contextId()!=='1'||$q->expectedContextRevision()!=='r1')return new Api\AddonAssignmentLearnerPageV1(false,[],'',null,new Api\AddonApiProblemV1('addon_context_denied','denied','test-v1',false));
        return new Api\AddonAssignmentLearnerPageV1(true,[new Api\AddonAssignmentLearnerV1(7,'m1')],'','roster1',null);
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
check(count(Roster::contexts()['items'])===1,'teaching contexts');
check(Roster::learners('class','1','r1')===[7],'trusted roster');
check(is_wp_error(Roster::learners('class','2','r1')),'foreign class denied');
check(is_wp_error(Roster::learners('class','1','old')),'stale roster denied');
$params=['type'=>'class','id'=>'1','revision'=>'r1','game'=>'binary-conversion','mode'=>'arcade'];
check(Report_Controller::students(new Request('', $params))['pagination']['total']===1,'controller uses authorized roster only');
check(is_wp_error(Report_Controller::students(new Request('', array_replace($params,['from'=>'2026-02-30'])))),'invalid date denied');
echo "v1: {$checks} passed (SQLite query fixture; no live migration)\n";
