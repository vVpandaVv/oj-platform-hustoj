<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
interface NativeLearningCatalog
{
    public function hasProviders(bool $teacher = false): bool;
    public function showsProgramming(HttpRequest $request, bool $teacher = false): bool;
    public function problemFilters(HttpRequest $request, bool $teacher = false): string;
    public function problems(HttpRequest $request, bool $teacher = false): string;
    public function classAssignments(HttpRequest $request, int $classId, bool $teacher): string;
}
