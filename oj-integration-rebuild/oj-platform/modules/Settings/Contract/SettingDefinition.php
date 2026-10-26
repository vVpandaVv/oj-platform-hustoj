<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use InvalidArgumentException;

final class SettingDefinition implements RegistryDefinition
{
    public function __construct(
        private readonly string $settingKey,
        private readonly string $optionName,
        private readonly ModuleId $moduleOwner,
        private readonly bool $sensitive
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->settingKey) !== 1) {
            throw new InvalidArgumentException('설정 키 형식이 올바르지 않습니다.');
        }

        if (preg_match('/^oj_platform_[a-z0-9_]+$/', $this->optionName) !== 1) {
            throw new InvalidArgumentException('WordPress 옵션 이름 형식이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            (string) ($definition['option_name'] ?? ''),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            (bool) ($definition['sensitive'] ?? false)
        );
    }

    public function key(): string
    {
        return $this->settingKey;
    }

    public function optionName(): string
    {
        return $this->optionName;
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }
}
