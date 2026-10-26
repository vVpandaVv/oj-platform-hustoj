<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmitSubmissionService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SubmitSubmissionActionHandler implements ActionHandler
{
    private const ACTION = 'oj_submit_submission';

    public function __construct(
        private readonly SubmitSubmissionService $submissions,
        private readonly SubmissionRequestFactory $requests,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $problemId = max(0, $request->bodyInt('problem_id'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $result = $this->submissions->submit(
                $this->requests->command($actor, $request),
                fn (int $count, int $seconds) => $this->guard->throttle(
                    'submission_create', (string) $userId, $count, $seconds
                )
            );

            $restricted = $this->performanceMode
                ->restrictsOwnSubmissionDetails($userId);
            return $this->responses->redirect(
                $restricted
                    ? $this->resultListPath($problemId, $request)
                    : $this->detailPath(
                        $result->submissionId()->value(),
                        $request
                    ),
                new UiMessage(
                    'submissions.created',
                    $result->hasAuditWarning()
                        ? MessageTone::Warning
                        : MessageTone::Success,
                    $result->wasReused()
                        ? '기존 제출 확인'
                        : '제출 완료',
                    $result->hasAuditWarning()
                        ? '채점 요청은 완료되었지만 감사 기록을 운영자가 확인해야 합니다.'
                        : ($restricted
                            ? '제출 목록에서 채점 결과를 확인할 수 있습니다.'
                            : '채점 결과는 이 화면에서 자동으로 갱신됩니다.')
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure(
                $problemId,
                $userId,
                $error->getMessage(),
                $request
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                $problemId,
                $userId,
                '제출을 완료하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
                $request
            );
        }
    }

    private function failure(
        int $problemId,
        int $userId,
        string $message,
        HttpRequest $request
    ): HttpResponse {
        return $this->responses->redirect(
            $this->formPath($problemId, $request),
            new UiMessage(
                'submissions.create_failed',
                MessageTone::Danger,
                '제출 실패',
                $message
            ),
            $userId
        );
    }

    private function formPath(int $problemId, HttpRequest $request): string
    {
        $path = $problemId > 0 ? '/submit/' . $problemId : '/submit';

        return $this->withContextQuery($path, $request);
    }

    private function detailPath(int $submissionId, HttpRequest $request): string
    {
        return $this->withContextQuery('/submissions/' . $submissionId, $request);
    }

    private function withContextQuery(string $path, HttpRequest $request): string
    {
        $query = [];
        $contestId = max(0, $request->bodyInt('contest_id'));
        $classId = max(0, $request->bodyInt('class_id'));
        $groupId = max(0, $request->bodyInt('group_id'));
        if ($contestId > 0) {
            $query['contest_id'] = $contestId;
            $query['contest_problem_number'] = $request->bodyInt(
                'contest_problem_number',
                -1
            );
        }
        if ($classId > 0) {
            $query['class_id'] = $classId;
        }
        if ($groupId > 0) {
            $query['group_id'] = $groupId;
        }

        return $query === []
            ? $path
            : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function resultListPath(int $problemId, HttpRequest $request): string
    {
        $contestId = max(0, $request->bodyInt('contest_id'));
        if ($contestId > 0) {
            return '/contests/' . $contestId . '/submissions';
        }
        $classId = max(0, $request->bodyInt('class_id'));
        if ($classId > 0) {
            return '/classes/' . $classId . '/submissions';
        }
        $groupId = max(0, $request->bodyInt('group_id'));
        if ($groupId > 0) {
            return '/groups/' . $groupId . '/submissions';
        }

        return $problemId > 0
            ? '/problems/' . $problemId . '/submissions'
            : '/submissions';
    }
}
