<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationState;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use DomainException;
use Throwable;

final class MigrationStateService
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('migration', MigrationState::initial()->toArray());
    }

    public function current(): MigrationState
    {
        $value = $this->snapshot()->value();

        return MigrationState::fromArray(is_array($value) ? $value : []);
    }

    public function save(MigrationState $state, int $expectedRevision): MigrationState
    {
        $snapshot = $this->settings->save(
            'migration',
            $state->toArray(),
            $expectedRevision
        );
        $value = $snapshot->value();

        return MigrationState::fromArray(is_array($value) ? $value : []);
    }

    /**
     * @param callable(MigrationState): MigrationState $transition
     */
    public function update(callable $transition, ?int $expectedRevision = null): MigrationState
    {
        $attempts = $expectedRevision === null ? 3 : 1;
        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $snapshot = $this->snapshot();
            if ($expectedRevision !== null && $snapshot->revision() !== $expectedRevision) {
                throw new DomainException('다른 관리자가 이전 설정을 변경했습니다. 화면을 새로 고쳐 주세요.');
            }
            $value = $snapshot->value();
            $current = MigrationState::fromArray(is_array($value) ? $value : []);
            $next = $transition($current);
            if (! $next instanceof MigrationState) {
                throw new DomainException('이전 상태 변경 결과가 올바르지 않습니다.');
            }

            try {
                return $this->save($next, $snapshot->revision());
            } catch (Throwable $error) {
                $lastError = $error;
            }
        }

        throw new DomainException(
            '이전 상태를 저장하는 동안 충돌이 발생했습니다. 다시 시도해 주세요.',
            0,
            $lastError
        );
    }
}

