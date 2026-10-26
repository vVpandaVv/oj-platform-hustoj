<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

interface CurriculumLessonGenerator extends LessonGenerator
{
    public function generateForProfile(int $actor, array $publicProblem, string $language, string $kind, array $profile, string $key): array;
    public function repairForProfile(int $actor, array $publicProblem, array $lesson, array $report, array $profile, string $key): array;
    public function regenerate(int $actor, array $publicProblem, array $lesson, string $scope, array $profile, string $key): array;
}
