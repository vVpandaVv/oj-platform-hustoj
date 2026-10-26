<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackValidationService;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use Throwable;

final class FeedbackValidationActionHandler implements ActionHandler
{
    public const ACTION='oj_ai_guide_validate';
    public function __construct(private readonly FeedbackValidationService $validation,private readonly ActionAccessGuard $guard,private readonly ErrorReporter $errors) {}
    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor=$this->guard->actor(self::ACTION,$request,'oj_manage_ai_feedback');
            $key=$request->bodyString('guide_key');
            if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D',$key)) {return HttpResponse::html('올바르지 않은 지침입니다.',400);}
            if($request->bodyString('operation')==='export') {
                return HttpResponse::download(
                    'feedback-validation-' . $key . '.json',
                    'application/json',
                    json_encode($this->validation->export($key), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }
            $this->guard->throttle('ai_guide_validation',(string)$actor->userId(),30,60);
            $this->validation->run($actor->userId(),$key,$request->bodyString('case_key'),$request->bodyString('variant'),$request->bodyString('run_token'));
            return HttpResponse::redirect('/oj-console/submissions/ai-guides/'.$key.'/preview#validation');
        } catch(Throwable $error) {
            $this->errors->report($error);
            return HttpResponse::html('<p>검증을 실행하지 못했습니다. 권한·AI 연결·지침을 확인해 주세요.</p><a href="/oj-console/submissions/ai-guides">지침 목록</a>',400);
        }
    }
}
