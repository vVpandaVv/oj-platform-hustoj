<?php
declare(strict_types=1);
require dirname(__DIR__) . '/Unit/research.php';

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{ActionSecurity, ActionUrlProvider, HttpRequest, HttpResponse, RouteDefinition, RouteMatch, RouteArea, AuthenticationRequirement};
use CodingPanda\OjPlatform\Modules\Auth\Contract\{CurrentAccount, CurrentAccountProvider, UserIdentity};
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjResearch\Application\ResearchService;
use CodingPanda\OjResearch\Presentation\ResearchPageHandler;

$before = $checks;
$store = new ResearchMemoryStore(); $clock = new ResearchClock();
$service = new ResearchService($store,new ResearchGroups(),new ResearchLearning(),new ResearchUsers(),new ResearchPermissions(),new ResearchFeatures(),new ResearchRouter(),$clock);
$accounts = new class implements CurrentAccountProvider {
    public int $actor = 1;
    public function current(): CurrentAccount { return new CurrentAccount(new UserIdentity($this->actor,'user'.$this->actor,'','사람'.$this->actor),null); }
};
$pages = new class implements ConsoleResponder, PageResponder {
    public function respond(string $title,string $content,string $currentMenu,HttpRequest $request,int $status=200):HttpResponse {return HttpResponse::html($content,$status);}
    public function guest(string $title,string $content,HttpRequest $request,int $status=200):HttpResponse {return HttpResponse::html($content,$status);}
    public function authenticated(string $title,string $content,HttpRequest $request,int $status=200):HttpResponse {return HttpResponse::html($content,$status);}
};
$urls = new class implements ActionUrlProvider {public function actionUrl():string{return '/wp-admin/admin-post.php';}public function ajaxUrl():string{return '/wp-admin/admin-ajax.php';}};
$security = new class implements ActionSecurity {
    public function hiddenFields(string $actionKey):string {return '<input type="hidden" name="action" value="'.$actionKey.'"><input type="hidden" name="_wpnonce" value="test-only">';}
    public function verify(string $actionKey,HttpRequest $request):bool{return false;}
};
$view = new ResearchPageHandler($service,$accounts,$pages,$pages,$urls,$security);
$render = function(string $key,int $id=0)use($view):HttpResponse {
    $public = $key==='research.index';
    $route=new RouteDefinition($key,new ModuleId('research'),'research',$public?'/research':'/oj-console/research', $public?RouteArea::PublicArea:RouteArea::Console,['GET'],AuthenticationRequirement::Authenticated,'html');
    return $view->handle(new RouteMatch($route,['study_id'=>(string)$id]),new HttpRequest('GET',$route->path()));
};
$snapshots=[];
$response=$render('console.research.index');$snapshots['index']=$response->body();
researchAssert($response->status()===200&&str_contains($response->body(),'연구 만들기'),'Teacher can reach native research creation view');
researchAssert(str_contains($response->body(),'name="_wpnonce"')&&str_contains($response->body(),'/wp-admin/admin-post.php'),'Creation form uses platform action URL and nonce fields');
$id=$service->create(1,array_replace($input,['title'=>'수준별 피드백 효과 연구 <script>alert(1)</script>']));
$rev=fn()=> $store->study($id)['revision'];
$snapshots['draft']=$render('console.research.show',$id)->body();
researchAssert(str_contains($snapshots['draft'],'&lt;script&gt;')&&!str_contains($snapshots['draft'],'<script>'),'Research title is HTML escaped');
researchAssert(str_contains($snapshots['draft'],'연구 계획과 공통 과제 확인')&&str_contains($snapshots['draft'],'동의'),'Teacher can review protocol and explicitly select consented roster');
$service->enroll(1,$id,$rev(),[10,11]);$service->transition(1,$id,$rev(),'pretest');
$snapshots['manage']=$render('console.research.show',$id)->body();
researchAssert(str_contains($snapshots['manage'],'학생10')&&str_contains($snapshots['manage'],'R-'),'Authorized operator can resolve pseudonym to student for attendance and withdrawal');
$accounts->actor=10;$student=$render('research.index');$snapshots['student']=$student->body();
researchAssert(str_contains($student->body(),'최종 답안 제출')&&str_contains($student->body(),'AI 도움 없이'),'Independent learner answer form is reachable');
researchAssert(!str_contains($student->body(),'초기 수준')&&!str_contains($student->body(),'R-')&&!str_contains($student->body(),'원배정'),'Learner view conceals level, condition and participant ledger');
researchAssert($render('console.research.show',$id)->status()===403,'Student cannot open research management by direct URL');
$service->submitAnswer(10,$id,$rev(),'print("<script>test</script>")','반복문으로 값을 계산했습니다.');
researchAssert(str_contains($render('research.index')->body(),'최종 답안을 제출했습니다.'),'Submitted student sees final submission state');
$accounts->actor=2;$rating=$render('console.research.rating');$snapshots['rating']=$rating->body();
researchAssert(str_contains($rating->body(),'독립 점수 저장')&&!str_contains($rating->body(),'학생10')&&!str_contains($rating->body(),'원배정'),'Assigned rater sees answer and rubric without student or condition');
researchAssert(str_contains($rating->body(),'&lt;script&gt;test&lt;/script&gt;'),'Student code cannot execute in evaluator view');
researchAssert($render('console.research.show',$id)->status()===403,'Assigned evaluator cannot unblind management by direct URL');
$service->transition(1,$id,$rev(),'cancelled','예비 운영 종료');
researchAssert(!str_contains($render('console.research.rating')->body(),'독립 점수 저장'),'Cancelled study no longer exposes grading work');
researchDenied(fn()=> $service->rate(2,$id,$service->ratingTasks(3)[0]['blind_id']??'stale',['understanding'=>0,'design'=>0,'implementation'=>0,'explanation'=>0]),'Cancelled study rejects a stale grading form');

// Optional deterministic render artifacts for browser visual QA; ordinary test runs do not write files.
$out=getenv('OJ_RESEARCH_UI_OUTPUT');
if(is_string($out)&&$out!=='') {
    $root=realpath($out);if($root===false)throw new RuntimeException('Create output directory first');
    copy(dirname(__DIR__,3).'/oj-platform/resources/ui/css/oj-ui-system.css',$root.'/ui.css');
    foreach($snapshots as$name=>$html) {file_put_contents($root.'/'.$name.'.html','<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>수업 연구 화면 검사</title><link rel="stylesheet" href="ui.css"></head><body class="ojp-app"><main class="ojp-public-main"><div class="ojp-shell">'.$html.'</div></main></body></html>');}
}
echo 'Research route UI passed: '.($checks-$before)." assertions; real page handlers with synthetic identities and store\n";
