<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

use InvalidArgumentException;

final class ModuleManifestItem
{
    /**
     * @var list<ModuleId>
     */
    private readonly array $moduleDependencies;

    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        private readonly ModuleId $moduleId,
        private readonly ModuleType $moduleType,
        array $dependencies
    ) {
        $normalized = [];
        foreach ($dependencies as $dependency) {
            $id = new ModuleId((string) $dependency);
            if ($id->equals($this->moduleId)) {
                throw new InvalidArgumentException('목표 모듈은 자기 자신을 의존할 수 없습니다.');
            }

            $normalized[$id->value()] = $id;
        }

        if (count($normalized) !== count($dependencies)) {
            throw new InvalidArgumentException('목표 모듈 의존 관계가 중복되었습니다.');
        }

        $this->moduleDependencies = array_values($normalized);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            new ModuleId((string) ($definition['key'] ?? '')),
            ModuleType::from((string) ($definition['type'] ?? '')),
            array_values((array) ($definition['dependencies'] ?? []))
        );
    }

    public function id(): ModuleId
    {
        return $this->moduleId;
    }

    public function type(): ModuleType
    {
        return $this->moduleType;
    }

    /**
     * @return list<ModuleId>
     */
    public function dependencies(): array
    {
        return $this->moduleDependencies;
    }
}
