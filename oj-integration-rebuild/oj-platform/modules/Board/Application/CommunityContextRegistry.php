<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Contract\RecentCommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use DomainException;
use Throwable;

final class CommunityContextRegistry
{
    /** @var array<string, CommunityContextProvider> */
    private array $providers = [];

    public function __construct(
        private readonly BoardSettingsService $settings,
        private readonly FeatureFlags $features,
        private readonly Authorization $permissions
    ) {
    }

    public function register(CommunityContextProvider $provider): void
    {
        $type = $provider->type();
        if (
            ! in_array($type, ['problem', 'class', 'contest', 'group'], true)
            || isset($this->providers[$type])
        ) {
            throw new DomainException('커뮤니티 문맥 Provider 등록이 올바르지 않습니다: ' . $type);
        }
        $this->providers[$type] = $provider;
    }

    public function resolve(
        string $type,
        int $targetId,
        int $userId
    ): CommunityContextAccess {
        if ($type === 'global') {
            $moderate = $this->permissions->can($userId, 'oj_manage_board');
            return new CommunityContextAccess(
                CommunityContext::global(),
                true,
                $this->settings->current()->globalPosting() || $moderate,
                $moderate
            );
        }

        $fallback = new CommunityContext(
            $type,
            $targetId,
            $this->typeLabel($type) . ' #' . $targetId
        );
        if (
            ! $this->settings->current()->questionsEnabled($type)
            || ! $this->featureAvailable($type)
        ) {
            return $this->orphaned($fallback, $userId);
        }

        $provider = $this->providers[$type] ?? null;
        try {
            $access = $provider?->resolve($targetId, $userId);
        } catch (Throwable) {
            $access = null;
        }
        if ($access === null) {
            return $this->orphaned($fallback, $userId);
        }
        if ($access->isProtected()) {
            return $access;
        }

        $globalModerator = $this->permissions->can($userId, 'oj_manage_board');
        return new CommunityContextAccess(
            $access->context(),
            $access->canView() || $globalModerator,
            $access->canPost(),
            $access->canModerate() || $globalModerator,
            false,
            $access->isOrphaned()
        );
    }

    /**
     * @param list<string> $excludeTypes
     * @return list<CommunityContextAccess>
     */
    public function choices(
        int $userId,
        int $limit = 200,
        bool $postingOnly = true,
        array $excludeTypes = []
    ): array {
        $limit = max(1, min(500, $limit));
        $global = $this->resolve('global', 0, $userId);
        $choices = $this->selectable($global, $postingOnly)
            ? [$global]
            : [];
        foreach ($this->providers as $type => $provider) {
            if (
                count($choices) >= $limit
                || in_array($type, $excludeTypes, true)
                || ! $this->settings->current()->questionsEnabled($type)
                || ! $this->featureAvailable($type)
            ) {
                continue;
            }
            try {
                $providerChoices = $provider->choices(
                    $userId,
                    $limit - count($choices)
                );
            } catch (Throwable) {
                continue;
            }
            foreach ($providerChoices as $choice) {
                if ($this->selectable($choice, $postingOnly)) {
                    $choices[] = $choice;
                }
            }
        }

        return $choices;
    }

    /**
     * @return list<CommunityContextAccess>
     */
    public function recentProblemChoices(int $userId, int $limit = 5): array
    {
        $provider = $this->providers['problem'] ?? null;
        if (
            $provider === null
            || ! $this->settings->current()->questionsEnabled('problem')
            || ! $this->featureAvailable('problem')
        ) {
            return [];
        }
        try {
            $providerChoices = $provider instanceof RecentCommunityContextProvider
                ? $provider->recentChoices($userId, $limit)
                : $provider->choices($userId, $limit);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $providerChoices,
            fn (CommunityContextAccess $choice): bool => $this->selectable($choice, true)
        ));
    }

    private function selectable(
        CommunityContextAccess $access,
        bool $postingOnly
    ): bool {
        if ($access->isProtected() || $access->isOrphaned()) {
            return false;
        }

        return $postingOnly
            ? ($access->canPost() || $access->canModerate())
            : ($access->canView() || $access->canModerate());
    }

    private function orphaned(
        CommunityContext $context,
        int $userId
    ): CommunityContextAccess {
        $moderate = $this->permissions->can($userId, 'oj_manage_board');

        return new CommunityContextAccess(
            $context,
            $moderate,
            false,
            $moderate,
            false,
            true
        );
    }

    private function featureAvailable(string $type): bool
    {
        $feature = match ($type) {
            'problem' => 'problems',
            'class' => 'classes',
            'contest' => 'contests',
            'group' => 'groups',
            default => '',
        };
        if ($feature === '') {
            return false;
        }

        try {
            return $this->features->isEnabled($feature);
        } catch (Throwable) {
            return false;
        }
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'problem' => '문제',
            'class' => '수업',
            'contest' => '대회·평가',
            'group' => '그룹',
            default => throw new DomainException('지원하지 않는 커뮤니티 문맥입니다.'),
        };
    }
}
