<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Registry;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use DomainException;

final class JobHandlerRegistry
{
    /**
     * @var array<string, JobHandler>
     */
    private array $handlers = [];

    public function register(JobHandler $handler): void
    {
        $jobType = $handler->jobType();
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $jobType) !== 1
            || isset($this->handlers[$jobType])
        ) {
            throw new DomainException('Jobs Handler 종류가 올바르지 않거나 중복되었습니다.');
        }
        $this->handlers[$jobType] = $handler;
    }

    public function find(string $jobType): ?JobHandler
    {
        return $this->handlers[$jobType] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->handlers);
    }
}
