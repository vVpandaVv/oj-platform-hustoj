<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use InvalidArgumentException;

final class PermissionPresetDefinition implements RegistryDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $presetCapabilities;

    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        private readonly string $presetKey,
        private readonly string $label,
        array $capabilities
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->presetKey) !== 1) {
            throw new InvalidArgumentException('권한 묶음 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('권한 묶음 이름은 비어 있을 수 없습니다.');
        }

        $normalized = array_values(array_unique(array_map('strval', $capabilities)));
        if ($normalized === [] || count($normalized) !== count($capabilities)) {
            throw new InvalidArgumentException('권한 묶음은 비어 있거나 중복될 수 없습니다.');
        }

        $this->presetCapabilities = $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            (string) ($definition['label'] ?? ''),
            array_values((array) ($definition['capabilities'] ?? []))
        );
    }

    public function key(): string
    {
        return $this->presetKey;
    }

    public function owner(): ModuleId
    {
        return new ModuleId('permission');
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->presetCapabilities;
    }
}
