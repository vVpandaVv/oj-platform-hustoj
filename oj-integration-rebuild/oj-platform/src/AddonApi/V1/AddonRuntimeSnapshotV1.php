<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonRuntimeSnapshotV1
{
    /** @param array<string, int> $contributionCounts */
    public function __construct(
        private readonly string $addonId,
        private readonly string $addonVersion,
        private readonly string $requiredPlatform,
        private readonly string $actualPlatform,
        private readonly string $requiredAddonApi,
        private readonly string $actualAddonApi,
        private readonly string $status,
        private readonly string $code,
        private readonly string $catalogRevision,
        private readonly array $contributionCounts,
        private readonly string $correlationId
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $this->addonId) !== 1) {
            throw new InvalidArgumentException('Addon API 애드온 ID가 올바르지 않습니다.');
        }
        foreach ([
            'addonVersion' => $this->addonVersion,
            'requiredPlatform' => $this->requiredPlatform,
            'actualPlatform' => $this->actualPlatform,
            'requiredAddonApi' => $this->requiredAddonApi,
            'actualAddonApi' => $this->actualAddonApi,
            'catalogRevision' => $this->catalogRevision,
        ] as $field => $value) {
            ValueValidator::text($value, $field, 160, true, false);
        }
        ValueValidator::oneOf(
            $this->status,
            ['accepted', 'incompatible', 'rejected', 'disabled', 'unregistered'],
            'status'
        );
        if ($this->status === 'accepted' && $this->code !== '') {
            throw new InvalidArgumentException('승인된 애드온에는 오류 코드가 없어야 합니다.');
        }
        if ($this->status !== 'accepted') {
            ValueValidator::errorCode($this->code);
        }
        ValueValidator::contributionCountMap($this->contributionCounts, 'contributionCounts');
        ValueValidator::correlationId($this->correlationId);
    }

    public function addonId(): string { return $this->addonId; }
    public function addonVersion(): string { return $this->addonVersion; }
    public function requiredPlatform(): string { return $this->requiredPlatform; }
    public function actualPlatform(): string { return $this->actualPlatform; }
    public function requiredAddonApi(): string { return $this->requiredAddonApi; }
    public function actualAddonApi(): string { return $this->actualAddonApi; }
    public function status(): string { return $this->status; }
    public function code(): string { return $this->code; }
    public function catalogRevision(): string { return $this->catalogRevision; }
    /** @return array<string, int> */
    public function contributionCounts(): array { return $this->contributionCounts; }
    public function correlationId(): string { return $this->correlationId; }
}
