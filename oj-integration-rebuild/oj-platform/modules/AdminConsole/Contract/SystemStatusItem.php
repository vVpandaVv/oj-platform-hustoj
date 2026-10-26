<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

use InvalidArgumentException;

final class SystemStatusItem
{
    /**
     * @param array<string, string> $technicalDetails
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $tone,
        private readonly string $summary,
        private readonly string $nextAction,
        private readonly array $technicalDetails = [],
        private readonly string $capability = 'oj_view_system_status'
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_.-]*$/', $this->key) !== 1
            || trim($this->label) === ''
            || ! in_array($this->tone, ['success', 'info', 'warning', 'danger'], true)
            || trim($this->summary) === ''
            || trim($this->capability) === ''
        ) {
            throw new InvalidArgumentException('시스템 상태 항목이 올바르지 않습니다.');
        }
        foreach ($this->technicalDetails as $detailLabel => $detailValue) {
            if (
                ! is_string($detailLabel)
                || trim($detailLabel) === ''
                || ! is_string($detailValue)
            ) {
                throw new InvalidArgumentException('시스템 상태 기술 정보가 올바르지 않습니다.');
            }
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function tone(): string
    {
        return $this->tone;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function nextAction(): string
    {
        return $this->nextAction;
    }

    /**
     * @return array<string, string>
     */
    public function technicalDetails(): array
    {
        return $this->technicalDetails;
    }

    public function capability(): string
    {
        return $this->capability;
    }
}
