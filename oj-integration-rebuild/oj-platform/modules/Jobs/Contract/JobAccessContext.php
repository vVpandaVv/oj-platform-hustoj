<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobAccessContext
{
    public function id(): int;

    public function jobType(): string;

    public function ownerModule(): string;

    public function accessPolicyKey(): string;

    public function accessPolicyVersion(): string;

    public function createdByUserId(): int;

    /**
     * @return array<string, mixed>
     */
    public function targetSummary(): array;
}
