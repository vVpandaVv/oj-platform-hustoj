<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackSettingsService;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveAiFeedbackSettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_ai_feedback_settings';

    public function __construct(
        private readonly AiFeedbackSettingsService $settings,
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
            $active = [];
            foreach (['insufficient', 'beginner', 'intermediate', 'advanced'] as $level) {
                $key = trim($request->bodyString('guide_' . $level));
                $guide = $this->guides->find($key);
                if (
                    $guide === null
                    || ! $guide->isActive()
                    || $guide->level() !== $level
                ) {
                    throw new DomainException('수준에 맞는 활성 답변 지침을 선택해 주세요.');
                }
                $active[$level] = $key;
            }
            $this->settings->save(
                $request->bodyBool('enabled'),
                $request->bodyString('result_scope', 'all'),
                $active,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/submissions/ai-feedback',
                new UiMessage(
                    'ai_feedback.settings_saved',
                    MessageTone::Success,
                    'AI 피드백 설정 저장 완료',
                    '새 요청부터 변경한 수준별 답변 정책을 적용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/submissions/ai-feedback',
                new UiMessage(
                    'ai_feedback.settings_failed',
                    MessageTone::Danger,
                    'AI 피드백 설정 저장 실패',
                    $error instanceof DomainException
                        ? $error->getMessage()
                        : '설정이 변경되었습니다. 화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
