<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaOwnership;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use InvalidArgumentException;

final class SchemaOwnershipMarker implements SchemaOwnership
{
    private const PREFIX = 'oj-platform';

    public function create(string $installationId, SchemaTableDefinition $schema): string
    {
        $this->assertInstallationId($installationId);

        return implode('|', [
            self::PREFIX,
            'installation=' . $installationId,
            'module=' . $schema->owner()->value(),
            'table=' . $schema->key(),
            'version=' . $schema->schemaVersion(),
            'fingerprint=' . $schema->fingerprint(),
        ]);
    }

    public function belongsTo(
        string $comment,
        string $installationId,
        SchemaTableDefinition $schema
    ): bool {
        return hash_equals($this->create($installationId, $schema), $comment);
    }

    public function belongsToInstallation(
        string $comment,
        string $installationId,
        SchemaTableDefinition $schema
    ): bool {
        $this->assertInstallationId($installationId);
        $parts = explode('|', $comment);
        if (array_shift($parts) !== self::PREFIX) {
            return false;
        }

        $values = [];
        foreach ($parts as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === null || $value === null || isset($values[$key])) {
                return false;
            }
            $values[$key] = $value;
        }

        return isset(
            $values['installation'],
            $values['module'],
            $values['table']
        )
            && hash_equals($installationId, $values['installation'])
            && hash_equals($schema->owner()->value(), $values['module'])
            && hash_equals($schema->key(), $values['table']);
    }

    private function assertInstallationId(string $installationId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $installationId) !== 1) {
            throw new InvalidArgumentException('플랫폼 설치 ID 형식이 올바르지 않습니다.');
        }
    }
}
