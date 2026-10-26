<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

interface SchemaCompiler
{
    public function compileCreate(
        SchemaTableDefinition $schema,
        string $installationId
    ): string;

    public function compileSynchronize(
        SchemaTableDefinition $schema,
        string $installationId
    ): string;

    public function compileOwnershipUpdate(
        SchemaTableDefinition $schema,
        string $installationId
    ): string;
}
