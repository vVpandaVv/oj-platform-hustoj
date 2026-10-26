<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCommandService;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationStateService;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class MigrationActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $actionKey,
        private readonly string $operation,
        private readonly MigrationCommandService $commands,
        private readonly MigrationStateService $states,
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
                $this->actionKey,
                $request,
                'oj_manage_migration'
            );
            $userId = $actor->userId();
            $detail = $this->execute($request, $userId);

            return $this->responses->redirect(
                '/oj-console/settings/migration',
                new UiMessage(
                    'migration.' . $this->operation . '.completed',
                    MessageTone::Success,
                    '데이터 이전 요청 완료',
                    $detail
                ),
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/migration',
                new UiMessage(
                    'migration.' . $this->operation . '.failed',
                    MessageTone::Danger,
                    '데이터 이전 요청 실패',
                    $error instanceof DomainException
                        || $error instanceof \InvalidArgumentException
                        ? $error->getMessage()
                        : '화면을 새로 고친 뒤 다시 시도해 주세요.'
                ),
                $userId
            );
        }
    }

    private function execute(HttpRequest $request, int $userId): string
    {
        $revision = $request->bodyInt('expected_revision');
        if ($this->operation === 'scan') {
            $scan = $this->commands->scan($revision);

            return $scan->canPlan()
                ? '읽기 전용 기준 검사를 통과했습니다.'
                : '기준 검사를 마쳤지만 차단 항목을 먼저 해결해야 합니다.';
        }
        if ($this->operation === 'save') {
            $this->commands->savePreparation($request->body(), $revision);

            return '복구 리허설 증거와 담당자 배정을 저장했습니다.';
        }
        if ($this->operation === 'plan') {
            $stageValue = $request->bodyInt('requested_stage', -1);
            $stage = $stageValue >= 0 ? MigrationStage::tryFrom($stageValue) : null;
            $plan = $this->commands->dryRun($revision, $stage);

            return sprintf(
                '%d단계 계획을 만들었습니다. 계획 해시는 %s입니다.',
                $plan->stage()->value,
                $plan->hash()
            );
        }
        if (in_array($this->operation, ['run', 'validate', 'cleanup'], true)) {
            $state = $this->states->current();
            if ($this->operation === 'validate'
                && $state->stage() !== MigrationStage::Validation) {
                throw new DomainException('현재 계획은 최종 검증 단계가 아닙니다.');
            }
            if ($this->operation === 'cleanup'
                && $state->stage() !== MigrationStage::Cleanup) {
                throw new DomainException('현재 계획은 정리 보고서 단계가 아닙니다.');
            }
            $result = $this->commands->enqueue(
                $userId,
                $request->bodyString('plan_hash'),
                $request->bodyString('confirmation'),
                $revision
            );

            return $result->wasCreated()
                ? '승인된 계획을 Jobs에 등록했습니다.'
                : '같은 승인 계획의 기존 Jobs를 확인했습니다.';
        }
        if ($this->operation === 'deactivate') {
            $result = $this->commands->deactivateLegacyPlugins(
                $userId,
                $request->bodyString('confirmation'),
                $revision
            );
            if ($result->needsAttention()) {
                throw new DomainException($result->message());
            }

            return '승인된 기존 플러그인 비활성화를 완료했습니다.';
        }

        throw new DomainException('등록되지 않은 데이터 이전 명령입니다.');
    }
}
