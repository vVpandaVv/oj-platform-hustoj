<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Migration\Application\LegacyRowTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWorkResult;
use DomainException;
use JsonException;

final class MigrationDataCopier
{
    private const BATCH_SIZE = 250;

    public function __construct(
        private readonly WordPressMigrationDatabase $database,
        private readonly LegacySourceLocator $sources,
        private readonly MigrationSchemaManager $schemas,
        private readonly LegacyRowTransformer $transformer
    ) {
    }

    public function copy(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        $source = $this->requiredSource($table);
        $target = $this->schemas->assertReady($table, $plan, $source);
        if ($source === null) {
            return new MigrationWorkResult(
                'migration.table_ready',
                '별도 원본이 없는 목표 테이블을 확인했습니다.',
                ['target_rows' => $this->database->count($target)],
                ['missing_rows' => 0]
            );
        }

        $schema = $this->schemas->schema($table);
        $columns = $this->sourceColumns($table, $source);
        $sourceRows = 0;
        $writtenRows = 0;
        $batchCount = 0;
        $hash = hash_init('sha256');
        for ($offset = 0; ; $offset += self::BATCH_SIZE) {
            $rows = $this->database->batch(
                $source,
                $columns,
                $table->sourceKey(),
                $offset,
                self::BATCH_SIZE
            );
            if ($rows === []) {
                break;
            }
            ++$batchCount;
            $sourceRows += count($rows);
            foreach ($rows as $row) {
                $transformedRows = $this->transformer->transform($table, $row, $plan);
                foreach ($transformedRows as $transformed) {
                    $this->database->upsert($target, $schema, $transformed);
                    hash_update($hash, $this->canonical($transformed));
                    ++$writtenRows;
                }
            }
            if (count($rows) < self::BATCH_SIZE) {
                break;
            }
        }

        return new MigrationWorkResult(
            'migration.table_copy_complete',
            '테이블 데이터 복사를 완료했습니다.',
            [
                'source_rows' => $sourceRows,
                'written_rows' => $writtenRows,
                'batch_count' => $batchCount,
                'transformed_hash' => hash_final($hash),
            ],
            [
                'target_rows' => $this->database->count($target),
                'missing_rows' => 0,
            ]
        );
    }

    public function validate(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        $source = $this->requiredSource($table);
        $target = $this->schemas->assertReady($table, $plan, $source);
        if ($source === null) {
            return new MigrationWorkResult(
                'migration.table_validation_passed',
                '별도 원본이 없는 목표 테이블 검증을 통과했습니다.',
                ['target_rows' => $this->database->count($target)],
                ['missing_rows' => 0]
            );
        }

        $schema = $this->schemas->schema($table);
        $columns = $this->sourceColumns($table, $source);
        $sourceRows = 0;
        $expectedRows = 0;
        $missingRows = 0;
        $hash = hash_init('sha256');
        for ($offset = 0; ; $offset += self::BATCH_SIZE) {
            $rows = $this->database->batch(
                $source,
                $columns,
                $table->sourceKey(),
                $offset,
                self::BATCH_SIZE
            );
            if ($rows === []) {
                break;
            }
            $sourceRows += count($rows);
            foreach ($rows as $row) {
                foreach ($this->transformer->transform($table, $row, $plan) as $transformed) {
                    ++$expectedRows;
                    hash_update($hash, $this->canonical($transformed));
                    if (! $this->database->contains($target, $schema, $transformed)) {
                        ++$missingRows;
                    }
                }
            }
            if (count($rows) < self::BATCH_SIZE) {
                break;
            }
        }
        $needsAttention = $missingRows > 0;

        return new MigrationWorkResult(
            $needsAttention
                ? 'migration.table_validation_failed'
                : 'migration.table_validation_passed',
            $needsAttention
                ? '목표 테이블에서 이전 대상 행 일부를 찾지 못했습니다.'
                : '테이블 데이터 검증을 통과했습니다.',
            [
                'source_rows' => $sourceRows,
                'expected_rows' => $expectedRows,
                'transformed_hash' => hash_final($hash),
            ],
            [
                'target_rows' => $this->database->count($target),
                'missing_rows' => $missingRows,
            ],
            $needsAttention
        );
    }

    private function requiredSource(MigrationTable $table): ?string
    {
        if (! $table->hasLegacySource()) {
            return null;
        }
        $sources = $this->sources->existing($table);
        if ($sources === []) {
            return null;
        }
        if (count($sources) !== 1) {
            throw new DomainException(
                '같은 논리 이름의 이전 원본 테이블이 둘 이상입니다.'
            );
        }

        return $sources[0];
    }

    /** @return list<string> */
    private function sourceColumns(MigrationTable $table, string $source): array
    {
        $columns = $this->database->columns($source);
        if (! in_array($table->sourceKey(), $columns, true)) {
            throw new DomainException('이전 원본의 정렬 키 열을 찾을 수 없습니다.');
        }
        if ($table->legacyLogicalName() === 'users') {
            $columns = array_values(array_diff($columns, [
                'password',
                'pass',
                'email',
                'user_email',
                'user_pass',
            ]));
        }

        return $columns;
    }

    /** @param array<string, mixed> $row */
    private function canonical(array $row): string
    {
        ksort($row, SORT_STRING);
        try {
            return json_encode(
                $row,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new DomainException('이전 행 검증 해시를 만들지 못했습니다.', 0, $error);
        }
    }
}
