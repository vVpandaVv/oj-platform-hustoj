<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceBatchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\JudgeUserActivityProvider;
use CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingSnapshotCache;
use CodingPanda\OjPlatform\Modules\Ranking\Domain\RankingEntry;

final class RankingService
{
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly JudgeUserActivityProvider $activityProvider,
        private readonly WordPressUserGateway $users,
        private readonly UserPreferenceBatchProvider $preferences,
        private readonly RankingSnapshotCache $cache
    ) {
    }

    /** @return list<RankingEntry> */
    public function entries(): array
    {
        $activity = $this->activity();
        $identities = [];
        foreach ($this->users->findMany(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $activity
        )) as $identity) {
            $identities[$identity->userId()] = $identity;
        }
        $preferenceMap = $this->preferences->findMany(array_keys($identities));

        $rows = [];
        foreach ($activity as $summary) {
            $userId = (int) ($summary['user_id'] ?? 0);
            $user = $identities[$userId] ?? null;
            if ($user === null) {
                continue;
            }
            $preferences = $preferenceMap[$userId] ?? null;
            if (
                $preferences !== null
                && $preferences->rankingVisibility() === 'hidden'
            ) {
                continue;
            }
            $rows[] = [
                'user_id' => $userId,
                'display_name' => $user->displayName(),
                'solved' => (int) ($summary['solved'] ?? 0),
                'accepted' => (int) ($summary['accepted'] ?? 0),
                'submitted' => (int) ($summary['submitted'] ?? 0),
                'accuracy' => (float) ($summary['accuracy'] ?? 0.0),
                'profile_visible' =>
                    $preferences === null
                    || $preferences->profileVisibility() === 'members',
            ];
        }
        usort($rows, static function (array $left, array $right): int {
            $solved = $right['solved'] <=> $left['solved'];
            if ($solved !== 0) {
                return $solved;
            }
            $name = strnatcasecmp(
                (string) $left['display_name'],
                (string) $right['display_name']
            );

            return $name !== 0
                ? $name
                : $left['user_id'] <=> $right['user_id'];
        });

        $entries = [];
        $position = 0;
        $rank = 0;
        $previousSolved = null;
        foreach ($rows as $row) {
            ++$position;
            $solved = (int) $row['solved'];
            if ($previousSolved !== $solved) {
                $rank = $position;
                $previousSolved = $solved;
            }
            $entries[] = new RankingEntry(
                $rank,
                (int) $row['user_id'],
                (string) $row['display_name'],
                $solved,
                (int) $row['accepted'],
                (int) $row['submitted'],
                (bool) $row['profile_visible'],
                (float) $row['accuracy']
            );
        }

        return $entries;
    }

    /**
     * @return list<array{
     *   user_id: int,
     *   solved: int,
     *   accepted: int,
     *   submitted: int,
     *   accuracy: float
     * }>
     */
    private function activity(): array
    {
        $cacheKey = 'activity:v6';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $refresh = $this->cache instanceof \CodingPanda\OjPlatform\Modules\Ranking\Contract\RankingRefreshCache ? $this->cache : null;
        if ($refresh !== null && ! $refresh->acquireRefresh($cacheKey)) {
            $stale = $refresh->stale($cacheKey);
            if ($stale !== null) { return $stale; }
            throw new \DomainException('랭킹을 갱신하고 있습니다. 잠시 뒤 다시 확인해 주세요.');
        }
        try {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) { return $cached; }
            $activity = $this->buildActivity();
            $this->cache->put($cacheKey, $activity, self::CACHE_TTL);
            return $activity;
        } finally {
            $refresh?->releaseRefresh($cacheKey);
        }
    }

    private function buildActivity(): array
    {
        $judgeUsers = $this->activityProvider->activeUsers();
        $usersByLogin = $this->users instanceof \CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginBatchProvider
            ? $this->users->findManyByLogin(array_map(static fn ($user): string => $user->userLogin(), $judgeUsers)) : null;
        $activity = [];
        foreach ($judgeUsers as $judgeUser) {
            $user = $usersByLogin === null ? $this->users->findByLogin($judgeUser->userLogin()) : ($usersByLogin[$judgeUser->userLogin()] ?? null);
            if ($user === null) {
                continue;
            }
            $submitted = max(0, $judgeUser->submissionCount());
            $accepted = min(
                $submitted,
                max(0, $judgeUser->acceptedSubmissionCount())
            );
            $solved = min(
                $accepted,
                max(0, $judgeUser->solvedProblemCount())
            );
            $activity[] = [
                'user_id' => $user->userId(),
                'solved' => $solved,
                'accepted' => $accepted,
                'submitted' => $submitted,
                'accuracy' => $submitted > 0 ? $accepted / $submitted : 0.0,
            ];
        }
        return $activity;
    }
}
