<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class GenerateProblemMetadataActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $actionKey,
        private readonly bool $filtered,
        private readonly ProblemAiJobService $aiJobs,
        private readonly ProblemCatalogService $catalog,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->authenticatedActor($this->actionKey, $request);
            $actorUserId = $actor->userId();
            $problemIds = $this->filtered
                ? $this->filteredProblemIds($request, $actorUserId)
                : array_map(
                    static fn (int $problemId): ProblemId => new ProblemId($problemId),
                    $request->bodyIntList('problem_ids')
                );
            if ($problemIds === []) {
                throw new DomainException('메타데이터를 생성할 문제를 선택해 주세요.');
            }
            $executionMode = $this->filtered
                ? 'filtered'
                : (count($problemIds) === 1 ? 'single' : 'selected');
            $result = $this->aiJobs->enqueueMetadata(
                $actorUserId,
                $problemIds,
                $executionMode
            );

            return $this->responses->redirect(
                '/oj-console/jobs/' . $result->jobId(),
                new UiMessage(
                    'problems.metadata_job_registered',
                    MessageTone::Success,
                    '메타데이터 작업 등록',
                    sprintf(
                        '문제 %d개의 메타데이터 생성 작업을 등록했습니다.',
                        count($problemIds)
                    )
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/oj-console/problems',
                new UiMessage(
                    'problems.metadata_job_rejected',
                    MessageTone::Danger,
                    '메타데이터 작업 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/problems',
                new UiMessage(
                    'problems.metadata_job_failed',
                    MessageTone::Danger,
                    '메타데이터 작업 실패',
                    '작업을 등록하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $actorUserId
            );
        }
    }

    /**
     * @return list<ProblemId>
     */
    private function filteredProblemIds(
        HttpRequest $request,
        int $actorUserId
    ): array {
        $keyword = trim($request->bodyString('q'));
        $visibility = match ($request->bodyString('visibility')) {
            'published' => ProblemVisibility::Published,
            'archived' => ProblemVisibility::Archived,
            default => null,
        };
        $specialJudge = match ($request->bodyString('judging')) {
            'special' => true,
            'standard' => false,
            default => null,
        };
        $total = $this->catalog->managedCount(
            $actorUserId,
            $keyword,
            $visibility,
            $specialJudge
        );
        if ($total > 500) {
            throw new DomainException(
                '필터 결과가 500개를 초과합니다. 조건을 더 좁혀 주세요.'
            );
        }

        return array_map(
            static fn (JudgeProblem $problem): ProblemId => $problem->id(),
            $this->catalog->managedPage(
                $actorUserId,
                $keyword,
                $visibility,
                500,
                0,
                $specialJudge
            )
        );
    }
}
