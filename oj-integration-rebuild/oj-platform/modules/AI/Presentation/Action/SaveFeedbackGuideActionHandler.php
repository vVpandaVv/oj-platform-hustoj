<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveFeedbackGuideActionHandler implements ActionHandler
{
    private const ACTION = 'oj_ai_persona_save';

    public function __construct(
        private readonly FeedbackGuideRepository $guides,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_manage_ai_feedback'
            );
            $userId = $actor->userId();
            $key = trim($request->bodyString('guide_key'));
            $guide = $this->guides->save(
                $key === '' ? null : $key,
                $request->bodyString('name'),
                $request->bodyString('level'),
                $request->bodyString('purpose'),
                $request->bodyString('instructions'),
                $userId
            );

            return $this->responses->redirect(
                '/oj-console/submissions/ai-guides/' . $guide->key(),
                new UiMessage(
                    'ai_feedback.guide_saved',
                    MessageTone::Success,
                    'AI 답변 지침 저장 완료',
                    '새 개정을 비활성 상태로 안전하게 저장했습니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/submissions/ai-guides',
                new UiMessage(
                    'ai_feedback.guide_failed',
                    MessageTone::Danger,
                    'AI 답변 지침 저장 실패',
                    $error instanceof \DomainException
                        ? $error->getMessage()
                        : '지침을 저장하지 못했습니다.'
                ),
                $userId
            );
        }
    }
}
