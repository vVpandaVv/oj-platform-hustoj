<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaOwnership;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;

final class MigrationSchemaManager
{
    /** @param DefinitionRegistry<SchemaTableDefinition> $schemas */
    public function __construct(
        private readonly DefinitionRegistry $schemas,
        private readonly SchemaCompiler $compiler,
        private readonly SchemaOwnership $ownership,
        private readonly SettingsManager $settings,
        private readonly WordPressMigrationDatabase $database,
        private readonly Clock $clock
    ) {
    }

    public function schema(MigrationTable $table): SchemaTableDefinition
    {
        $schema = $this->schemas->find($table->targetTable());
        if (! $schema instanceof SchemaTableDefinition) {
            throw new DomainException(
                '목표 스키마가 등록되지 않았습니다: ' . $table->targetTable()
            );
        }

        return $schema;
    }

    public function physicalTarget(
        MigrationTable $table,
        MigrationPlan $plan,
        ?string $source
    ): string
    {
        if ($table->hasSameNameCollision()
            && $source === $table->targetTable()
            && $plan->stage()->value < MigrationStage::Cutover->value) {
            return $this->stagingName($table, $plan);
        }

        return $table->targetTable();
    }

    public function prepare(
        MigrationTable $table,
        MigrationPlan $plan,
        ?string $source
    ): string
    {
        $schema = $this->schema($table);
        $physical = $this->physicalTarget($table, $plan, $source);
        $physicalSchema = $physical === $schema->key()
            ? $schema
            : $this->cloneFor($schema, $physical);
        $state = $this->database->inspect($physical);
        if (! $state['exists']) {
            $this->database->execute($this->compiler->compileCreate(
                $physicalSchema,
                $this->installationId()
            ));
            $state = $this->database->inspect($physical);
        }
        if (! $state['exists']
            || ! $this->ownership->belongsTo(
                $state['comment'],
                $this->installationId(),
                $physicalSchema
            )) {
            throw new DomainException(
                '목표 테이블 소유권 또는 스키마가 현재 설치 계획과 다릅니다: ' . $physical
            );
        }

        return $physical;
    }

    public function isCurrentTarget(MigrationTable $table): bool
    {
        $schema = $this->schema($table);
        $state = $this->database->inspect($schema->key());

        return $state['exists'] && $this->ownership->belongsTo(
            $state['comment'],
            $this->installationId(),
            $schema
        );
    }

    public function assertReady(
        MigrationTable $table,
        MigrationPlan $plan,
        ?string $source
    ): string
    {
        $physical = $this->physicalTarget($table, $plan, $source);
        $schema = $this->schema($table);
        $physicalSchema = $physical === $schema->key()
            ? $schema
            : $this->cloneFor($schema, $physical);
        $state = $this->database->inspect($physical);
        if (! $state['exists']
            || ! $this->ownership->belongsTo(
                $state['comment'],
                $this->installationId(),
                $physicalSchema
            )) {
            throw new DomainException('목표 테이블 검증에 실패했습니다: ' . $physical);
        }

        return $physical;
    }

    public function cutover(
        MigrationTable $table,
        MigrationPlan $plan,
        ?string $source
    ): void {
        $schema = $this->schema($table);
        if ($table->hasSameNameCollision() && $source === $table->targetTable()) {
            $this->cutoverCollision($table, $plan, $schema, $source);
        } else {
            $this->assertReady($table, $plan, $source);
        }
        $this->activate($schema, $plan);
    }

    public function legacyAlias(MigrationTable $table, MigrationPlan $plan): string
    {
        return 'legacy_' . $table->targetTable() . '_' . $this->waveSuffix($table, $plan);
    }

    public function stagingName(MigrationTable $table, MigrationPlan $plan): string
    {
        return 'migration_' . $table->targetTable() . '_' . $this->waveSuffix($table, $plan);
    }

