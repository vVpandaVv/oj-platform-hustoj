<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use DomainException;
use Throwable;

final class GroupMemberNamesService
{
    public const MAX_CHANGES = 100;

    public function __construct(
        private readonly GroupService $groups,
        private readonly WordPressUserGateway $users,
        private readonly ManagedUserNameUpdater $names,
        private readonly Authorization $permissions,
        private readonly ErrorReporter $errors
    ) {
    }

    /** @param list<UserIdentity> $members @return list<int> */
    public function editableUserIds(LearningGroup $group, array $members): array
    {
        if ($group->isDeleted()) {
            return [];
        }
        $active = array_flip($this->groups->memberUserIds($group->id()));
        $managers = array_flip($this->groups->managerUserIds($group->id()));
        $editable = [];
        foreach ($members as $member) {
            $id = $member->userId();
            if ($id !== $group->ownerUserId()
                && isset($active[$id]) && ! isset($managers[$id])
                && ! in_array('administrator', $member->roles(), true)
                && ! $this->permissions->isSuperAdmin($id)
                && ! $this->permissions->canAny($id, ['oj_edit_users', 'oj_manage_roles', 'oj_manage_groups'])
            ) {
                $editable[] = $id;
            }
        }
        return $editable;
    }

    /**
     * @param list<array{user_id:int, name:string, expected_name:string}> $changes
     * @return list<array{user_id:int, saved:bool, name?:string, error?:string}>
     */
    public function save(int $actorId, int $groupId, array $changes): array
    {
        $group = $this->groups->findManaged($groupId, $actorId);
        if ($group->isDeleted() || ! array_is_list($changes) || count($changes) < 1 || count($changes) > self::MAX_CHANGES) {
            throw new DomainException('활성 그룹에서 한 번에 1~100명의 관리용 이름을 수정할 수 있습니다.');
        }

        // Reject a forged or out-of-scope batch before changing any member.
        $seen = [];
        foreach ($changes as $change) {
            if (! is_array($change)
                || ! is_int($change['user_id'] ?? null)
                || ! is_string($change['name'] ?? null)
                || ! is_string($change['expected_name'] ?? null)
                || isset($seen[$change['user_id']])
            ) {
                throw new DomainException('이름 변경 목록이 올바르지 않습니다.');
            }
            $seen[$change['user_id']] = true;
        }
        $members = $this->users->findMany(array_keys($seen));
        if (count($this->editableUserIds($group, $members)) !== count($changes)) {
            throw new DomainException('이 그룹에서 이름을 수정할 수 없는 사용자가 포함되어 있습니다.');
        }

        $results = [];
        foreach ($changes as $change) {
            try {
                $name = $this->names->update($change['user_id'], $change['name'], $change['expected_name']);
                $results[] = ['user_id' => $change['user_id'], 'saved' => true, 'name' => $name];
            } catch (DomainException $error) {
                $results[] = ['user_id' => $change['user_id'], 'saved' => false, 'error' => $error->getMessage()];
            } catch (Throwable $error) {
                $this->errors->report($error);
                $results[] = ['user_id' => $change['user_id'], 'saved' => false, 'error' => '저장하지 못했습니다. 입력을 유지했으니 다시 시도해 주세요.'];
            }
        }

        return $results;
    }
}
