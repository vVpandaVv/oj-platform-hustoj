<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobAccessRule
{
    /**
     * @return list<string>
     */
    public function policyKeys(): array;

    public function policyVersion(): string;

    public function canView(int $userId, JobAccessContext $job): bool;

    public function canControl(int $userId, JobAccessContext $job): bool;
}
