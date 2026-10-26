<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;

final class MenuTree
{
    /**
     * @param DefinitionRegistry<MenuDefinition> $menus
     */
    public function __construct(private readonly DefinitionRegistry $menus)
    {
    }

    /**
     * @return list<MenuDefinition>
     */
    public function roots(): array
    {
        return $this->sort(array_filter(
            $this->menus->all(),
            static fn (MenuDefinition $menu): bool => $menu->parent() === null
        ));
    }

    /**
     * @return list<MenuDefinition>
     */
    public function childrenOf(string $parentKey): array
    {
        return $this->sort(array_filter(
            $this->menus->all(),
            static fn (MenuDefinition $menu): bool => $menu->parent() === $parentKey
        ));
    }

    /**
     * 애드온이 등록한 메뉴는 키가 "addon.<id>.그 외"로 시작하므로, 같은 <id>를 가진
     * 항목끼리는 서로 다른 항목 사이에 흩어지지 않고 한 덩어리로 붙어서 나오도록
     * 정렬한다. 정렬 순서 자체(order 값)는 그대로 존중하되, 묶음의 위치는 그 안에서
     * 가장 작은 order 값으로 정한다.
     *
     * @param array<string, MenuDefinition> $menus
     *
     * @return list<MenuDefinition>
     */
    private function sort(array $menus): array
    {
        $items = array_values($menus);
        $groupOrder = [];
        foreach ($items as $item) {
            $group = self::groupKey($item);
            $groupOrder[$group] = min($groupOrder[$group] ?? PHP_INT_MAX, $item->order());
        }
        usort(
            $items,
            static function (MenuDefinition $left, MenuDefinition $right) use ($groupOrder): int {
                $leftGroup = self::groupKey($left);
                $rightGroup = self::groupKey($right);

                return [$groupOrder[$leftGroup], $leftGroup, $left->order(), $left->key()]
                    <=> [$groupOrder[$rightGroup], $rightGroup, $right->order(), $right->key()];
            }
        );

        return $items;
    }

    /**
     * 애드온 항목은 addon id로 묶고, 그 외(플랫폼 고유 항목)는 각자 자기 키를 그룹으로
     * 삼아 다른 항목과 절대 묶이지 않게 한다. 그래야 애드온이 하나뿐인 항목만 등록한
     * 경우 기존처럼 순서(order)대로 자연스럽게 끼어들고, 같은 애드온이 여러 항목을
     * 등록한 경우에만 서로 붙어 나온다.
     *
     * @return non-empty-string
     */
    private static function groupKey(MenuDefinition $menu): string
    {
        return preg_match('/^(addon\.[a-z0-9_]+)\./', $menu->key(), $matches) === 1
            ? $matches[1]
            : $menu->key();
    }
}
