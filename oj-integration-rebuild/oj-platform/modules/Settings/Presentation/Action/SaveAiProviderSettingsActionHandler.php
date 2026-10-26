<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCandidateService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProbeRunGuard;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveAiProviderSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly AiProviderCandidateService $candidates,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly AiProbeRunGuard $probeRuns
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $profileKey = trim($request->bodyString('profile_key'));
        $copySourceKey = array_key_exists('copy_from', $request->body())
            ? trim($request->bodyString('copy_from'))
            : null;
        try {
            $actor = $this->guard->actor(
                'oj_save_ai_provider_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $operation = trim($request->bodyString('operation'));
            if ($operation === 'test') {
                $body = $request->body();
                $rawTasks = is_array($body['task_types'] ?? null)
                    ? $body['task_types']
                    : [];
                $tasks = [];
                foreach ($rawTasks as $taskType) {
                    if (is_string($taskType) && trim($taskType) !== '') {
                        $tasks[] = trim($taskType);
                    }
                }
                $token = $this->probeRuns->acquire($profileKey, $userId);
                try {
                    $this->candidates->test(
                        $profileKey,
                        trim($request->bodyString('profile_name')),
                        trim($request->bodyString('provider_key')),
                        trim($request->bodyString('base_url')),
                        trim($request->bodyString('authentication_method')),
                        $request->bodyString('secret'),
                        trim($request->bodyString('model')),
                        $tasks,
                        $request->bodyBool('allow_submission_source'),
                        $request->bodyInt('timeout_seconds', 60),
                        $userId,
                        $copySourceKey
                    );
                } finally {
                    $this->probeRuns->release($profileKey, $userId, $token);
                }
                $message = new UiMessage(
                    'ai_provider.test_succeeded',
                    MessageTone::Success,
                    'AI 연결 후보 검사 완료',
                    '모델과 선택한 업무 계약을 모두 통과했습니다.'
                );
            } elseif ($operation === 'reset_probe') {
                $this->probeRuns->reset($profileKey, $userId);
                $message = new UiMessage(
                    'ai_provider.probe_reset',
                    MessageTone::Success,
                    'AI 검사 대기 초기화 완료',
                    '이 프로필의 연결 검사를 다시 시도할 수 있습니다. AI 서버 자체의 요청 한도는 변경하지 않습니다.'
                );
            } elseif ($operation === 'save') {
                $this->candidates->saveTested(
                    $profileKey,
                    $userId,
                    $request->bodyInt('expected_revision')
                );
                $message = new UiMessage(
                    'ai_provider.saved',
                    MessageTone::Success,
                    'AI 연결 프로필 저장 완료',
                    '검사한 허용 작업과 fingerprint를 함께 저장했습니다.'
                );
            } else {
                throw new DomainException('AI 연결 처리 방식이 올바르지 않습니다.');
            }

            return $this->responses->redirect(
                '/oj-console/settings/ai-provider?profile_key='
                    . rawurlencode($profileKey)
                    . ($copySourceKey !== null && $operation !== 'save' ? '&copy_from=' . rawurlencode($copySourceKey) : ''),
                $message,
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/ai-provider'
                    . ($copySourceKey !== null
                        ? '?copy_from=' . rawurlencode($copySourceKey)
                        : ($profileKey !== '' ? '?profile_key=' . rawurlencode($profileKey) : '')),
                new UiMessage(
                    'ai_provider.failed',
                    MessageTone::Danger,
                    'AI 연결 처리 실패',
                    $error instanceof DomainException
                        || $error instanceof \InvalidArgumentException
                        || $error instanceof AiProviderException
                        ? $error->getMessage()
                        : '현재 운영 프로필은 유지했습니다. 후보 연결을 확인해 주세요.'
                ),
                $userId
            );
        }
    }
}
