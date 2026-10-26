<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

interface SchemaOwnership
{
    public function create(
        string $installationId,
        SchemaTableDefinition $schema
    ): string;

    public function belongsTo(
        string $comment,
        string $installationId,
        SchemaTableDefinition $schema
    ): bool;
}
