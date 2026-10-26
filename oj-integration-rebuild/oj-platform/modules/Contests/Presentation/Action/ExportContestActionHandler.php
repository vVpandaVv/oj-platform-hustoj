<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblemResult;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestReport;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Shared\Export\SpreadsheetCsv;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class ExportContestActionHandler implements ActionHandler
{
    private const ACTION = 'oj_contest_export';

    public function __construct(
        private readonly ContestService $contests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $contestId = $request->bodyInt('contest_id');
        $reportType = $request->bodyString('report_type', 'ranking');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $report = $this->contests->exportReport(
                $actorId,
                $actor->userLogin(),
                $contestId
            );
            $statistics = $reportType === 'statistics';
            $lines = $statistics
                ? $this->statisticsLines($report)
                : $this->rankingLines($report);

            return HttpResponse::download(
                'contest-' . $contestId . '-'
                    . ($statistics ? 'statistics' : 'ranking') . '.csv',
                'text/csv',
                implode("\r\n", $lines) . "\r\n"
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/contests/' . $contestId . '/'
                    . ($reportType === 'statistics'
                        ? 'statistics' : 'ranking'),
                new UiMessage(
                    'contests.export_failed',
                    MessageTone::Danger,
                    '내보내기 실패',
                    '대회 순위를 내보내지 못했습니다.'
                ),
                $actorId
            );
        }
    }

    /** @return list<string> */
    private function rankingLines(ContestReport $report): array
    {
        $dates = $this->dateFormatter();
        $header = ['rank', 'user_login', 'display_name'];
        foreach ($report->problems() as $problem) {
            $prefix = 'problem_' . $problem->problemId();
            foreach ([
                'score',
                'accepted',
                'pass_rate',
                'attempts',
                'penalty_attempts',
                'first_accepted_seconds',
            ] as $field) {
                $header[] = $prefix . '_' . $field;
            }
        }
        array_push(
            $header,
            'score',
            'solved',
            'penalty',
            'completion_seconds',
            'latest_submission_at',
            'site_timezone'
        );
        $lines = [implode(',', $header)];
        foreach ($report->rankings() as $ranking) {
            $values = [
                (string) $ranking->rank(),
                $this->csv($ranking->userLogin()),
                $this->csv($ranking->displayName()),
            ];
            foreach ($report->problems() as $problem) {
                $result = $ranking->problemResults()[$problem->problemId()]
                    ?? null;
                if (! $result instanceof ContestProblemResult) {
                    array_push($values, '0', '0', '0', '0', '0', '');
                    continue;
                }
                array_push(
                    $values,
                    (string) $result->score(),
                    $result->isAccepted() ? '1' : '0',
                    (string) $result->bestPassRate(),
                    (string) $result->attemptCount(),
                    (string) $result->penaltyAttemptCount(),
                    $result->firstAcceptedElapsedSeconds() === null
                        ? ''
                        : (string) $result->firstAcceptedElapsedSeconds()
                );
            }
            array_push(
                $values,
                (string) $ranking->score(),
                (string) $ranking->solved(),
                (string) $ranking->penalty(),
                (string) $ranking->completionSeconds(),
                $this->csv($ranking->latestSubmittedAt() === null
                    ? ''
                    : $dates->export($ranking->latestSubmittedAt())),
                $this->csv($dates->timezoneLabel($ranking->latestSubmittedAt()))
            );
            $lines[] = implode(',', $values);
        }

        return $lines;
    }

    /** @return list<string> */
    private function statisticsLines(ContestReport $report): array
    {
        $statistics = $report->statistics();
        $header = implode(',', [
            'problem_number',
            'problem_id',
            'problem_score',
            'submissions',
            'attempted_participants',
            'accepted_participants',
            'accepted_rate',
            'average_attempts',
            'total_participants',
            'total_submissions',
            'average_score',
            'average_solved',
            'no_submission_participants',
        ]);
        $problemsById = [];
        foreach ($report->problems() as $problem) {
            $problemsById[$problem->problemId()] = $problem;
        }
        $lines = [$header];
        foreach ($statistics->problems() as $item) {
            $problem = $problemsById[$item->problemId()] ?? null;
            if ($problem === null) {
                continue;
            }
            $lines[] = implode(',', [
                $problem->numberLabel(),
                $problem->problemId(),
                $problem->score(),
                $item->submissionCount(),
                $item->attemptedParticipantCount(),
                $item->acceptedParticipantCount(),
                round($item->acceptedRate(), 6),
                round($item->averageAttempts(), 6),
                $statistics->participantCount(),
                $statistics->submissionCount(),
                round($statistics->averageScore(), 6),
                round($statistics->averageSolved(), 6),
                $statistics->noSubmissionCount(),
            ]);
        }

        return $lines;
    }

    private function csv(string $value): string
    {
        return SpreadsheetCsv::cell($value);
    }

    private function dateFormatter(): SiteDateFormatter
    {
        return isset($this->dates) ? $this->dates : new SiteDateFormatter();
    }
}
