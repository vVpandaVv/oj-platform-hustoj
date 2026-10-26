<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Domain\CapabilityScope;
use InvalidArgumentException;

final class CapabilityDefinition implements RegistryDefinition
{
    private const RISK_LEVELS = ['normal', 'sensitive', 'destructive'];

    public function __construct(
        private readonly string $capabilityKey,
        private readonly ModuleId $moduleOwner,
        private readonly string $featureKey,
        private readonly CapabilityScope $capabilityScope,
        private readonly string $label,
        private readonly string $description,
        private readonly string $risk = 'normal',
        private readonly bool $consoleEntry = false
    ) {
        if (preg_match('/^oj_[a-z0-9_]+$/', $this->capabilityKey) !== 1) {
            throw new InvalidArgumentException('권한 키 형식이 올바르지 않습니다.');
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->featureKey) !== 1) {
            throw new InvalidArgumentException('권한의 기능 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->label) === '' || trim($this->description) === '') {
            throw new InvalidArgumentException('권한 이름과 설명은 비어 있을 수 없습니다.');
        }

        if (! in_array($this->risk, self::RISK_LEVELS, true)) {
            throw new InvalidArgumentException('권한 위험도 값이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            (string) ($definition['feature_key'] ?? ''),
            CapabilityScope::from((string) ($definition['scope'] ?? '')),
            (string) ($definition['label'] ?? ''),
            (string) ($definition['description'] ?? ''),
            (string) ($definition['risk'] ?? 'normal'),
            (bool) ($definition['console_entry'] ?? false)
        );
    }

    public function key(): string
    {
        return $this->capabilityKey;
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function featureKey(): string
    {
        return $this->featureKey;
    }

    public function scope(): CapabilityScope
    {
        return $this->capabilityScope;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return 'normal'|'sensitive'|'destructive'
     */
    public function risk(): string
    {
        return $this->risk;
    }

    /**
     * True when holding this capability alone makes the user a console-entry
     * candidate (menus/routes may still apply their own additional checks).
     */
    public function consoleEntry(): bool
    {
        return $this->consoleEntry;
    }
}
