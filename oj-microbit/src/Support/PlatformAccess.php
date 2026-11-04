<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use OjMicrobit\Application\Access;
use OjMicrobit\Application\LessonAccess;
use OjMicrobit\Core\Plugin;

final class PlatformAccess implements Access, LessonAccess
{
    public function actorId(): int
    {
        $id = get_current_user_id();
        if ($id < 1 || Plugin::runtime()?->snapshot()->status() !== 'accepted') { throw new OperationError('access_denied', 403, '로그인과 OJ Platform 연결 상태를 확인해 주세요.'); }
        return $id;
    }
    public function isAdmin(): bool { return current_user_can('manage_options'); }
    public function requireTeacher(): void
    {
        $this->actorId();
        if (!$this->isAdmin() && !current_user_can(AddonCapabilityKeyV1::wordpress('oj_microbit_teach'))) { throw new OperationError('access_denied', 403, '마이크로비트 과제를 관리할 권한이 없습니다.'); }
    }
    public function requireLearner(): void
    {
        $this->actorId();
        if (!$this->isAdmin() && !current_user_can(AddonCapabilityKeyV1::wordpress('oj_microbit_learn'))
            && !current_user_can(AddonCapabilityKeyV1::wordpress('oj_microbit_teach'))) { throw new OperationError('access_denied', 403, '마이크로비트 실습을 사용할 권한이 없습니다.'); }
    }
    public function teachingGroups(string $cursor, int $limit): array { return GroupAudienceAccess::current()->teaching($this->actorId(), $cursor, $limit); }
    public function learnerGroupIds(): ?array { return GroupAudienceAccess::current()->learningIds($this->actorId()); }
    public function requireGroupManagement(array $groupIds): void { GroupAudienceAccess::current()->requireManaged($this->actorId(), $groupIds); }
    public function requireGroupParticipation(array $groupIds): void { GroupAudienceAccess::current()->requireParticipant($this->actorId(), $groupIds); }
    public function teachingClasses(string $cursor, int $limit): array { return ClassAudienceAccess::current()->teaching($this->actorId(), $cursor, $limit); }
    public function learnerClassIds(): ?array { return ClassAudienceAccess::current()->learningIds($this->actorId()); }
    public function requireClassManagement(array $classIds): void { ClassAudienceAccess::current()->requireManaged($this->actorId(), $classIds); }
    public function requireClassParticipation(array $classIds): void { ClassAudienceAccess::current()->requireParticipant($this->actorId(), $classIds); }
    public function learningLessons(): ?array { return ClassLessonAccess::current()->learning($this->actorId()); }
    public function lessonTitle(int $classId, bool $teacher): string { return ClassLessonAccess::current()->title($this->actorId(), $classId, $teacher); }
    public function lessonRoster(int $classId, string $cursor, int $limit, ?string $contextRevision = null, ?string $rosterRevision = null): array
    { return ClassLessonAccess::current()->roster($this->actorId(), $classId, $cursor, $limit, $contextRevision, $rosterRevision); }
}
