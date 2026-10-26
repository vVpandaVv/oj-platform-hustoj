<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use DomainException;
use RuntimeException;

final class FeatureService implements FeatureFlags
{
    /**
     * @param DefinitionRegistry<FeatureDefinition> $features
     */
    public function __construct(
        private readonly DefinitionRegistry $features,
        private readonly SettingsManager $settings,
        private readonly ?FeatureDisableGuardRegistry $disableGuards = null
    ) {
    }

    public function isEnabled(string $featureKey): bool
    {
        return $this->resolve($featureKey, []);
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('feature_flags', []);
    }

    /**
     * @return list<FeatureDefinition>
     */
    public function definitions(): array
    {
        return array_values($this->features->all());
    }

    /**
     * @param array<string, bool> $requestedFlags
     */
    public function save(
        array $requestedFlags,
        int $expectedRevision
    ): SettingsSnapshot {
        $definitions = [];
        foreach ($this->definitions() as $definition) {
            $definitions[$definition->key()] = $definition;
        }
        foreach ($requestedFlags as $featureKey => $enabled) {
            if (
                ! is_string($featureKey)
                || ! is_bool($enabled)
                || ! isset($definitions[$featureKey])
            ) {
                throw new DomainException('등록되지 않은 기능 설정이 포함되어 있습니다.');
            }
        }
        $flags = [];
        foreach ($definitions as $featureKey => $definition) {
            $flags[$featureKey] = $definition->isRequired()
                ? true
                : ($requestedFlags[$featureKey] ?? false);
        }
        foreach ($definitions as $featureKey => $definition) {
            if (! $flags[$featureKey]) {
                continue;
            }
            foreach ($definition->dependencies() as $dependency) {
                if (! ($flags[$dependency] ?? false)) {
                    $dependencyLabel = isset($definitions[$dependency])
                        ? $definitions[$dependency]->label()
                        : $dependency;
                    throw new DomainException(sprintf(
                        '%s 기능을 사용하려면 %s 기능을 먼저 켜야 합니다.',
                        $definition->label(),
                        $dependencyLabel
                    ));
                }
            }
        }
        ksort($flags);
        if ($this->disableGuards !== null) {
            foreach ($flags as $featureKey => $enabled) {
                if ($enabled || ! $this->isEnabled($featureKey)) {
                    continue;
                }
                $blockers = $this->disableGuards->blockers($featureKey);
                if ($blockers === []) {
                    continue;
                }
                $visible = array_slice($blockers, 0, 10);
                if (count($blockers) > count($visible)) {
                    $visible[] = sprintf('외 %d개', count($blockers) - count($visible));
                }
                throw new DomainException(sprintf(
                    '%s 기능을 끌 수 없습니다. 먼저 연결을 공개 또는 비공개로 변경해 주세요: %s',
                    $definitions[$featureKey]->label(),
                    implode(', ', $visible)
                ));
            }
        }

        return $this->settings->save(
            'feature_flags',
            $flags,
            $expectedRevision
        );
    }

    /**
     * @param array<string, true> $stack
     */
    private function resolve(string $featureKey, array $stack): bool
    {
        $definition = $this->features->find($featureKey);
        if (! $definition instanceof FeatureDefinition) {
            throw new RuntimeException(sprintf('등록되지 않은 기능입니다: %s', $featureKey));
        }

        if (isset($stack[$featureKey])) {
            throw new RuntimeException(sprintf('기능 의존 관계가 순환합니다: %s', $featureKey));
        }

        $flags = $this->snapshot()->value();
        $enabled = is_array($flags) && array_key_exists($featureKey, $flags)
            ? (bool) $flags[$featureKey]
            : $definition->defaultEnabled();

        if ($definition->isRequired()) {
            $enabled = true;
        }

        if (! $enabled) {
            return false;
        }

        $stack[$featureKey] = true;
        foreach ($definition->dependencies() as $dependency) {
            if (! $this->resolve($dependency, $stack)) {
                return false;
            }
        }

        return true;
    }
}
