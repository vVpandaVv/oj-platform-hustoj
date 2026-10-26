<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Learning\Run;
use EduGames\Learning\Catalog;
use EduGames\Rest\Controllers\Learning_Controller as Controller;
$checks=0;
function check_learning($ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejected(callable $fn):void{try{$fn();}catch(InvalidArgumentException $e){check_learning(true,'rejected');return;}check_learning(false,'invalid action accepted');}
function answer_for(array $q):array{$a=array();foreach($q['fields'] as $f){$e=$q['expected'][$f['key']];$a[$f['key']]=$f['type']==='choice'?$e[0]:$e;}return $a;}
foreach(Catalog::PROVIDERS as $game=>$class){
    $state=Run::create($game,'all');check_learning(count($state['ids'])===count(array_unique($state['ids'])),'duplicate set');
    $q=Catalog::find($game,$state['ids'][0]);$answer=answer_for($q);$input=array('questionId'=>$q['id'],'action'=>'answer','answer'=>$answer);
    rejected(fn()=>Run::apply($state,$game,'practice',array('questionId'=>'wrong','action'=>'next')));
    rejected(fn()=>Run::apply($state,$game,'practice',array('questionId'=>$q['id'],'action'=>'next')));
    rejected(fn()=>Run::apply($state,$game,'assessment',array('questionId'=>$q['id'],'action'=>'hint')));
    rejected(fn()=>Run::apply($state,$game,'practice',array_replace($input,array('answer'=>array('score'=>100)))));
    $wrong=Run::apply($state,$game,'practice',array_replace($input,array('answer'=>array())));
    $fixed=Run::apply($wrong,$game,'practice',$input);$record=$fixed['records'][0];
    check_learning(!$record['firstCorrect']&&$record['result']['correct']&&$record['attempts']===2&&$record['firstAnswer']===array(),'retry overwrites first');
    rejected(fn()=>Run::apply($fixed,$game,'practice',$input));
    $evaluated=Run::apply($state,$game,'assessment',$input);rejected(fn()=>Run::apply($evaluated,$game,'assessment',$input));
    check_learning(!isset(Run::view($state,$game,'assessment')['question']['expected']),'answer leak');
    check_learning(Run::summary($fixed)['firstCorrect']===0&&Run::summary($fixed)['finalCorrect']===1,'first/final score');
}
$config=require EDU_GAMES_PATH.'src/Games/Modules/AI_Model_Lab/activities/digit_recognition_v2.php';
$split=$config['simulation'];
foreach(array('trainingIds','validationIds','reservedTestIds') as $key){check_learning(count($split[$key])===count(array_unique($split[$key])),'duplicate split ID');}
check_learning(!array_intersect($split['trainingIds'],$split['validationIds'])&&!array_intersect($split['trainingIds'],$split['reservedTestIds'])&&!array_intersect($split['validationIds'],$split['reservedTestIds']),'overlapping splits');
$digit=Catalog::find('ai-model-lab','digit-labeling');$defer=array_fill_keys(array_keys(answer_for($digit)),'defer');$r=Catalog::grade('ai-model-lab',$digit,$defer);
check_learning(!$r['correct']&&$r['labeling']['coverage']==0&&$r['labeling']['accuracyOfDecided']==0&&!isset($r['simulation']),'defer perfect score');
foreach($config['performance_by_correct_count'] as $table){foreach($table as $row){$matrix=$row['confusion_matrix'];$total=$right=0;$precision=$recall=$f1=0;
    for($i=0;$i<3;$i++){$tp=$matrix[$i][$i];$actual=array_sum($matrix[$i]);$predicted=array_sum(array_column($matrix,$i));$p=$predicted?$tp/$predicted:0;$r=$actual?$tp/$actual:0;$precision+=$p/3;$recall+=$r/3;$f1+=($p+$r?2*$p*$r/($p+$r):0)/3;$right+=$tp;$total+=$actual;}
    check_learning($total===$config['simulation']['validationCount'],'matrix sample count');
    foreach(array('accuracy'=>$right/$total,'precision'=>$precision,'recall'=>$recall,'f1'=>$f1) as $key=>$value){check_learning(abs($row[$key]-$value)<1e-5,'same-run '.$key);}
}}

final class Learning_DB {
    public string $prefix='wp_';public array $row=array();public bool $race=false;public bool $failure=false;public int $writes=0;
    public function prepare($sql,...$args){return array($sql,$args);}
    public function get_var($q){return 0;}
    public function get_results($q,$format){return array();}
    public function insert($table,$data,$format){$this->row=$data+array('id'=>1);return 1;}
    public function get_row($q,$format){[$token,$user,$game,$version,$now]=$q[1];$r=$this->row;return $r&&$token===$r['token']&&$user===$r['user_id']&&$game===$r['game_slug']&&$version===$r['content_version']&&$now<$r['expires_at']?$r:null;}
    public function query($q){if($this->failure)return false;if($this->race)return 0;$args=$q[1];$n=count($args);if($args[$n-2]!==$this->row['revision'])return 0;$this->row['state_json']=$args[0];$this->row['revision']++;$this->writes++;return 1;}
}
$GLOBALS['wpdb']=new Learning_DB();$db=$GLOBALS['wpdb'];
$start=Controller::start(new WP_REST_Request('data-extraction',array('mode'=>'assessment','level'=>'all')));
check_learning(!is_wp_error($start)&&strlen($start['token'])===48,'create');$q=Catalog::find('data-extraction',$start['question']['id']);
$input=array('token'=>$start['token'],'revision'=>0,'requestId'=>'test-request-1','questionId'=>$q['id'],'action'=>'answer','answer'=>answer_for($q));
$request=new WP_REST_Request('data-extraction',$input);
$db->failure=true;check_learning(Controller::update($request)->data['status']===500,'save failure');$db->failure=false;
$db->race=true;check_learning(Controller::update($request)->data['status']===409,'race conflict');$db->race=false;
$saved=Controller::update($request);$repeat=Controller::update($request);
check_learning($saved===$repeat&&$db->writes===1,'idempotent retry');
check_learning(Controller::update(new WP_REST_Request('data-extraction',array_replace($input,array('requestId'=>'different-request'))))->data['status']===409,'stale revision');
check_learning(Controller::resume(new WP_REST_Request('missing-values',array('token'=>$start['token'])))->data['status']===404,'cross game');
$db->row['user_id']=8;check_learning(Controller::resume(new WP_REST_Request('data-extraction',array('token'=>$start['token'])))->data['status']===404,'cross user');
$db->row['user_id']=7;$db->row['expires_at']='2000-01-01 00:00:00';check_learning(Controller::resume(new WP_REST_Request('data-extraction',array('token'=>$start['token'])))->data['status']===404,'expired');
echo "PASS: $checks state, metrics, privacy and controller checks (DB doubles).\n";
