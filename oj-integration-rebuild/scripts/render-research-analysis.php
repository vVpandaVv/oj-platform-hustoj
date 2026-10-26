<?php
declare(strict_types=1);
// Render real PHP views against an in-memory synthetic study. Never connects to WordPress.
require dirname(__DIR__).'/oj-research/tests/Unit/research-pilot.php';
use CodingPanda\OjResearch\Presentation\PilotPages;
use CodingPanda\OjResearch\Domain\PilotProtocol;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{ActionUrlProvider,ActionSecurity,HttpRequest};
$dir=$argv[1]??dirname(__DIR__).'/oj-research/docs/preview';
if(!is_dir($dir)){mkdir($dir,0777,true);}
$urls=new class implements ActionUrlProvider{public function actionUrl():string{return '#preview';}public function ajaxUrl():string{return '#preview';}};
$security=new class implements ActionSecurity{public function hiddenFields(string $key):string{return ''; }public function verify(string $key,HttpRequest $r):bool{return false;}};
$ui=new PilotPages($service,$pilot,$urls,$security);
$views=['new'=>$ui->edit(1),'insufficient'=>$ui->report(1,$id)];
$fixture=json_decode(file_get_contents(dirname(__DIR__).'/oj-research/tests/Fixtures/research-analysis.json'),true,512,JSON_THROW_ON_ERROR)['fixtures'][0];
$demo=$store->study($id);$demo['title']='합성 자료 · 수준별 AI 피드백 효과';$demo['status']='completed';
$demo['data']['protocol']=PilotProtocol::validate(['title'=>$demo['title'],'groups'=>[1],'design'=>'adaptation','scoring'=>'pass_rate','practice'=>[1000],'pretest'=>[2000],'posttest'=>[3000],
    'notes'=>['equivalence'=>'화면·계산 검증을 위한 합성 점수이며 실제 연구 자료가 아닙니다.']]);
$store->studyRows[$id]=$demo;$store->people=[];$store->events=[];
foreach($fixture['rows']as$i=>$r){$pid=$store->addParticipant(['study_id'=>$id,'user_id'=>10000+$i,'group_id'=>1,'active_user_id'=>null,'pseudonym'=>$r['participant'],'arm'=>$r['arm'],'consent'=>'granted','baseline'=>['level'=>$r['level']]]);
    foreach(['pretest'=>2000,'posttest'=>3000]as$phase=>$problem){$sid=100000+$i*2+($phase==='posttest'?1:0);
        $base=['submission_id'=>$sid,'phase'=>$phase,'problem_id'=>$problem];
        $store->append($id,$pid,'s'.$sid,'pilot.submission',$base,'2026-09-13 12:00:00');
        $store->append($id,$pid,'j'.$sid,'pilot.judged',$base+['result_code'=>6,'pass_rate'=>$r[$phase.'_score']/100,'elapsed_seconds'=>60],'2026-09-13 12:00:00');
        $store->append($id,$pid,'f'.$sid,'pilot.finished',['phase'=>$phase],'2026-09-13 12:00:00');
    }
}
$views['manage']=$ui->show(1,$id);$views['report']=$ui->report(1,$id);
foreach($views as$name=>$html){$navigation='<nav class="ojp-research-actions"><a href="new.html">연구 설정</a><a href="manage.html">수업 관리</a><a href="report.html">결과 보고서</a><a href="insufficient.html">자료 부족 상태</a></nav>';
    file_put_contents($dir.'/'.$name.'.html','<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>연구 기능 미리보기</title><link rel="stylesheet" href="ui.css"><script src="ui.js" defer></script></head><body class="ojp-app"><main class="ojp-public-main"><div class="ojp-shell"><p class="ojp-research-callout">합성 자료로 렌더링한 실제 UI · 학생 데이터 없음 · 저장 기능은 미리보기에서 제공하지 않음</p>'.$navigation.$html.'</div></main></body></html>');}
file_put_contents($dir.'/ui.css', file_get_contents(dirname(__DIR__).'/oj-platform/resources/ui/css/oj-ui-system.css') . file_get_contents(dirname(__DIR__).'/oj-research/assets/research.css'));
file_put_contents($dir.'/ui.js', file_get_contents(dirname(__DIR__).'/oj-platform/resources/ui/js/oj-ui.js') . file_get_contents(dirname(__DIR__).'/oj-research/assets/research.js'));
echo 'Rendered synthetic UI at '.$dir."\n";
