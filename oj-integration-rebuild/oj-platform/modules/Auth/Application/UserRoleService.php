<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserRoleAssignmentPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use DomainException;

final class UserRoleService
{
    /**
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly DefinitionRegistry $roles,
        private readonly UserRoleAssignmentPolicy $policy
    ) {
    }

    /**
     * @param list<string> $roleKeys
     */
    public function assign(
        int $actorUserId,
        int $userId,
        array $roleKeys
    ): void
    {
        $target = $this->users->findById($userId);
        if ($target === null) {
            throw new DomainException('역할을 변경할 사용자를 찾지 못했습니다.');
        }
        if (! $this->policy->allows($actorUserId, $target)) {
            throw new DomainException('이 사용자의 역할을 변경할 수 없습니다.');
        }

        $roleKeys = array_values(array_unique(array_map('strval', $roleKeys)));
        foreach ($roleKeys as $roleKey) {
            if (! $this->roles->find($roleKey) instanceof RoleDefinition) {
                throw new DomainException('등록되지 않은 운영 역할은 배정할 수 없습니다.');
            }
        }

        $ownedRoleKeys = array_keys($this->roles->all());
        $preservedRoles = array_values(array_filter(
            $target->roles(),
            static fn (string $role): bool => ! in_array(
                $role,
                $ownedRoleKeys,
                true
            )
        ));
        if ($preservedRoles === []) {
            $preservedRoles = ['subscriber'];
        }

        $this->users->setRoles(
            $userId,
            array_values(array_unique([...$preservedRoles, ...$roleKeys]))
        );
    }
}
