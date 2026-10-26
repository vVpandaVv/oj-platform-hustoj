<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

use InvalidArgumentException;
use JsonException;

final class MigrationPlan
{
    /**
     * @param list<MigrationTable> $tables
     * @param array<string, scalar|null> $evidence
     * @param array<string, int> $ownerAssignments
     */
    public function __construct(
        private readonly MigrationWave $wave,
        private readonly MigrationStage $stage,
        private readonly string $databaseFingerprint,
        private readonly array $tables,
        private readonly array $evidence,
        private readonly array $ownerAssignments
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/', $this->databaseFingerprint) !== 1
            || $this->tables === []
        ) {
            throw new InvalidArgumentException('이전 계획 입력이 올바르지 않습니다.');
        }
        foreach ($this->tables as $table) {
            if (! $table instanceof MigrationTable || $table->wave() !== $this->wave) {
                throw new InvalidArgumentException('이전 계획의 테이블 파동이 일치하지 않습니다.');
            }
        }
        foreach ($this->ownerAssignments as $key => $userId) {
            if (preg_match('/^(problem|class|contest|group):[1-9][0-9]*$/', $key) !== 1 || $userId < 1) {
                throw new InvalidArgumentException('소유자 승인 값이 올바르지 않습니다.');
            }
        }
    }

    public function wave(): MigrationWave
    {
        return $this->wave;
    }

    public function stage(): MigrationStage
    {
        return $this->stage;
    }

    public function databaseFingerprint(): string
    {
        return $this->databaseFingerprint;
    }

    /** @return list<MigrationTable> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return array<string, scalar|null> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** @return array<string, int> */
    public function ownerAssignments(): array
    {
        return $this->ownerAssignments;
    }
    /**
     * @param array<string, mixed> $value
     * @param callable(string): MigrationTable $tableResolver
     */
    public static function fromArray(array $value, callable $tableResolver): self
    {
        $wave = MigrationWave::fromKey((string) ($value['wave'] ?? ''));
        $stage = MigrationStage::tryFrom((int) ($value['stage'] ?? -1));
        $tableValues = $value['tables'] ?? null;
        if ($stage === null || ! is_array($tableValues)) {
            throw new InvalidArgumentException('이전 계획 형식이 올바르지 않습니다.');
        }

        $tables = [];
        foreach ($tableValues as $tableValue) {
            if (! is_array($tableValue) || ! is_string($tableValue['key'] ?? null)) {
                throw new InvalidArgumentException('이전 계획의 테이블 항목이 올바르지 않습니다.');
            }
            $table = $tableResolver($tableValue['key']);
            if (! $table instanceof MigrationTable || $table->canonical() !== $tableValue) {
                throw new InvalidArgumentException('현재 이전 계약과 계획의 테이블 정의가 다릅니다.');
            }
            $tables[] = $table;
        }

        $evidence = [];
        foreach ((array) ($value['evidence'] ?? []) as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $evidence[$key] = $item;
            }
        }
        $owners = [];
        foreach ((array) ($value['owner_assignments'] ?? []) as $key => $item) {
            $userId = is_int($item) || (is_string($item) && ctype_digit($item))
                ? (int) $item
                : 0;
            if (is_string($key) && $userId > 0) {
                $owners[$key] = $userId;
            }
        }

        return new self(
            $wave,
            $stage,
            (string) ($value['database_fingerprint'] ?? ''),
            $tables,
            $evidence,
            $owners
        );
    }


    public function hash(): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonical(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $error) {
            throw new InvalidArgumentException('이전 계획을 직렬화할 수 없습니다.', 0, $error);
        }
    }

    public function freezeKey(): string
    {
        return hash(
            'sha256',
            'migration-freeze:v1:' . $this->databaseFingerprint . ':' . $this->wave->value
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        $tables = array_map(
            static fn (MigrationTable $table): array => $table->canonical(),
            $this->tables
        );
        $evidence = $this->evidence;
        $owners = $this->ownerAssignments;
        ksort($evidence, SORT_STRING);
        ksort($owners, SORT_STRING);

        return [
            'contract_version' => '1',
            'wave' => $this->wave->value,
            'stage' => $this->stage->value,
            'database_fingerprint' => $this->databaseFingerprint,
            'tables' => $tables,
            'evidence' => $evidence,
            'owner_assignments' => $owners,
        ];
    }
}
