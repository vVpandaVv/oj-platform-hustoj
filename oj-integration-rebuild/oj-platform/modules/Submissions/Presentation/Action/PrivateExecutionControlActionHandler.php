<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;

final class PrivateExecutionControlActionHandler implements ActionHandler
{
    public function __construct(private readonly CodeExecutionSupport $support,private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,private readonly ErrorReporter $errors){}
    public function handle(HttpRequest $request):HttpResponse
    {
        $actorId=0;$tone=MessageTone::Success;$message='실습 실행 설정을 저장했습니다.';
        try{
            $actorId=$this->guard->actor('oj_private_execution_control',$request,'oj_manage_submission_settings')->userId();
            $operation=$request->bodyString('operation');
            if($operation==='probe'){$result=$this->support->probe($actorId,$request->bodyString('language'),$request->bodyBool('restart'));$message=$result['message'];}
            elseif($operation==='cleanup'){$result=$this->support->cleanup();$message='완료된 임시 실행을 정리했습니다. 진행·중단 확인 필요 실행은 보존합니다.';}
            elseif($operation==='limits'){$this->support->savePolicy($request->bodyInt('global_pending',8),$request->bodyInt('actor_pending',2),$request->bodyInt('actor_hourly',120),$request->bodyInt('expected_revision'));}
            else{throw new \DomainException('실습 실행 관리 작업을 확인해 주세요.');}
        }catch(\Throwable $error){$this->errors->report($error);$tone=MessageTone::Danger;$message=$error instanceof \DomainException?$error->getMessage():'실습 실행 설정을 처리하지 못했습니다. 연결과 설정 버전을 확인해 주세요.';}
        return $this->responses->redirect('/oj-console/submissions/settings',new UiMessage('submissions.settings',$tone,'실습 실행 관리',$message),$actorId);
    }
}
