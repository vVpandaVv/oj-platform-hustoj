<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Application;

use CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;
use CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use DomainException;
use Throwable;

final class PublicMenuSettingsService
{
    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     */
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly DefinitionRegistry $routes,
        private readonly RouteHandlerCollection $handlers,
        private readonly RouteUrlProvider $urls,
        private readonly FeatureFlags $features,
        private readonly Authorization $permissions,
        private readonly AddonPublicMenuContributions $addonMenus = new AddonPublicMenuContributions()
    ) {
    }

    /**
     * D-010 default names that changed. A stored entry still carrying the old
     * default name was never renamed by an admin, so it takes the new one.
     * (The old 대회·평가 root is dropped automatically: its route now belongs
     * to the 수업 child, which is claimed first.)
     */
    private const RENAMED_DEFAULTS = [
        'learning_support' => '학습지원',
        'classes.index' => '수업',
    ];

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read(
            'public_menu',
            ['items' => array_map(
                static fn (PublicMenuEntry $entry): array => $entry->toArray(),
                $this->defaults()
            )]
        );
    }

    /**
     * @return list<PublicMenuEntry>
     */
    public function entries(): array
    {
        $value = $this->snapshot()->value();
        $raw = is_array($value) && is_array($value['items'] ?? null)
            ? $value['items']
            : [];
        $stored = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                throw new DomainException('사용자 메뉴 저장 형식이 올바르지 않습니다.');
            }
            $entry = PublicMenuEntry::fromArray($item);
            $stored[$entry->key()] = $entry;
        }

        $rootKeys = [];
        foreach ($this->defaults() as $canonical) {
            if ($canonical->level() === 1) {
                $rootKeys[$canonical->key()] = true;
            }
        }

        $reconciled = [];
        $state = ['routes' => [], 'roots' => 0, 'children' => []];
        foreach ($this->defaults() as $canonical) {
            $candidate = $stored[$canonical->key()] ?? $canonical;
            unset($stored[$canonical->key()]);
            if ($candidate !== $canonical && (self::RENAMED_DEFAULTS[$candidate->key()] ?? null) === $candidate->label()) {
                // A saved copy of the old default name is not an admin choice: follow the new name.
                $candidate = $this->relabeled($candidate, $canonical->label());
            }
            if (
                $candidate->level() === 2
                && ! isset($rootKeys[$candidate->parentKey() ?? ''])
            ) {
                // The admin's own customization (enabled/order/label/...) survives;
                // only the stale parent reference gets corrected to where the menu
                // structure now actually puts this key.
                $candidate = $this->reparented($candidate, $canonical->parentKey());
            }
            if (! $this->isConsistent($candidate, $rootKeys, $state)) {
                $candidate = $canonical;
            }
            $reconciled[$canonical->key()] = $candidate;
            $this->claim($candidate, $state);
        }
        foreach ($stored as $key => $entry) {
            if (
                // A root that only opens its first child was a core default
                // (학습, 대회·평가 …) that no longer exists; admins add link roots.
                ($entry->level() === 1 && $entry->routeKey() === 'first_accessible_child')
                || $this->isStaleAddonEntry($entry)
                || ! $this->isConsistent($entry, $rootKeys, $state)
            ) {
                continue;
            }
            $reconciled[$key] = $entry;
            $this->claim($entry, $state);
        }

        return $this->validate(array_values($reconciled));
    }

    /**
     * 저장된 메뉴 항목이 현재 기본 메뉴 구조와 여전히 맞는지 확인한다. 관리자가 기본
     * 메뉴 구조를 바꾸면, 예전에 저장된 값이 더 이상 유효하지 않은 상위 메뉴를
     * 가리키거나, 새 기본 항목과 같은 경로를 가리키거나, 더 이상 존재하지 않는
     * 1차 메뉴 자리를 차지해 개수 제한을 넘길 수 있어 이를 걸러낸다. entries()는
     * 화면을 그리는 데 쓰이므로, 저장된 값이 낡았다고 해서 예외를 던져 화면 전체를
     * 막으면 안 되고 조용히 최신 기본값으로 되돌리거나 걸러내야 한다.
     *
     * @param array<string, bool> $rootKeys
     * @param array{routes: array<string, bool>, roots: int, children: array<string, int>} $state
     */
    private function isConsistent(
        PublicMenuEntry $entry,
        array $rootKeys,
        array $state
    ): bool {
        if ($entry->level() === 1) {
            if (! isset($rootKeys[$entry->key()]) && $state['roots'] >= 8) {
                return false;
            }
        } else {
            $parent = $entry->parentKey() ?? '';
            if (! isset($rootKeys[$parent])) {
                return false;
            }
            if (($state['children'][$parent] ?? 0) >= 12) {
                return false;
            }
        }
        $routeKey = $entry->routeKey();

        return $routeKey === null
            || $routeKey === 'first_accessible_child'
            || ! isset($state['routes'][$routeKey]);
    }

    /**
     * @param array{routes: array<string, bool>, roots: int, children: array<string, int>} $state
     */
    private function claim(PublicMenuEntry $entry, array &$state): void
    {
        $routeKey = $entry->routeKey();
        if ($routeKey !== null && $routeKey !== 'first_accessible_child') {
            $state['routes'][$routeKey] = true;
        }
        if ($entry->level() === 1) {
            ++$state['roots'];
        } else {
            $parent = $entry->parentKey() ?? '';
            $state['children'][$parent] = ($state['children'][$parent] ?? 0) + 1;
        }
    }

    /**
     * 상위 메뉴만 최신 값으로 바꾸고, 사용 여부·순서·이름 등 나머지 값은 그대로
     * 남긴 새 항목을 만든다. 관리자가 켜둔 항목을 구조 개편 때문에 조용히 꺼진
     * 상태로 되돌리면 안 된다.
     */
    private function reparented(PublicMenuEntry $entry, ?string $parentKey): PublicMenuEntry
    {
        return new PublicMenuEntry(
            $entry->key(),
            $entry->label(),
            $entry->level(),
            $parentKey,
            $entry->routeKey(),
            $entry->externalUrl(),
            $entry->scope(),
            $entry->order(),
            $entry->mobilePosition(),
            $entry->enabled()
        );
    }

    private function relabeled(PublicMenuEntry $entry, string $label): PublicMenuEntry
    {
        return new PublicMenuEntry(
            $entry->key(),
            $label,
            $entry->level(),
            $entry->parentKey(),
            $entry->routeKey(),
            $entry->externalUrl(),
            $entry->scope(),
            $entry->order(),
            $entry->mobilePosition(),
            $entry->enabled()
        );
    }

    private function isStaleAddonEntry(PublicMenuEntry $entry): bool
    {
        $routeKey = $entry->routeKey();

        return $routeKey !== null
            && $routeKey !== 'first_accessible_child'
            && $this->routes->find($routeKey) === null;
    }

    /**
     * @param list<PublicMenuEntry> $entries
     */
    public function save(
        array $entries,
        int $expectedRevision
    ): SettingsSnapshot {
        $entries = $this->validate($entries);

        return $this->settings->save(
            'public_menu',
            ['items' => array_map(
                static fn (PublicMenuEntry $entry): array => $entry->toArray(),
                $entries
            )],
            $expectedRevision
        );
    }

    public function reset(int $expectedRevision): SettingsSnapshot
    {
        return $this->save($this->defaults(), $expectedRevision);
    }

    /**
     * @return array<string, string>
     */
    public function availableInternalRoutes(): array
    {
        $labels = [];
        foreach ($this->defaults() as $entry) {
            if ($entry->routeKey() !== null && $entry->routeKey() !== 'first_accessible_child') {
                $labels[$entry->routeKey()] = $entry->label();
            }
        }
        $available = [];
        foreach ($this->routes->all() as $definition) {
            if (
                ! $definition instanceof RouteDefinition
                || $definition->area()->value !== 'public'
                || $definition->authentication()->value !== 'authenticated'
                || $definition->responseType() !== 'html'
                || $this->handlers->find($definition->key()) === null
                || (
                    $definition->parameters() !== []
                    && $definition->key() !== 'auth.profile'
                )
            ) {
                continue;
            }
            $label = $labels[$definition->key()] ?? $definition->key();
            $available[$definition->key()] = $label . ' (' . $definition->path() . ')';
        }
        asort($available, SORT_NATURAL);

        return $available;
    }

    /**
     * @return list<PublicMenuItem>
     */
    public function resolve(UserIdentity $user): array
    {
        $roots = [];
        $children = [];
        foreach ($this->entries() as $entry) {
            if (! $entry->enabled() || ! $this->scopeAllows($entry->scope(), $user)) {
                continue;
            }
            if ($entry->level() === 1) {
                $roots[$entry->key()] = $entry;
            } else {
                $children[$entry->parentKey() ?? ''][] = $entry;
            }
        }
        $items = [];
        foreach ($roots as $root) {
            $resolvedChildren = [];
            foreach ($children[$root->key()] ?? [] as $child) {
                $resolved = $this->resolveEntry($child, $user);
                if ($resolved !== null) {
                    $resolvedChildren[] = $resolved;
                }
            }
            $rootResolved = $root->routeKey() === 'first_accessible_child'
                ? null
                : $this->resolveEntry($root, $user);
            $firstChild = $resolvedChildren[0] ?? null;
            $url = $rootResolved?->url()
                ?? $firstChild?->url();
            if ($url === null) {
                continue;
            }
            $items[] = new PublicMenuItem(
                $root->key(),
                $root->label(),
                $url,
                $resolvedChildren,
                $root->isExternal()
            );
        }

        return $items;
    }

    /**
     * @return list<PublicMenuEntry>
     */
    public function defaults(): array
    {
        return array_merge($this->coreDefaults(), $this->addonMenus->all());
    }

    /**
     * @return list<PublicMenuEntry>
     */
    private function coreDefaults(): array
    {
        $items = [
            ['home', '홈', 1, null, 'home', 10, 'primary'],
            ['problems', '문제', 1, null, 'first_accessible_child', 20, 'primary'],
            ['submissions', '제출', 1, null, 'submissions.index', 30, 'primary'],
            ['classes', '수업', 1, null, 'first_accessible_child', 40, 'primary'],
            ['learning_support', '학습 활동', 1, null, 'first_accessible_child', 42, 'primary'],
            ['community', '커뮤니티', 1, null, 'first_accessible_child', 50, 'more'],
            ['account', '내 정보', 1, null, 'first_accessible_child', 60, 'more'],
            ['problems.index', '문제 목록', 2, 'problems', 'problems.index', 10, 'primary'],
            ['quizzes.index', '퀴즈', 2, 'problems', 'quizzes.index', 20, 'primary'],
            ['problem_books.index', '문제집', 2, 'problems', 'problem_books.index', 30, 'primary'],
            ['classes.index', '내 수업', 2, 'classes', 'classes.index', 10, 'primary'],
            ['groups.index', '그룹', 2, 'classes', 'groups.index', 20, 'primary'],
            ['contests.index', '대회·평가', 2, 'classes', 'contests.index', 30, 'primary'],
            ['board.index', '게시판', 2, 'community', 'board.index', 10, 'primary'],
            ['notices.index', '공지사항', 2, 'community', 'notices.index', 20, 'primary'],
            ['messaging.inbox', '쪽지', 2, 'community', 'messaging.inbox', 30, 'primary'],
            ['messaging.find', '사용자 찾기', 2, 'community', 'messaging.find', 40, 'primary'],
            ['learning_dashboard.index', '내 학습', 2, 'account', 'learning_dashboard.index', 10, 'primary'],
            ['ranking.index', '전체 랭킹', 2, 'account', 'ranking.index', 20, 'primary'],
            ['account.profile', '프로필', 2, 'account', 'auth.profile', 30, 'primary'],
            ['account.settings', '계정 설정', 2, 'account', 'auth.settings', 40, 'primary'],
            ['account.agreements', '동의 현황', 2, 'account', 'auth.agreements', 50, 'primary'],
        ];
        $entries = [];
        foreach ($items as [$key, $label, $level, $parent, $route, $order, $mobile]) {
            $entries[] = new PublicMenuEntry(
                $key,
                $label,
                $level,
                $parent,
                $route,
                null,
                'all',
                $order,
                $mobile,
                true
            );
        }

        return $entries;
    }

    /**
     * @param list<PublicMenuEntry> $entries
     * @return list<PublicMenuEntry>
     */
    private function validate(array $entries): array
    {
        $roots = [];
        $keys = [];
        $routes = [];
        $externalCount = 0;
        $enabledRootCount = 0;
        foreach ($entries as $entry) {
            if (! $entry instanceof PublicMenuEntry || isset($keys[$entry->key()])) {
                throw new DomainException('사용자 메뉴 키가 중복되었습니다.');
            }
            $keys[$entry->key()] = true;
            if ($entry->level() === 1) {
                $roots[$entry->key()] = true;
                if ($entry->enabled()) {
                    ++$enabledRootCount;
                }
            }
            if ($entry->routeKey() !== null && $entry->routeKey() !== 'first_accessible_child') {
                if (isset($routes[$entry->routeKey()])) {
                    throw new DomainException('같은 내부 페이지를 사용자 메뉴에 두 번 넣을 수 없습니다.');
                }
                $this->route($entry->routeKey());
                $routes[$entry->routeKey()] = true;
            }
            if ($entry->isExternal()) {
                ++$externalCount;
            }
        }
        if (count($roots) > 8 || $externalCount > 20) {
            throw new DomainException('사용자 메뉴 수량 제한을 초과했습니다.');
        }
        if ($enabledRootCount < 1) {
            throw new DomainException('사용자 메뉴에는 활성화된 1차 메뉴가 최소 1개 있어야 합니다.');
        }
        $childCounts = [];
        foreach ($entries as $entry) {
            if ($entry->level() !== 2) {
                continue;
            }
            if (! isset($roots[$entry->parentKey() ?? ''])) {
                throw new DomainException('2차 메뉴의 상위 1차 메뉴가 없습니다.');
            }
            $parent = $entry->parentKey() ?? '';
            $childCounts[$parent] = ($childCounts[$parent] ?? 0) + 1;
            if ($childCounts[$parent] > 12) {
                throw new DomainException('한 1차 메뉴에 2차 메뉴는 최대 12개입니다.');
            }
        }
        usort($entries, static function (
            PublicMenuEntry $left,
            PublicMenuEntry $right
        ): int {
            return [$left->level(), $left->parentKey() ?? '', $left->order(), $left->key()]
                <=> [$right->level(), $right->parentKey() ?? '', $right->order(), $right->key()];
        });

        return $entries;
    }

    private function resolveEntry(
        PublicMenuEntry $entry,
        UserIdentity $user
    ): ?PublicMenuItem {
        $addonCapability = $this->addonMenus->capability($entry->key());
        if (
            $addonCapability !== null
            && ! $this->permissions->can($user->userId(), $addonCapability)
        ) {
            return null;
        }

        if ($entry->isExternal()) {
            return new PublicMenuItem(
                $entry->key(),
                $entry->label(),
                $entry->externalUrl() ?? '',
                [],
                true
            );
        }
        $routeKey = $entry->routeKey();
        if ($routeKey === null || $routeKey === 'first_accessible_child') {
            return null;
        }
        $definition = $this->route($routeKey);
        if ($this->handlers->find($routeKey) === null) {
            return null;
        }
        try {
            if (! $this->features->isEnabled($definition->featureKey())) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        $params = $routeKey === 'auth.profile'
            ? ['user_id' => $user->userId()]
            : [];

        return new PublicMenuItem(
            $entry->key(),
            $entry->label(),
            $this->urls->path($routeKey, $params)
        );
    }

    private function route(string $routeKey): RouteDefinition
    {
        $definition = $this->routes->find($routeKey);
        if (! $definition instanceof RouteDefinition) {
            throw new DomainException('사용자 메뉴의 내부 Route가 등록되지 않았습니다: ' . $routeKey);
        }
        if (
            $definition->area()->value !== 'public'
            || $definition->authentication()->value !== 'authenticated'
            || $definition->responseType() !== 'html'
            || (
                $definition->parameters() !== []
                && $definition->key() !== 'auth.profile'
            )
        ) {
            throw new DomainException(
                '사용자 메뉴에는 로그인 후 바로 열 수 있는 공개 HTML Route만 넣을 수 있습니다.'
            );
        }

        return $definition;
    }

    private function scopeAllows(string $scope, UserIdentity $user): bool
    {
        if ($this->permissions->isSuperAdmin($user->userId())) {
            return true;
        }
        if ($scope === 'all') {
            return true;
        }
        if ($scope === 'system_admin') {
            return false;
        }
        if ($scope === 'learner') {
            $roles = $user->roles();

            return in_array('oj_learner', $roles, true)
                || in_array('subscriber', $roles, true);
        }

        return $this->permissions->can($user->userId(), 'oj_access_console');
    }
}
