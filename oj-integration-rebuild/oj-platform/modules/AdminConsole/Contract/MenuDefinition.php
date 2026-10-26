<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use InvalidArgumentException;

final class MenuDefinition implements RegistryDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $requiredCapabilities;

    /**
     * @var list<string>
     */
    private readonly array $anyFeatureKeys;

    /**
     * @param list<string> $capabilities
     * @param list<string> $anyFeatureKeys
     */
    public function __construct(
        private readonly string $menuKey,
        private readonly string $label,
        private readonly ?string $parentKey,
        private readonly string $routeName,
        private readonly ?string $featureKey,
        array $capabilities,
        private readonly int $menuOrder,
        private readonly ?string $policy,
        array $anyFeatureKeys = [],
        private readonly ?string $section = null,
        private readonly string $icon = ''
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $this->menuKey) !== 1) {
            throw new InvalidArgumentException('관리 메뉴 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('관리 메뉴 이름은 비어 있을 수 없습니다.');
        }

        if (
            $this->parentKey !== null
            && preg_match('/^[a-z][a-z0-9_.]*$/', $this->parentKey) !== 1
        ) {
            throw new InvalidArgumentException('상위 관리 메뉴 키 형식이 올바르지 않습니다.');
        }

        if (
            $this->routeName !== 'first_accessible_child'
            && preg_match('/^[a-z][a-z0-9_.-]*$/', $this->routeName) !== 1
        ) {
            throw new InvalidArgumentException('관리 메뉴 Route 형식이 올바르지 않습니다.');
        }

        if (
            $this->featureKey !== null
            && preg_match('/^[a-z][a-z0-9_]*$/', $this->featureKey) !== 1
        ) {
            throw new InvalidArgumentException('관리 메뉴 기능 키 형식이 올바르지 않습니다.');
        }

        $normalized = array_values(array_unique(array_map('strval', $capabilities)));
        if (count($normalized) !== count($capabilities)) {
            throw new InvalidArgumentException('관리 메뉴 권한이 중복되었습니다.');
        }

        foreach ($normalized as $capability) {
            if (preg_match('/^oj_[a-z0-9_]+$/', $capability) !== 1) {
                throw new InvalidArgumentException('관리 메뉴 권한 키 형식이 올바르지 않습니다.');
            }
        }

        if (
            $this->menuOrder < 0
            || ! in_array($this->policy, [null, 'super_admin_only', 'all_capabilities'], true)
        ) {
            throw new InvalidArgumentException('관리 메뉴 순서 또는 정책이 올바르지 않습니다.');
        }

        $this->requiredCapabilities = $normalized;

        $normalizedFeatures = array_values(array_unique(array_map(
            'strval',
            $anyFeatureKeys
        )));
        if (count($normalizedFeatures) !== count($anyFeatureKeys)) {
            throw new InvalidArgumentException('관리 메뉴 선택 기능 키가 중복되었습니다.');
        }
        foreach ($normalizedFeatures as $featureKey) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $featureKey) !== 1) {
                throw new InvalidArgumentException('관리 메뉴 선택 기능 키 형식이 올바르지 않습니다.');
            }
        }
        $this->anyFeatureKeys = $normalizedFeatures;

        if (
            $this->section !== null
            && (trim($this->section) === '' || mb_strlen($this->section) > 40)
        ) {
            throw new InvalidArgumentException('관리 메뉴 묶음 이름이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            (string) ($definition['label'] ?? ''),
            isset($definition['parent']) ? (string) $definition['parent'] : null,
            (string) ($definition['route'] ?? ''),
            isset($definition['feature_key']) ? (string) $definition['feature_key'] : null,
            array_values((array) ($definition['capabilities'] ?? [])),
            (int) ($definition['order'] ?? -1),
            isset($definition['policy']) ? (string) $definition['policy'] : null,
            array_values((array) ($definition['any_feature_keys'] ?? [])),
            isset($definition['section']) ? (string) $definition['section'] : null,
            (string) ($definition['icon'] ?? '')
        );
    }

    public function key(): string
    {
        return $this->menuKey;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function owner(): ModuleId
    {
        return new ModuleId('admin_console');
    }

    public function label(): string
    {
        return $this->label;
    }

    public function parent(): ?string
    {
        return $this->parentKey;
    }

    public function route(): string
    {
        return $this->routeName;
    }

    public function featureKey(): ?string
    {
        return $this->featureKey;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->requiredCapabilities;
    }

    public function order(): int
    {
        return $this->menuOrder;
    }

    public function policy(): ?string
    {
        return $this->policy;
    }

    /**
     * @return list<string>
     */
    public function anyFeatureKeys(): array
    {
        return $this->anyFeatureKeys;
    }

    /**
     * Sidebar grouping. On a child it is the heading shown above the first
     * item of its group; on a root a change of section draws a divider.
     */
    public function section(): ?string
    {
        return $this->section;
    }
}
