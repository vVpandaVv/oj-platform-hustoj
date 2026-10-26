<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;

final class InstallationStep
{
    public function __construct(
        private readonly int $wave,
        private readonly SchemaTableDefinition $schema,
        private readonly bool $tableExists,
        private readonly bool $alreadyInstalled
    ) {
    }

    public function wave(): int
    {
        return $this->wave;
    }

    public function schema(): SchemaTableDefinition
    {
        return $this->schema;
    }

    public function isAlreadyInstalled(): bool
    {
        return $this->alreadyInstalled;
    }

    public function requiresCreation(): bool
    {
        return ! $this->tableExists;
    }

    public function requiresUpgrade(): bool
    {
        return $this->tableExists && ! $this->alreadyInstalled;
    }
}
