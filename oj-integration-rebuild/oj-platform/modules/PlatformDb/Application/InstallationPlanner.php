<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;
use RuntimeException;

final class InstallationPlanner
{
    /**
     * @param DefinitionRegistry<TargetTableDefinition> $targets
     * @param DefinitionRegistry<SchemaTableDefinition> $schemas
     */
    public function __construct(
        private readonly DefinitionRegistry $targets,
        private readonly DefinitionRegistry $schemas,
        private readonly PlatformConnection $connection,
        private readonly SchemaOwnershipMarker $ownershipMarker
    ) {
    }

    /** @param list<string>|null $ownerModules */
    public function plan(string $installationId, ?array $ownerModules = null, ?array $tableStates = null): InstallationPlan
    {
        $targetDefinitions = $this->targets->all();
        $tableStates ??= $this->connection->inspectTables(array_keys($targetDefinitions));
        if ($ownerModules !== null) {
            $ownerModules = array_values(array_unique(array_map('strval', $ownerModules)));
            $targetDefinitions = array_filter(
                $targetDefinitions,
                static fn (object $target): bool =>
                    $target instanceof TargetTableDefinition
                    && in_array($target->owner()->value(), $ownerModules, true)
            );
        }
        $steps = [];
        $missingSchemas = [];
        $collisions = [];

        foreach ($targetDefinitions as $target) {
            if (! $target instanceof TargetTableDefinition) {
                continue;
            }

            $schema = $this->schemas->find($target->key());
            $state = $tableStates[$target->key()] ?? null;

            if (! $schema instanceof SchemaTableDefinition) {
                $missingSchemas[] = $target->key();
                if ($state !== null) {
                    $collisions[] = $target->key();
                }
                continue;
            }

            $alreadyInstalled = false;
            if ($state !== null) {
                $storageMatches = $state->engine() === $target->engine()
                    && str_starts_with(
                        $state->collation(),
                        $target->charset() . '_'
                    );
                $owned = $this->ownershipMarker->belongsToInstallation(
                    $state->comment(),
                    $installationId,
                    $schema
                );
                $alreadyInstalled = $storageMatches
                    && $this->ownershipMarker->belongsTo(
                        $state->comment(),
                        $installationId,
                        $schema
                    );

                if (! $storageMatches || ! $owned) {
                    $collisions[] = $target->key();
                }
            }

            $steps[] = new InstallationStep(
                $target->wave(),
                $schema,
                $state !== null,
                $alreadyInstalled
            );
        }

        $steps = $this->orderByDependencies($steps);
        sort($missingSchemas);
        sort($collisions);

        return new InstallationPlan(
            $installationId,
            $steps,
            array_values(array_unique($missingSchemas)),
            array_values(array_unique($collisions))
        );
    }

    /**
     * @param list<InstallationStep> $steps
     * @return list<InstallationStep>
     */
    private function orderByDependencies(array $steps): array
    {
        $remaining = [];
        $dependencies = [];

        foreach ($steps as $step) {
            $key = $step->schema()->key();
            $remaining[$key] = $step;
        }

        foreach ($remaining as $key => $step) {
            $dependencies[$key] = [];
            foreach ($step->schema()->foreignKeys() as $foreignKey) {
                $referencedTable = $foreignKey->referencedTable();
                if ($referencedTable !== $key && isset($remaining[$referencedTable])) {
                    $dependencies[$key][$referencedTable] = true;
                }
            }
        }

        $ordered = [];
        while ($remaining !== []) {
            $ready = array_filter(
                $remaining,
                static fn (InstallationStep $step, string $key): bool =>
                    array_intersect_key($dependencies[$key], $remaining) === [],
                ARRAY_FILTER_USE_BOTH
            );

            if ($ready === []) {
                throw new RuntimeException(sprintf(
                    '플랫폼 테이블 외래 키 의존 관계가 순환합니다: %s',
                    implode(', ', array_keys($remaining))
                ));
            }

            uasort(
                $ready,
                static fn (InstallationStep $left, InstallationStep $right): int =>
                    [$left->wave(), $left->schema()->key()]
                    <=>
                    [$right->wave(), $right->schema()->key()]
            );

            foreach ($ready as $key => $step) {
                $ordered[] = $step;
                unset($remaining[$key]);
                break;
            }
        }

        return $ordered;
    }
}
