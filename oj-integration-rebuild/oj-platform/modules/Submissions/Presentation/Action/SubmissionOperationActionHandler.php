<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionOperationService;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SubmissionOperationActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $action,
        private readonly string $operation,
        private readonly SubmissionOperationService $submissions,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $redirectPath = $this->redirectPath($request);
        try {
            $actor = $this->guard->authenticatedActor(
                $this->action,
                $request
            );
            $actorUserId = $actor->userId();
            $ids = array_values(array_map(
                static fn (int $id): SubmissionId => new SubmissionId($id),
                array_filter(
                    $this->submissionIds($request),
                    static fn (int $id): bool => $id > 0
                )
            ));
            $reason = $request->bodyString('reason');
            $requestKey = strtolower(trim(
                $request->bodyString('request_key')
            ));
            $requestKey = $requestKey === '' ? null : $requestKey;
            $count = match ($this->operation) {
                'rejudge' => $this->submissions->rejudge(
                    $actorUserId,
                    $ids,
                    $reason,
                    $requestKey
                ),
                'invalidate' => $this->submissions->setValidity(
                    $actorUserId,
                    $ids,
                    false,
                    $reason,
                    $requestKey
                ),
                'restore' => $this->submissions->setValidity(
                    $actorUserId,
                    $ids,
                    true,
                    $reason,
                    $requestKey
                ),
                default => throw new \LogicException(
                    '정의되지 않은 제출 관리 작업입니다.'
                ),
            };

            return $this->responses->redirect(
                $redirectPath,
                new UiMessage(
                    'submissions.operation_completed',
                    MessageTone::Success,
                    $this->title() . ' 완료',
                    number_format($count) . '건을 처리했습니다.'
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $redirectPath,
                new UiMessage(
                    'submissions.operation_failed',
                    MessageTone::Danger,
                    $this->title() . ' 실패',
                    $error instanceof \DomainException
                        || $error instanceof \InvalidArgumentException
                            ? $error->getMessage()
                            : '선택한 제출을 처리하지 못했습니다.'
                ),
                $actorUserId
            );
        }
    }

    /**
     * @return list<int>
     */
    private function submissionIds(HttpRequest $request): array
    {
        $ids = $request->bodyIntList('submission_ids');
        if ($ids === []) {
            $single = $request->bodyInt('submission_id');
            if ($single > 0) {
                $ids[] = $single;
            }
        }

        return $ids;
    }

    private function title(): string
    {
        return match ($this->operation) {
            'rejudge' => '재채점 요청',
            'invalidate' => '무효 처리',
            'restore' => '복원',
            default => '제출 처리',
        };
    }

    private function redirectPath(HttpRequest $request): string
    {
        $requested = trim($request->bodyString('return_path'));
        if (preg_match(
            '#^/oj-console/(?:classes|contests|groups)/[1-9][0-9]*/submissions$#',
            $requested
        ) === 1) {
            return $requested;
        }

        return $this->operation === 'restore'
            ? '/oj-console/submissions/deleted'
            : '/oj-console/submissions';
    }
}
