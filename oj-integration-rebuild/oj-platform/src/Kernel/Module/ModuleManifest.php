<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

use InvalidArgumentException;

final class ModuleManifest
{
    /**
     * @var array<string, ModuleManifestItem>
     */
    private readonly array $items;

    /**
     * @param list<array<string, mixed>> $configuration
     */
    public function __construct(array $configuration)
    {
        $items = [];
        foreach ($configuration as $definition) {
            $item = ModuleManifestItem::fromArray($definition);
            $key = $item->id()->value();
            if (isset($items[$key])) {
                throw new InvalidArgumentException(sprintf('목표 모듈이 중복되었습니다: %s', $key));
            }

            $items[$key] = $item;
        }

        foreach ($items as $item) {
            foreach ($item->dependencies() as $dependency) {
                if (! isset($items[$dependency->value()])) {
                    throw new InvalidArgumentException(sprintf(
                        '목표 모듈 %s의 의존 모듈 %s가 없습니다.',
                        $item->id()->value(),
                        $dependency->value()
                    ));
                }
            }
        }

        $this->assertAcyclic($items);
        $this->items = $items;
    }

    public function has(ModuleId|string $moduleId): bool
    {
        $key = $moduleId instanceof ModuleId ? $moduleId->value() : $moduleId;

        return isset($this->items[$key]);
    }

    public function get(ModuleId|string $moduleId): ModuleManifestItem
    {
        $key = $moduleId instanceof ModuleId ? $moduleId->value() : $moduleId;
        if (! isset($this->items[$key])) {
            throw new InvalidArgumentException(sprintf('목표 모듈을 찾을 수 없습니다: %s', $key));
        }

        return $this->items[$key];
    }

    /**
     * @return array<string, ModuleManifestItem>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function assertLoadedCatalog(ModuleCatalog $catalog): void
    {
        foreach ($catalog->definitions() as $definition) {
            $key = $definition->id()->value();
            $target = $this->get($key);

            if ($target->type() !== $definition->type()) {
                throw new InvalidArgumentException(sprintf(
                    '모듈 유형이 목표 manifest와 다릅니다: %s',
                    $key
                ));
            }

            $actualDependencies = array_map(
                static fn (ModuleId $dependency): string => $dependency->value(),
                array_merge(
                    $definition->requiredDependencies(),
                    $definition->optionalDependencies()
                )
            );
            $targetDependencies = array_map(
                static fn (ModuleId $dependency): string => $dependency->value(),
                $target->dependencies()
            );
            sort($actualDependencies);
            sort($targetDependencies);

            if ($actualDependencies !== $targetDependencies) {
                throw new InvalidArgumentException(sprintf(
                    '모듈 의존 관계가 목표 manifest와 다릅니다: %s',
                    $key
                ));
            }
        }
    }

    /**
     * @param array<string, ModuleManifestItem> $items
     */
    private function assertAcyclic(array $items): void
    {
        $states = [];

        $visit = function (string $key) use (&$visit, &$states, $items): void {
            if (($states[$key] ?? 0) === 2) {
                return;
            }

            if (($states[$key] ?? 0) === 1) {
                throw new InvalidArgumentException('목표 모듈 의존 관계에 순환이 있습니다.');
            }

            $states[$key] = 1;
            foreach ($items[$key]->dependencies() as $dependency) {
                $visit($dependency->value());
            }
            $states[$key] = 2;
        };

        foreach (array_keys($items) as $key) {
            $visit($key);
        }
    }
}
