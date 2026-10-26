<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskRoutingService;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveAiTaskRoutingActionHandler implements ActionHandler
{
    public function __construct(
        private readonly AiTaskRoutingService $routing,
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
                'oj_save_ai_task_routing',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $body = $request->body();
            $raw = is_array($body['assignments'] ?? null)
                ? $body['assignments']
                : [];
            $assignments = [];
            foreach ($raw as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $primary = trim((string) ($item['primary_profile_key'] ?? ''));
                if ($primary === '') {
                    continue;
                }
                $fallback = trim((string) ($item['fallback_profile_key'] ?? ''));
                $item['fallback_profile_key'] = $fallback !== '' ? $fallback : null;
                if ($fallback === '') {
                    $item['fallback_policy'] = 'none';
                }
                $assignments[] = AiTaskAssignment::fromArray($item);
            }
            $this->routing->save(
                $assignments,
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/settings/ai-routing',
                new UiMessage(
                    'ai_routing.saved',
                    MessageTone::Success,
                    'AI 작업 배정 저장 완료',
                    '새 작업부터 현재 배정과 대체 정책을 사용합니다.'
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/ai-routing',
                new UiMessage(
                    'ai_routing.failed',
                    MessageTone::Danger,
                    'AI 작업 배정 저장 실패',
                    $error instanceof \DomainException || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $userId
            );
        }
    }
}
