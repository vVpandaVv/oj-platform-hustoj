<?php
declare(strict_types=1);
require __DIR__.'/typing-support.php';
use EduGames\Typing\{Repository,Service,Rules};
use EduGames\Rest\Controllers\Typing_Controller;
$p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$file=$p['db']??'';$allowed=realpath(dirname(__DIR__,2).'/_notes/tmp');
if(!is_string($file)||!$allowed||!str_starts_with(str_replace('\\','/',dirname($file)).'/',str_replace('\\','/',$allowed).'/'))throw new RuntimeException('Fixture DB must be inside _notes/tmp.');
$wpdb=new Typing_Test_DB($file);$GLOBALS['test_user_id']=(int)($p['actor']??7);$GLOBALS['caps']=$GLOBALS['test_user_id']===1?array('edu_games_play','edu_games_manage'):array('edu_games_play');typing_test_runtime();
$names=array('김하늘','이준서','박지우','최서연','정시우','한유진','윤다은','강지호','신예린','임서준','조수아','송민재','오지안','백도윤','서이현','문가은','홍서진','안유빈','장태오','유수현');
foreach(range(7,66) as $index=>$user)$GLOBALS['test_user_names'][$user]=$names[$index%count($names)].($index>=count($names)?' '.(intdiv($index,count($names))+1):'');
function register_rest_route($namespace,$path,$config){$GLOBALS['typing_routes'][]=array($path,$config);}
final class Typing_Request extends WP_REST_Request {
    public function __construct(private array $params,private array $body){parent::__construct('code-typing',$body);}
    public function get_param($key){return $this->params[$key]??null;}
    public function offsetGet(mixed $key): mixed{return $this->params[$key]??null;}
    public function offsetExists(mixed $key): bool{return isset($this->params[$key]);}
}
try{
    if(($p['action']??'')==='seed-report'){
        if(get_current_user_id()!==1)throw new RuntimeException('Teacher fixture only');
        $evaluation=Service::owned((int)$p['evaluationId']);if(is_wp_error($evaluation))throw new RuntimeException($evaluation->message);$config=json_decode($evaluation['config_json'],true);
        foreach(range(8,49) as $index=>$user){
            $existing=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Repository::table('runs').' WHERE evaluation_id=%d AND user_id=%d',(int)$evaluation['id'],$user));if($existing)continue;
            $running=$index>=34;$seconds=(int)$config['durationSeconds'];$start=microtime(true)-($running?35:$seconds+5);
            $state=Rules::create($config['samples'],$seconds,(float)$config['minAccuracy'],$start,$config['version']);$goal=$running?45+$index%4*12:(int)max(30,min(1000,60+($index*73)%1000)*min(1,$seconds/180));$step=0;$characters=0;
            $mistakeEvery=$index%6===0?9:40;
            while($characters<$goal&&$step*35<($running?20000:$seconds*800)){
                $target=Rules::chars($state['samples'][$state['sampleIndex']%count($state['samples'])]['content']);$pos=count(Rules::chars($state['input']));$char=$target[$pos];
                if($characters&&$characters%$mistakeEvery===0){$at=++$step*35;$wrong=$char==='x'?'z':'x';$state=Rules::apply($state,array(array('start'=>$pos,'delete'=>0,'text'=>$wrong,'kind'=>'type','at'=>$at)),$start+$at/1000);$at=++$step*35;$state=Rules::apply($state,array(array('start'=>$pos,'delete'=>1,'text'=>'','kind'=>'delete','at'=>$at)),$start+$at/1000);}
                $at=++$step*35;$state=Rules::apply($state,array(array('start'=>$pos,'delete'=>0,'text'=>$char,'kind'=>'type','at'=>$at)),$start+$at/1000);$characters++;
            }
            if(!$running)$state=Rules::apply($state,array(),$start+$seconds,true);
            if($index===5)$state['verificationIssue']='stale_batch';
            $m=Rules::metrics($state,microtime(true));
            $wpdb->insert(Repository::table('runs'),array('token'=>bin2hex(random_bytes(24)),'user_id'=>$user,'evaluation_id'=>(int)$evaluation['id'],'attempt_no'=>1,'challenge'=>'evaluation-'.$evaluation['id'],'version'=>$state['version'],'status'=>$state['status'],'revision'=>1,'last_request'=>'fixture-report','state_json'=>wp_json_encode($state),'metrics_json'=>wp_json_encode($m),'deadline_at'=>gmdate('Y-m-d H:i:s',(int)ceil($state['deadline'])),'effective_cpm'=>$m['effectiveCpm'],'input_accuracy'=>$m['inputAccuracy'],'qualified'=>$m['qualified']?1:0,'started_at'=>gmdate('Y-m-d H:i:s',(int)$start),'completed_at'=>$running?null:gmdate('Y-m-d H:i:s',(int)$state['deadline'])));
        }
        echo json_encode(array('ok'=>true));exit;
    }
    if(($p['action']??'')==='expire'){
        $row=Repository::run($p['token'],get_current_user_id());if(!$row)throw new RuntimeException('No run');$s=Repository::state($row);$s['deadline']=microtime(true)-(!empty($p['settle'])?3:.1);$s['startedAt']=$s['deadline']-$s['durationSeconds'];
        $wpdb->db->prepare('UPDATE wp_edu_games_typing_runs SET state_json=? WHERE id=?')->execute(array(json_encode($s),$row['id']));if(!empty($p['settle']))Service::update(array('token'=>$p['token']),true);echo json_encode(array('ok'=>true));exit;
    }
    $path=$p['path'];$query=$p['query']??array();$body=$p['body']??array();$method=$p['method']??'GET';
    if($path==='/reports/contexts')$result=EduGames\Support\Capabilities::can_manage()?EduGames\Reports\Roster::contexts():Service::error('Denied',403);
    else{
        Typing_Controller::register_routes();$result=Service::error('Unknown route',404);
        foreach($GLOBALS['typing_routes'] as [$route,$config])if($config['methods']===$method&&preg_match('~^'.$route.'$~D',$path,$matches)){
            $params=$query;foreach($matches as $key=>$value)if(is_string($key))$params[$key]=$value;$request=new Typing_Request($params,$body);
            $result=call_user_func($config['permission_callback'],$request)?call_user_func($config['callback'],$request):Service::error('권한이 없습니다.',403);break;
        }
    }
    echo json_encode(is_wp_error($result)?array('error'=>$result->message,'status'=>$result->data['status']??400):array('data'=>$result),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);}