    private function cutoverCollision(
        MigrationTable $table,
        MigrationPlan $plan,
        SchemaTableDefinition $schema,
        ?string $source
    ): void {
        $target = $table->targetTable();
        $staging = $this->stagingName($table, $plan);
        $alias = $this->legacyAlias($table, $plan);
        $targetState = $this->database->inspect($target);
        $aliasState = $this->database->inspect($alias);
        if ($targetState['exists']
            && $aliasState['exists']
            && $this->ownership->belongsTo(
                $targetState['comment'],
                $this->installationId(),
                $schema
            )) {
            return;
        }
        if ($source !== $target || ! $targetState['exists'] || $aliasState['exists']) {
            throw new DomainException(
                '동명 테이블 원자 전환의 현재 원본 상태가 계획과 다릅니다.'
            );
        }
        $stagingSchema = $this->cloneFor($schema, $staging);
        $stagingState = $this->database->inspect($staging);
        if (! $stagingState['exists']
            || ! $this->ownership->belongsTo(
                $stagingState['comment'],
                $this->installationId(),
                $stagingSchema
            )) {
            throw new DomainException('검증된 동명 목표 테이블을 찾을 수 없습니다.');
        }

        $this->database->renamePair($target, $alias, $staging, $target);
        $this->database->setComment(
            $target,
            $this->ownership->create($this->installationId(), $schema)
        );
    }

    private function activate(
        SchemaTableDefinition $schema,
        MigrationPlan $plan
    ): void {
        $snapshot = $this->settings->read('schema_map', [
            'contract_version' => '1',
            'modules' => [],
            'events' => [],
        ]);
        $value = is_array($snapshot->value()) ? $snapshot->value() : [];
        $modules = is_array($value['modules'] ?? null) ? $value['modules'] : [];
        $events = is_array($value['events'] ?? null) ? $value['events'] : [];
        $moduleKey = $schema->owner()->value();
        $current = is_array($modules[$moduleKey] ?? null)
            ? $modules[$moduleKey]
            : [];
        $tables = is_array($current['tables'] ?? null) ? $current['tables'] : [];
        if (($tables[$schema->key()] ?? null) === $schema->fingerprint()
            && ($current['migration_plan_hash'] ?? null) === $plan->hash()) {
            return;
        }
        $tables[$schema->key()] = $schema->fingerprint();
        ksort($tables, SORT_STRING);
        $modules[$moduleKey] = [
            'installed_version' => $schema->schemaVersion(),
            'active_version' => $schema->schemaVersion(),
            'migration_plan_hash' => $plan->hash(),
            'wave' => $plan->wave()->value,
            'tables' => $tables,
            'activated_at' => $this->clock->now()->format(DATE_ATOM),
        ];
        ksort($modules, SORT_STRING);
        $events[] = [
            'module' => $moduleKey,
            'table' => $schema->key(),
            'plan_hash' => $plan->hash(),
            'wave' => $plan->wave()->value,
            'activated_at' => $this->clock->now()->format(DATE_ATOM),
        ];
        $events = array_slice($events, -100);
        $this->settings->save('schema_map', [
            'contract_version' => '1',
            'modules' => $modules,
            'events' => $events,
        ], $snapshot->revision());
    }

    private function cloneFor(
        SchemaTableDefinition $schema,
        string $name
    ): SchemaTableDefinition {
        return new SchemaTableDefinition(
            $name,
            $schema->owner(),
            $schema->schemaVersion(),
            $schema->columns(),
            $schema->indexes(),
            []
        );
    }

    private function waveSuffix(MigrationTable $table, MigrationPlan $plan): string
    {
        return substr(hash('sha256', implode(':', [
            $plan->databaseFingerprint(),
            $plan->wave()->value,
            $table->targetTable(),
        ])), 0, 12);
    }

    private function installationId(): string
    {
        $value = $this->settings->read('installation_id')->value();
        if (! is_string($value) || preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new DomainException('플랫폼 설치 ID를 확인할 수 없습니다.');
        }

        return $value;
    }
}
