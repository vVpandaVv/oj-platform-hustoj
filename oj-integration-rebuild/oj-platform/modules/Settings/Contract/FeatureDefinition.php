<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use InvalidArgumentException;

final class FeatureDefinition implements RegistryDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $featureDependencies;

    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        private readonly string $featureKey,
        private readonly string $label,
        private readonly bool $required,
        private readonly bool $defaultEnabled,
        array $dependencies
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->featureKey) !== 1) {
            throw new InvalidArgumentException('기능 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('기능 이름은 비어 있을 수 없습니다.');
        }

        $normalized = array_values(array_unique(array_map('strval', $dependencies)));
        if (count($normalized) !== count($dependencies) || in_array($this->featureKey, $normalized, true)) {
            throw new InvalidArgumentException('기능 의존 관계가 중복되거나 자기 자신을 가리킵니다.');
        }

        if ($this->required && ! $this->defaultEnabled) {
            throw new InvalidArgumentException('필수 기능은 기본으로 켜져 있어야 합니다.');
        }

        $this->featureDependencies = $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            (string) ($definition['label'] ?? ''),
            (bool) ($definition['required'] ?? false),
            (bool) ($definition['default_enabled'] ?? false),
            array_values((array) ($definition['dependencies'] ?? []))
        );
    }

    public function key(): string
    {
        return $this->featureKey;
    }

    public function owner(): ModuleId
    {
        return new ModuleId('settings');
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function defaultEnabled(): bool
    {
        return $this->defaultEnabled;
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return $this->featureDependencies;
    }
}
