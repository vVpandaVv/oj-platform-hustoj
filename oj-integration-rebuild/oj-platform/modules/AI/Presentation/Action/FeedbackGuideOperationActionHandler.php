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
use Throwable;

final class FeedbackGuideOperationActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $action,
        private readonly string $operation,
        private readonly FeedbackGuideRepository $guides,
        private readonly AiFeedbackSettingsService $settings,
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
                $this->action,
                $request,
                'oj_manage_ai_feedback'
            );
            $userId = $actor->userId();
            $key = trim($request->bodyString('guide_key'));
            $message = match ($this->operation) {
                'duplicate' => $this->duplicate($key, $userId),
                'archive' => $this->archive($key, $userId, true),
                'restore' => $this->archive($key, $userId, false),
                default => throw new \DomainException('AI 답변 지침 작업이 올바르지 않습니다.'),
            };

            return $this->responses->redirect(
                '/oj-console/submissions/ai-guides',
                new UiMessage(
                    'ai_feedback.guide_operation',
                    MessageTone::Success,
                    'AI 답변 지침 처리 완료',
                    $message
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/submissions/ai-guides',
                new UiMessage(
                    'ai_feedback.guide_operation_failed',
                    MessageTone::Danger,
                    'AI 답변 지침 처리 실패',
                    $error instanceof \DomainException
                        ? $error->getMessage()
                        : '지침 상태를 변경하지 못했습니다.'
                ),
                $userId
            );
        }
    }

    private function duplicate(string $key, int $userId): string
    {
        $this->guides->duplicate($key, $userId);

        return '지침을 새 비활성 복사본으로 만들었습니다.';
    }

    private function archive(
        string $key,
        int $userId,
        bool $archived
    ): string {
        if ($archived && $this->settings->usesGuide($key)) {
            throw new \DomainException(
                '현재 사용 중인 지침입니다. 수준별 답변 정책에서 대체 지침을 먼저 선택해 주세요.'
            );
        }
        $this->guides->setArchived($key, $archived, $userId);

        return $archived
            ? '지침을 보관했습니다.'
            : '지침을 다시 활성화했습니다.';
    }
}
