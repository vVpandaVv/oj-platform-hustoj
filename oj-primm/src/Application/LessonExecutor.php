<?php
declare(strict_types=1);
namespace OjPrimm\Application;

interface LessonExecutor
{
    public function availability(): array;
    /** Poll a private OJ execution, or submit it once using the stable key when ticket is null. */
    public function execute(int $actor, string $language, string $source, string $input, string $key, ?string $ticket): array;
}
