<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;
use RuntimeException;

final class SettingsService implements SettingsManager
{
    /**
     * @param DefinitionRegistry<SettingDefinition> $settings
     */
    public function __construct(
        private readonly DefinitionRegistry $settings,
        private readonly SettingStore $store
    ) {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        $definition = $this->definition($key);
        $stored = $this->store->read($definition->optionName(), null);

        if (! is_array($stored) || ! isset($stored['revision']) || ! array_key_exists('value', $stored)) {
            if ($definition->isSensitive() && $stored !== null) {
                throw new RuntimeException('민감 설정에 유효한 비밀값 참조가 없습니다.');
            }

            return new SettingsSnapshot(0, $stored ?? $default);
        }

        $value = $stored['value'];
        if ($definition->isSensitive() && $value !== null) {
            $value = new SecretReference((string) $value);
        }

        return new SettingsSnapshot(max(0, (int) $stored['revision']), $value);
    }

    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
    {
        if ($expectedRevision < 0) {
            throw new RuntimeException('설정 revision은 0 이상이어야 합니다.');
        }

        $definition = $this->definition($key);
        if ($definition->isSensitive() && ! $value instanceof SecretReference) {
            throw new RuntimeException(
                '민감 설정은 SecretVault가 발급한 비밀값 참조로만 저장할 수 있습니다.'
            );
        }

        $current = $this->read($key);
        if ($current->revision() !== $expectedRevision) {
            throw new RuntimeException('설정이 다른 요청에서 변경되었습니다. 현재 값을 다시 확인해 주세요.');
        }

        $next = new SettingsSnapshot($expectedRevision + 1, $value);
        $storedValue = $value instanceof SecretReference ? $value->value() : $value;
        if (! $this->store->write($definition->optionName(), [
            'revision' => $next->revision(),
            'value' => $storedValue,
        ])) {
            throw new RuntimeException('설정을 저장하지 못했습니다.');
        }

        return $next;
    }

    private function definition(string $key): SettingDefinition
    {
        $definition = $this->settings->find($key);
        if (! $definition instanceof SettingDefinition) {
            throw new RuntimeException(sprintf('등록되지 않은 설정입니다: %s', $key));
        }

        return $definition;
    }
}
