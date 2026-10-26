<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemV1;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use Throwable;

/** @internal */
final class RealAddonIntegrationGateway implements AddonIntegrationGatewayV1
{
    public function __construct(
        private readonly string $addonId,
        private readonly AcceptedAddonCatalog $catalog,
        private readonly RouteUrlProvider $routeUrls,
        private readonly ProblemCatalogService $problems,
        private readonly SubmissionQueryService $submissions,
        private readonly WordPressUserGateway $users,
        private readonly Authorization $permissions,
        private readonly AddonAssignmentRosterGatewayV1 $rosters,
        private readonly AddonCorrelationIdFactory $correlations
    ) {
    }

    public function searchActivities(AddonActivityQueryV1 $query): AddonActivityPageV1
    {
        if (! $this->isCurrentActor($query->actorUserId())) {
            return new AddonActivityPageV1(false, [], $this->denied('activities'));
        }
        try {
            $items = [];
            foreach ($this->catalog->accepted() as $entry) {
                if ($entry->addonId() === $this->addonId) {
                    continue;
                }
                $manifest = $entry->manifest();
                if (! $manifest instanceof AddonManifest) {
                    continue;
                }
                foreach ($manifest->publicMenus() as $menu) {
                    if (! $this->permissions->can(
                        $query->actorUserId(),
                        AddonCapabilityKeys::capabilityKey($menu->capability())
                    )) {
                        continue;
                    }
                    $activity = new AddonActivityV1(
                        $menu->key(),
                        $menu->label(),
                        '',
                        $this->routeUrls->path($menu->route()),
                        'page',
                        $manifest->diagnosticLabel()
                    );
                    $this->appendActivity($items, $activity, $query);
                }
                foreach ($manifest->activityProviders() as $definition) {
                    try {
                        $page = $definition->provider()->search($query);
                        if (! $page->isSuccessful()) {
                            continue;
                        }
                        foreach ($page->items() as $activity) {
                            $this->appendActivity($items, $activity, $query);
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
            }
            uasort($items, static fn (AddonActivityV1 $left, AddonActivityV1 $right): int =>
                [$left->sourceLabel(), $left->title(), $left->key()]
                <=> [$right->sourceLabel(), $right->title(), $right->key()]
            );

            return new AddonActivityPageV1(true, array_slice(array_values($items), 0, $query->limit()), null);
        } catch (Throwable) {
            return new AddonActivityPageV1(false, [], $this->unavailable('activities'));
        }
    }

    public function searchProblems(AddonProblemQueryV1 $query): AddonProblemPageV1
    {
        if (! $this->isCurrentActor($query->actorUserId())) {
            return new AddonProblemPageV1(false, [], $this->denied('problems'));
        }
        try {
            $items = array_map(
                static fn ($problem): AddonProblemV1 => new AddonProblemV1(
                    $problem->id()->value(),
                    $problem->title(),
                    $problem->submitted(),
                    $problem->accepted()
                ),
                $this->problems->publicPage($query->search(), $query->limit(), 0)
            );

            return new AddonProblemPageV1(true, $items, null);
        } catch (Throwable) {
            return new AddonProblemPageV1(false, [], $this->unavailable('problems'));
        }
    }

    public function acceptedSubmissions(AddonAcceptedSubmissionQueryV1 $query): AddonAcceptedSubmissionPageV1
    {
        if (! $this->isCurrentActor($query->actorUserId())) {
            return new AddonAcceptedSubmissionPageV1(false, [], $this->denied('submissions'));
        }
        try {
            $allowed = $this->authorizedRoster($query);
            if ($allowed === null) {
                return new AddonAcceptedSubmissionPageV1(false, [], $this->denied('submissions'));
            }
            $requested = $query->learnerUserIds();
            $learnerIds = $requested === [] ? array_keys($allowed) : $requested;
            foreach ($learnerIds as $userId) {
                if (! isset($allowed[$userId])) {
                    return new AddonAcceptedSubmissionPageV1(false, [], $this->denied('submissions'));
                }
            }
            $loginToUserId = [];
            foreach ($this->users->findMany($learnerIds) as $identity) {
                $loginToUserId[$identity->userLogin()] = $identity->userId();
            }
            if (count($loginToUserId) !== count(array_unique($learnerIds))) {
                return new AddonAcceptedSubmissionPageV1(false, [], $this->denied('submissions'));
            }
            $earliest = [];
            foreach ($this->submissions->acceptedTimesForUsers(
                array_keys($loginToUserId),
                $query->problemIds(),
                $query->submittedFrom(),
                $query->submittedUntil()
            ) as $submission) {
                $userId = $loginToUserId[$submission['user_login']] ?? 0;
                if ($userId < 1) {
                    continue;
                }
                $key = $userId . ':' . $submission['problem_id'];
                $acceptedAt = $submission['accepted_at'];
                if (! isset($earliest[$key]) || $acceptedAt < $earliest[$key]['acceptedAt']) {
                    $earliest[$key] = [
                        'userId' => $userId,
                        'problemId' => $submission['problem_id'],
                        'acceptedAt' => $acceptedAt,
                    ];
                }
            }
            usort($earliest, static fn (array $left, array $right): int =>
                [$left['userId'], $left['problemId']] <=> [$right['userId'], $right['problemId']]
            );
            $items = array_map(
                static fn (array $row): AddonAcceptedSubmissionV1 => new AddonAcceptedSubmissionV1(
                    $row['userId'],
                    $row['problemId'],
                    $row['acceptedAt']
                ),
                array_values($earliest)
            );

            return new AddonAcceptedSubmissionPageV1(true, $items, null);
        } catch (Throwable) {
            return new AddonAcceptedSubmissionPageV1(false, [], $this->unavailable('submissions'));
        }
    }

    public function privacyManagementPath(): string
    {
        return '/oj-console/agreements';
    }

    /** @param array<string, AddonActivityV1> $items */
    private function appendActivity(array &$items, AddonActivityV1 $activity, AddonActivityQueryV1 $query): void
    {
        if ($query->kinds() !== [] && ! in_array($activity->kind(), $query->kinds(), true)) {
            return;
        }
        $search = self::lower($query->search());
        if ($search !== '' && ! str_contains(
            self::lower($activity->title() . ' ' . $activity->description() . ' ' . $activity->sourceLabel()),
            $search
        )) {
            return;
        }
        $items[$activity->key()] ??= $activity;
    }

    /** @return array<int, true>|null */
    private function authorizedRoster(AddonAcceptedSubmissionQueryV1 $query): ?array
    {
        $cursor = '';
        $revision = null;
        $allowed = [];
        for ($page = 0; $page < 50; $page++) {
            $result = $this->rosters->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
                $query->actorUserId(),
                $query->contextType(),
                $query->contextId(),
                $query->contextRevision(),
                $revision,
                $cursor,
                100
            ));
            if (! $result->isSuccessful()) {
                return null;
            }
            foreach ($result->items() as $learner) {
                $allowed[$learner->learnerUserId()] = true;
            }
            $revision = $result->rosterRevision();
            $cursor = $result->nextCursor();
            if ($cursor === '') {
                return $allowed;
            }
        }

        return null;
    }

    private function isCurrentActor(int $actorUserId): bool
    {
        return $this->users->currentUser()?->userId() === $actorUserId;
    }

    private function denied(string $operation): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_access_denied',
            '이 통합 기능에 접근할 수 없습니다.',
            $this->correlations->next($this->addonId . ':integration-' . $operation),
            false
        );
    }

    private function unavailable(string $operation): AddonApiProblemV1
    {
        return new AddonApiProblemV1(
            'addon_runtime_unavailable',
            '플랫폼 통합 기능을 사용할 수 없습니다.',
            $this->correlations->next($this->addonId . ':integration-' . $operation),
            true
        );
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
