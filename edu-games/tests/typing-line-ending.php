<?php
declare(strict_types=1);
require __DIR__.'/typing-support.php';
use EduGames\Typing\{Rules,Service,Repository};
$checks=0;
function verify(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function denied(callable $fn): void {try{$fn();}catch(InvalidArgumentException){verify(true,'denied');return;}throw new RuntimeException('Expected rejection');}
$samples=[['id'=>'line','content'=>"ab\n    c"]];
$s=Rules::create($samples,60,95,1000,Rules::VERSION,'space-enter');
$first=['start'=>0,'delete'=>0,'text'=>'ab','kind'=>'type','at'=>0];$newline=['start'=>2,'delete'=>0,'text'=>"\n",'kind'=>'linebreak','at'=>100];
$s=Rules::apply($s,[$first,$newline],1001);verify($s['input']==="ab\n"&&$s['mistakes']===0,'configured Space accepted as newline');verify(Rules::metrics($s,1001)['validCharacters']===2,'newline excluded from speed');
$strict=Rules::apply(Rules::create($samples,60,95,1000),[$first],1001);denied(fn()=>Rules::apply($strict,[$newline],1001));
$bad=$newline;$bad['start']=1;denied(fn()=>Rules::apply($s,[$bad],1001));$bad=$newline;$bad['text']=' ';denied(fn()=>Rules::apply($s,[$bad],1001));$bad=$newline;$bad['delete']=1;denied(fn()=>Rules::apply($s,[$bad],1001));
verify(Rules::view($strict,1001)['lineEnding']==='enter','legacy default is strict');denied(fn()=>Rules::create($samples,60,95,1000,Rules::VERSION,'anything'));
$wpdb=new Typing_Test_DB();typing_test_runtime();$GLOBALS['test_user_id']=1;$GLOBALS['caps']=['edu_games_manage'];$ids=array_column(array_filter(Service::catalog()['samples'],fn($sample)=>$sample['language']==='python'),'id');
$config=['title'=>'줄 끝 설정 평가','language'=>'python','durationSeconds'=>60,'minAccuracy'=>95,'maxAttempts'=>1,'sampleIds'=>[$ids[0]],'lineEnding'=>'space-enter','contexts'=>[['type'=>'group','id'=>'g1','revision'=>'r1','label'=>'1반']]];
$created=Service::create_evaluation($config);verify(!is_wp_error($created)&&$created['evaluation']['lineEnding']==='space-enter','teacher freezes policy');$id=$created['evaluation']['id'];Service::set_status($id,'open');
$bad=$config;$bad['lineEnding']='anything';verify(is_wp_error(Service::create_evaluation($bad)),'invalid teacher policy rejected');
$GLOBALS['test_user_id']=7;$GLOBALS['caps']=['edu_games_play'];$run=Service::start(['evaluationId'=>$id,'lineEnding'=>'enter']);verify($run['lineEnding']==='space-enter','student cannot override evaluation policy');verify(Service::update(['token'=>$run['token']],true)['lineEnding']==='space-enter','resume preserves policy');
$GLOBALS['test_user_id']=70;$solo=Service::start(['language'=>'python','sampleId'=>$ids[0],'lineEnding'=>'space-enter']);verify($solo['lineEnding']==='space-enter'&&str_ends_with($solo['challenge'],':space-enter'),'different personal condition has a separate challenge');
$row=Repository::run($solo['token'],70);$state=Repository::state($row);$state['status']='finished';$state['attempted']=10;$state['correctAttempts']=10;$state['completedCode']=20;
$wpdb->db->prepare('UPDATE wp_edu_games_typing_runs SET state_json=?,status=?,qualified=1,effective_cpm=4,input_accuracy=100 WHERE id=?')->execute([wp_json_encode($state),'finished',$row['id']]);
verify(Service::leaderboard($ids[0])['pagination']['total']===0,'strict ranking excludes Space-enabled results');verify(Service::leaderboard($ids[0],1,20,Rules::VERSION,'space-enter')['pagination']['total']===1,'Space-enabled ranking remains available');
$practice=array_values(array_filter(EduGames\Games\Modules\Code_Typing\Code_Typing_Module::rules()['samples'],fn($sample)=>$sample['mode']==='short'&&$sample['language']==='python'))[0];
$key='python:short:'.$practice['id'];$payload=['key'=>$key.':typing-v3:enter','sampleId'=>$practice['id'],'input'=>$practice['content'],'seconds'=>60];
$modern=EduGames\Progress\Validator::entries('code-typing',$payload);verify($modern[$payload['key']]===Rules::code_count($practice['content']),'practice account record uses nonspace code count');
$payload['key']=$key;$old=EduGames\Progress\Validator::entries('code-typing',$payload);verify($old[$key]===count(Rules::chars($practice['content'])),'legacy practice benchmarks preserved');
echo $checks." line-ending rules, evaluation policy and ranking separation checks passed\n";
