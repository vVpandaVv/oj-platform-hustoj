<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemContextAccessProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$registry = new ProblemContextAccessRegistry();
$provider = new class () implements ProblemContextAccessProvider {
    public function scope(): string
    {
        return 'class';
    }

    public function canView(
        int $userId,
        int $problemId,
        ProblemAccessContext $context
    ): bool {
        return $userId === 7
            && $problemId === 1001
            && $context->classId() === 31;
    }
};
$registry->register($provider);

$permissions = new class () implements Authorization {
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        return false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return false;
    }
};
$assignments = new class () implements ProblemAssignmentRepository {
    public function find(ProblemId $problemId): ProblemAssignment
    {
        return new ProblemAssignment($problemId, null, []);
    }

    public function assignOwner(
        ProblemId $problemId,
        int $ownerUserId,
        int $actorUserId
    ): void {
    }

    public function grant(
        ProblemId $problemId,
        int $userId,
        CollaboratorRole $role,
        int $actorUserId
    ): void {
    }

    public function revoke(ProblemId $problemId, int $userId): void
    {
    }

    public function problemIdsForUser(int $userId): array
    {
        return [];
    }
};
$ai = new class () implements ProblemAiAvailability {
    public function isAvailable(string $taskType): bool
    {
        return false;
    }
};
$access = new ProblemAccessPolicy($permissions, $assignments, $ai, $registry);
$problemId = new ProblemId(1001);

$assert(
    $access->canView(7, $problemId, ProblemVisibility::Published),
    '일반 경로의 공개 문제는 기존처럼 열 수 있어야 합니다.'
);
$assert(
    ! $access->canView(7, $problemId, ProblemVisibility::Archived),
    '일반 경로에서 비공개 문제를 열면 안 됩니다.'
);
$assert(
    $access->canView(
        7,
        $problemId,
        ProblemVisibility::Archived,
        ProblemAccessContext::class(31)
    ),
    '수업 Provider가 승인한 배정 문제는 공개 상태와 무관하게 열 수 있어야 합니다.'
);
$assert(
    ! $access->canView(
        8,
        $problemId,
        ProblemVisibility::Published,
        ProblemAccessContext::class(31)
    ),
    '문맥 경로에서는 공개 문제라도 해당 수업 접근 검증을 통과해야 합니다.'
);
$assert(
    ! $access->canView(
        7,
        $problemId,
        ProblemVisibility::Published,
        ProblemAccessContext::contest(9, 0)
    ),
    '등록되지 않은 문맥 Provider를 신뢰하면 안 됩니다.'
);

$duplicateRejected = false;
try {
    $registry->register($provider);
} catch (LogicException) {
    $duplicateRejected = true;
}
$assert($duplicateRejected, '같은 범위의 문제 문맥 Provider를 중복 등록하면 안 됩니다.');

fwrite(STDOUT, "Problem context access tests passed: {$assertions} assertions\n");
