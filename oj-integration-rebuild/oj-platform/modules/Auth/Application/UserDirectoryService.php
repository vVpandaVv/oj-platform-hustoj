<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUser;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserDirectory;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSearchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;

final class UserDirectoryService implements UserDirectory
{
    public function __construct(
        private readonly UserProfileRepository $profiles,
        private readonly WordPressUserGateway $users,
        private readonly UserSearchProvider $search
    ) {
    }

    /**
     * @return list<ManagedUser>
     */
    public function page(
        ?AccessStatus $status,
        int $limit = 50,
        int $offset = 0,
        string $keyword = ''
    ): array {
        $userIds = $this->resolveUserIds($keyword);
        if ($userIds === []) {
            return [];
        }
        $profiles = $this->profiles->findFiltered($userIds, $status, $limit, $offset);
        $identities = [];
        foreach ($this->users->findMany(array_map(
            static fn ($profile): int => $profile->userId(),
            $profiles
        )) as $identity) {
            $identities[$identity->userId()] = $identity;
        }

        $managed = [];
        foreach ($profiles as $profile) {
            $identity = $identities[$profile->userId()] ?? null;
            if ($identity !== null) {
                $managed[] = new ManagedUser($identity, $profile);
            }
        }

        return $managed;
    }

    public function count(?AccessStatus $status, string $keyword = ''): int
    {
        $userIds = $this->resolveUserIds($keyword);
        if ($userIds === []) {
            return 0;
        }

        return $this->profiles->countFiltered($userIds, $status);
    }

    public function find(int $userId): ?ManagedUser
    {
        $profile = $this->profiles->find($userId);
        $identity = $this->users->findById($userId);
        if ($profile === null || $identity === null) {
            return null;
        }

        return new ManagedUser($identity, $profile);
    }

    /**
     * @return list<int>|null null이면 id 제한 없음, 빈 배열이면 검색 결과 0건
     */
    private function resolveUserIds(string $keyword): ?array
    {
        $keyword = trim($keyword);

        return $keyword === '' ? null : $this->search->searchUserIds($keyword);
    }
}
