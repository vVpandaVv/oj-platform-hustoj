<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

/** Private system runs, never formal learner submissions. */
interface CodeExecutionEngine
{
    public function isAvailable(): bool;
    public function submit(string $identity, int $languageId, string $source, string $input): int;
    /** @return array{result:int,output:string,compile_error:string,age_seconds:int}|null */
    public function poll(int $id, string $identity): ?array;
    public function erase(string $identityPrefix): void;
    public function hasRecords(string $identityPrefix): bool;
}
