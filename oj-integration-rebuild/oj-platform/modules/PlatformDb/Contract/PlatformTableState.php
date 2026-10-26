<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;

final class PlatformTableState
{
    private readonly SqlIdentifier $tableName;

    public function __construct(
        string $name,
        private readonly string $comment,
        private readonly string $engine,
        private readonly string $collation
    ) {
        $this->tableName = new SqlIdentifier($name);
    }

    public function name(): string
    {
        return $this->tableName->value();
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function engine(): string
    {
        return $this->engine;
    }

    public function collation(): string
    {
        return $this->collation;
    }
}
