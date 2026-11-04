<?php
declare(strict_types=1);
namespace OjMicrobit\Application;
interface Access
{
    public function actorId(): int;
    public function requireTeacher(): void;
    public function requireLearner(): void;
    public function isAdmin(): bool;
    public function teachingGroups(string $cursor, int $limit): array;
    /** @return list<int>|null Complete active group (school-class) scope, capped at 100. */
    public function learnerGroupIds(): ?array;
    public function requireGroupManagement(array $groupIds): void;
    public function requireGroupParticipation(array $groupIds): void;
    public function teachingClasses(string $cursor, int $limit): array;
    /** @return list<int>|null Legacy lesson scope; null means the class provider cannot prove a complete current scope. */
    public function learnerClassIds(): ?array;
    public function requireClassManagement(array $classIds): void;
    public function requireClassParticipation(array $classIds): void;
}
