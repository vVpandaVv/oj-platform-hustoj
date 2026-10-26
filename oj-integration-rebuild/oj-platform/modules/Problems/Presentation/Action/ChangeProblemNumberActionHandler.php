<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemNumberChangeService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ChangeProblemNumberActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_change_id';
    private const PATH = '/oj-console/problems/change-id';

    public function __construct(
        private readonly ProblemNumberChangeService $service,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $mapping = $this->service->deserializeMapping(
                $request->bodyString('mapping_json')
            );
            $result = $this->service->enqueue(
                $actorUserId,
                $mapping,
                $request->bodyString('plan_hash')
            );

            return $this->responses->redirect(
                self::PATH,
                new UiMessage(
                    'problems.number_change_registered',
                    MessageTone::Success,
                    '문제 번호 변경 작업 등록',
                    '작업 ' . $result->jobId()
                        . '번을 등록했습니다. 실행 전 현재 상태를 다시 검증합니다.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                self::PATH,
                new UiMessage(
                    'problems.number_change_rejected',
                    MessageTone::Danger,
                    '문제 번호 변경 작업 거부',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                self::PATH,
                new UiMessage(
                    'problems.number_change_failed',
                    MessageTone::Danger,
                    '문제 번호 변경 작업 실패',
                    '작업을 등록하지 못했습니다. 시스템 상태를 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
