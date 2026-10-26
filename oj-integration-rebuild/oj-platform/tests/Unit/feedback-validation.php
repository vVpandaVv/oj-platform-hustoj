<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AI\Domain\ClassroomFeedbackPrompts as P;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackValidationCases as C;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem\PrivateFeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem\PrivateFeedbackValidationStore;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackValidationService;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackInputFactory;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackOutputValidator;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;

require_once dirname(__DIR__,2).'/src/Kernel/Autoloader.php';Autoloader::register(dirname(__DIR__,2));
$count=0;
function verifyPrompt(bool $ok,string $message): void {global $count;++$count;if(!$ok){throw new RuntimeException($message);}}
function rejectsPrompt(callable $f,string $message): void {try{$f();}catch(DomainException){verifyPrompt(true,$message);return;}throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/oj-prompt-validation-'.bin2hex(random_bytes(6));
$roots=new class($root) implements PrivateDataRootProvider {public function __construct(private string $p){}public function root():string{return $this->p;}};
$repo=new PrivateFeedbackGuideRepository($roots);$store=new PrivateFeedbackValidationStore($roots);
$ai=new class implements AiTaskRouter {
    public int $calls=0;public string $mode='valid';public bool $fallback=false;
    public function canRoute(string $taskType,bool $requiresSubmissionSource=false):bool{return true;}
    public function snapshot(string $taskType,bool $requiresSubmissionSource=false):AiTaskAssignmentSnapshot{
        if($taskType!=='feedback_prompt_validation'||$requiresSubmissionSource){throw new RuntimeException('Synthetic validation must use its own non-student task.');}
        return new AiTaskAssignmentSnapshot($taskType,1,'test',$this->fallback?'backup':null,str_repeat('a',64));
    }
    public function execute(AiTaskAssignmentSnapshot $assignment,AiTaskRequest $request):AiTaskResponse{
        if($request->taskType()!==$assignment->taskType()){throw new RuntimeException('Task mismatch');}
        ++$this->calls;
        if($this->mode==='fail'){throw new RuntimeException('private provider detail');}
        return new AiTaskResponse($this->mode==='invalid'?['result_understanding'=>'print(42)','step_hints'=>['확인'],'review_question'=>'왜?']:['result_understanding'=>'판정과 범위를 확인해 보세요.','step_hints'=>['작은 입력에서 마지막으로 처리되는 값을 기록해 보세요.'],'review_question'=>'요구한 범위와 어떤 차이가 있나요?'],'test','openai','mock-test-only',str_repeat('a',64));
    }
};
$service=new FeedbackValidationService($repo,$store,$ai,new FeedbackInputFactory(),new FeedbackOutputValidator());
try {
    $catalog=new \CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog();
    verifyPrompt($catalog->supportsTask(FeedbackValidationService::TASK_TYPE)&&!$catalog->requiresSubmissionSource(FeedbackValidationService::TASK_TYPE),'고정 합성 검증 작업만 학생 코드 승인 없이 연결');
    verifyPrompt($catalog->requiresSubmissionSource('submission_feedback'),'실제 학생 제출은 기존 승인 요구 유지');
    $prompts=new \CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskPromptFactory();
    $fixtureInput=['source'=>'print(1)'];
    verifyPrompt($prompts->messages(new AiTaskRequest('submission_feedback','v1',$fixtureInput))===$prompts->messages(new AiTaskRequest(FeedbackValidationService::TASK_TYPE,'v1',$fixtureInput)),'검증 작업과 실제 피드백의 공통 시스템 지침 동일');
    $keys=[];
    foreach(P::LEVELS as $level=>$label) {
        $guide=$repo->save(null,P::name($level),$level,P::VERSION,P::instructions($level),1);$keys[$level]=$guide->key();
        verifyPrompt($repo->find($guide->key())?->instructions()===P::instructions($level),'프롬프트가 손실 없이 저장되어야 함');
        verifyPrompt(!$guide->isActive(),'새 지침은 자동 활성화되지 않아야 함');
        $service->run(1,$guide->key(),'sum-boundary','baseline',bin2hex(random_bytes(16)));
        $service->run(1,$guide->key(),'sum-boundary','candidate',bin2hex(random_bytes(16)));
        $records=$service->records($guide->key());
        verifyPrompt(count($records)===2,'두 조건이 별도 기록되어야 함');
        $byVariant=array_column($records,null,'variant');$old=$byVariant['baseline']['input'];$new=$byVariant['candidate']['input'];
        unset($old['response_policy']['instructions'],$new['response_policy']['instructions']);
        verifyPrompt($old===$new,'같은 수준·사례의 비교는 지침 외 입력이 같아야 함');
        verifyPrompt(!str_contains(json_encode($new,JSON_UNESCAPED_UNICODE),'기대값은'),'정답 oracle은 모델에 주입되지 않아야 함');
        verifyPrompt($byVariant['candidate']['human_review']['correctness']===null,'교사 점수를 생성하지 않아야 함');
    }
    $key=$keys['beginner'];$token=bin2hex(random_bytes(16));$service->run(1,$key,'negative-max','candidate',$token);$before=$ai->calls;
    $service->run(1,$key,'negative-max','candidate',$token);verifyPrompt($ai->calls===$before,'동일 요청 재전송은 AI를 다시 호출하지 않아야 함');
    rejectsPrompt(fn()=>$service->run(1,$key,'sum-boundary','candidate',$token),'요청 토큰을 다른 사례로 재사용할 수 없어야 함');
    rejectsPrompt(fn()=>$service->run(0,$key,'sum-boundary','candidate',bin2hex(random_bytes(16))),'비로그인 actor 거부');
    rejectsPrompt(fn()=>$service->run(1,$key,'unknown','candidate',bin2hex(random_bytes(16))),'임의 사례 거부');
    rejectsPrompt(fn()=>$store->records('../escape'),'경로 탐색 거부');
    $ai->fallback=true;rejectsPrompt(fn()=>$service->run(1,$key,'sum-boundary','candidate',bin2hex(random_bytes(16))),'대체 모델이 있으면 비교 거부');$ai->fallback=false;
    $ai->mode='invalid';$service->run(1,$key,'accepted-count','candidate',bin2hex(random_bytes(16)));
    $invalid=array_values(array_filter($service->records($key),fn($r)=>$r['status']==='rejected'));
    verifyPrompt(count($invalid)===1&&isset($invalid[0]['output']),'형식 위반 원문도 거부 기록으로 보존');
    $ai->mode='fail';$service->run(1,$key,'zero-division','candidate',bin2hex(random_bytes(16)));
    $export=$service->export($key);$json=json_encode($export);
    verifyPrompt(!str_contains($json,'private provider detail')&&!str_contains($json,'actor_id'),'원자료에 내부 오류·운영자 ID를 내보내지 않아야 함');
    verifyPrompt(count(array_filter($export['records'],fn($r)=>$r['status']==='failed'))===1,'실패도 기록되어야 함');
    verifyPrompt($repo->find('beginner-steps')?->revision()===1,'기존 기본 지침 보존');
    verifyPrompt(count(array_filter(C::all(),fn($c)=>$c['split']==='holdout'))===4,'개발용과 확인용 사례 구분');
    $guard=new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard {
        public bool $deny=true;public array $observed=[];
        public function verifyNonce(string $key,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $r):void{}
        public function actor(string $key,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $r,string $cap):\CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity{$this->observed=[$key,$cap];if($this->deny){throw new DomainException('denied');}return new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(1,'test','','test');}
        public function authenticatedActor(string $key,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $r):\CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity{throw new DomainException('not allowed');}
        public function throttle(string $scope,string $id,int $limit,int $window):void{}
    };
    $errors=new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter{public function report(Throwable $e):void{}};
    $handler=new \CodingPanda\OjPlatform\Modules\AI\Presentation\Action\FeedbackValidationActionHandler($service,$guard,$errors);
    $request=new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('POST','/action',[],['guide_key'=>$key,'operation'=>'export']);
    $response=$handler->handle($request);verifyPrompt($response->status()!==200&&!str_contains($response->body(),'records'),'권한 없는 내보내기는 원자료를 노출하지 않아야 함');
    verifyPrompt($guard->observed===['oj_ai_guide_validate','oj_manage_ai_feedback'],'동일 관리 권한과 nonce guard 사용');
    $guard->deny=false;$response=$handler->handle($request);verifyPrompt($response->status()===200&&json_decode($response->body(),true)['schema']==='oj-feedback-validation-v1','실제 export handler 본문 검증');
    $urls=new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider{public function actionUrl():string{return '/action';}public function ajaxUrl():string{return '/ajax';}};
    $security=new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity{public function hiddenFields(string $a):string{return '<input name="action" value="'.$a.'">';}public function verify(string $a,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $r):bool{return true;}};
    $panel=new \CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackValidationPanel($service,$urls,$security,new \CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml());
    $markup=$panel->render($repo->find($key));verifyPrompt(str_contains($markup,'교사 평가 미실시')&&str_contains($markup,'검증 원자료 JSON 내려받기'),'실행 화면에 검증 범위·원자료 제공');
    verifyPrompt(!str_contains($markup,'feedback-validation-json')&&!str_contains($markup,'작은 입력에서 마지막으로'),'목록에는 전체 JSON이나 응답 원문을 포함하지 않아야 함');
    $detail=$panel->render($repo->find($key),1,$token);
    verifyPrompt(str_contains($detail,'작은 입력에서 마지막으로'),'선택한 응답은 개별 조회해야 함');
    for($i=0;$i<12;++$i){$fixture=$export['records'][0];$fixture['id']=bin2hex(random_bytes(16));$store->reserve($fixture);}
    $first=$service->page($key,1);$second=$service->page($key,2);
    verifyPrompt(count($first['records'])===10&&$second['records']!==[],'기록 목록은 10건씩 분리');
    verifyPrompt(!isset($first['records'][0]['output'],$first['records'][0]['input']),'목록 계약에서 응답과 입력 원문 제외');
    verifyPrompt(array_intersect(array_column($first['records'],'id'),array_column($second['records'],'id'))===[],'페이지 사이 중복 기록 없음');
    verifyPrompt(!str_contains($markup,'private provider detail'),'모델 실패 내부 정보 UI 비노출');
    echo "Feedback validation: $count assertions passed (mock AI; no external generation).\n";
} finally {
    if(is_dir($root)) {
        $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($items as $item){if($item->isDir()){rmdir($item->getPathname());}else{unlink($item->getPathname());}}rmdir($root);
    }
}
