<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
define('EDU_GAMES_VERSION',(require EDU_GAMES_PATH.'plugin-package.php')['version']);
define('OJ_CONCEPT_LAB_PATH',dirname(EDU_GAMES_PATH).'/oj-concept-lab/');
define('OJ_CONCEPT_LAB_FILE',OJ_CONCEPT_LAB_PATH.'oj-concept-lab.php');
define('OJ_CONCEPT_LAB_VERSION','1.0.0');
require OJ_CONCEPT_LAB_PATH.'src/Core/Autoloader.php';
OjConceptLab\Core\Autoloader::register();
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return esc_attr($v);}
function esc_html_e($v,$domain=''){echo esc_html($v);}
function esc_url($v){return esc_attr($v);}
function esc_url_raw($v){return $v;}
function rest_url($v){return '/'.$v;}
function wp_create_nonce($v){return 'fixture-nonce';}
function wp_enqueue_style(...$args){}
function wp_enqueue_script(...$args){}
function wp_salt($v){return 'isolated-arcade-test-salt';}
function wp_verify_nonce($nonce,$v){return $nonce==='fixture-nonce';}
function wp_timezone(){return new DateTimeZone('Asia/Seoul');}
function wp_timezone_string(){return 'Asia/Seoul';}
function do_action(...$args){}
function get_date_from_gmt($v){return $v;}
function get_gmt_from_date($v){return $v;}
function get_block_wrapper_attributes($args){return 'class="'.esc_attr($args['class']).'"';}
function wp_get_current_user(){return (object)['display_name'=>'검증 학생 '.get_current_user_id()];}
class WP_REST_Response {
    public array $headers=[];
    public function __construct(public mixed $data,public int $status=200){}
    public function header($key,$value){$this->headers[$key]=$value;}
}
class Arcade_Test_Request extends WP_REST_Request {
    public function __construct(private array $body,private string $method='POST',private int $id=0){parent::__construct('cipher-vault',$body);}
    public function get_body(){return json_encode($this->body);}
    public function get_header($key){return $key==='X-WP-Nonce'?'fixture-nonce':'';}
    public function get_method(){return $this->method;}
    public function offsetGet(mixed $key):mixed{return $key==='id'?$this->id:parent::offsetGet($key);}
}
// SQLite is isolated test storage; production continues to use WordPress wpdb.
class Arcade_Test_DB {
    public string $prefix='wp_',$last_error='';public int $insert_id=0;public bool $fail=false;
    public function __construct(public PDO $pdo){}
    public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:$this->pdo->quote((string)$v);},$sql);}
    public function esc_like($v){return $v;}
    public function get_var($sql){$r=$this->get_row($sql,ARRAY_A);return $r?array_values($r)[0]:null;}
    public function get_row($sql,$format=ARRAY_A){$r=$this->get_results($sql,$format);return $r[0]??null;}
    public function get_results($sql,$format=ARRAY_A){try{$this->last_error='';return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$this->last_error=$e->getMessage();return null;}}
    public function query($sql){if($this->fail)return false;try{$this->last_error='';return $this->pdo->exec($sql);}catch(Throwable $e){$this->last_error=$e->getMessage();return false;}}
    public function insert($table,$row,...$ignored){$keys=array_keys($row);$sql='INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';if($this->fail)return false;$stmt=$this->pdo->prepare($sql);$stmt->execute(array_values($row));$this->insert_id=(int)$this->pdo->lastInsertId();return 1;}
}
function arcade_test_database(string $file): Arcade_Test_DB {
    $db=new Arcade_Test_DB(new PDO('sqlite:'.$file));$db->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->query('CREATE TABLE IF NOT EXISTS wp_edu_games_arcade_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,token TEXT UNIQUE,user_id INTEGER,game_slug TEXT,pack TEXT,status TEXT,revision INTEGER,state_json TEXT,score INTEGER,elapsed_ms INTEGER,wrong_count INTEGER,created_at TEXT,expires_ms INTEGER,completed_at TEXT)');
    return $db;
}
if(defined('ARCADE_TEST_LIBRARY'))return;
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$GLOBALS['test_user_id']=(int)($input['uid']??7);$GLOBALS['caps']=['edu_games_play'];
if(get_current_user_id()===100)$GLOBALS['caps']=array_merge($GLOBALS['caps'],['edu_games_manage','manage_options','oj_concept_lab_manage']);
$GLOBALS['wpdb']=arcade_test_database($input['database']);
$game=$input['game']??'cipher-vault';$action=$input['action'];
if($action==='html'){
    if(in_array($input['kind'],['hub','ranking'],true)){
        spl_autoload_register(static function($class){$prefix='CodingPanda\\OjPlatform\\';if(str_starts_with($class,$prefix)){$file=dirname(EDU_GAMES_PATH).'/oj-integration-rebuild/oj-platform/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;}});
        if($input['kind']==='ranking'){echo EduGames\Blocks\Ranking_Page_Renderer::render();exit;}
        $request=new CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1('addon.edu_games.games',get_current_user_id(),[],[],'ko_KR','split-hub-preview');
        echo (new EduGames\Addon\Public_Game_Hub_Page_Handler())->handle($request)->body();exit;
    }
    echo $input['kind']==='lab'?OjConceptLab\Frontend\Renderer::render($input['activity']??'',!empty($input['manage'])):EduGames\Blocks\Arcade_Renderer::render($game);exit;
}
if(!EduGames\Rest\Controllers\Arcade_Controller::allowed($game))$result=new WP_Error('denied','게임에 참여할 수 없습니다.',['status'=>403]);
elseif($action==='delete')$result=EduGames\Rest\Controllers\Arcade_Controller::remove($game,new Arcade_Test_Request([],'DELETE',(int)$input['id']));
else $result=EduGames\Rest\Controllers\Arcade_Controller::run($game,$action,new Arcade_Test_Request($input['body']??[]));
echo json_encode($result instanceof WP_Error?['status'=>$result->data['status']??500,'data'=>['message'=>$result->message]]:['status'=>$result->status,'data'=>$result->data],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
