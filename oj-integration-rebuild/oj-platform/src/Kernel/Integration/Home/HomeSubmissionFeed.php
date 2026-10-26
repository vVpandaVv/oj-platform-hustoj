<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class HomeSubmissionFeed
{
    /** @var array<int, list<HomeSubmissionEntry>> */
    private array $entries = [];

    /** @var array<int, bool> */
    private array $restricted = [];

    public function __construct(
        private readonly SubmissionQueryService $submissions,
        private readonly SubmissionLanguageCatalog $languages,
        private readonly LearningProblemProvider $problems,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    /** @return list<HomeSubmissionEntry> */
    public function entries(int $userId, string $userLogin): array
    {
        if (isset($this->entries[$userId])) {
            return $this->entries[$userId];
        }
        $summaries = $this->submissions->recentOwn($userLogin, 6);
        $problemIds = array_values(array_unique(array_map(
            static fn ($summary): int => $summary->problemId(),
            $summaries
        )));
        $problems = $this->problems->findManyForUser($userId, $problemIds);
        $restricted = $this->isRestricted($userId);
        $entries = [];
        foreach ($summaries as $summary) {
            $problem = $problems[$summary->problemId()] ?? null;
            $result = $this->submissions->result($summary->resultCode());
            $entries[] = new HomeSubmissionEntry(
                $summary->submissionId()->value(),
                $summary->problemId(),
                $problem?->title() ?? ('문제 ' . $summary->problemId()),
                $result->label(),
                $result->tone(),
                $restricted ? '' : $this->languages->label($summary->languageId()),
                $this->dates->format($summary->submittedAt()),
                $summary->resultCode()
            );
        }

        return $this->entries[$userId] = $entries;
    }

    public function isRestricted(int $userId): bool
    {
        if (! array_key_exists($userId, $this->restricted)) {
            $this->restricted[$userId] = $this->performanceMode
                ->restrictsOwnSubmissionDetails($userId);
        }

        return $this->restricted[$userId];
    }

    public function hasAccepted(string $userLogin, int $problemId): bool
    {
        return $this->submissions->hasAcceptedOwn($userLogin, $problemId);
    }
}
