<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Navigation;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use InvalidArgumentException;

final class ConsoleMenuAccessRuleRegistry
{
    /**
     * @var array<string, ConsoleMenuAccessRule>
     */
    private array $rules = [];

    public function register(string $key, ConsoleMenuAccessRule $rule): void
    {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1) {
            throw new InvalidArgumentException('관리 메뉴 접근 규칙 키 형식이 올바르지 않습니다.');
        }
        if (isset($this->rules[$key])) {
            throw new InvalidArgumentException('관리 메뉴 접근 규칙이 중복되었습니다: ' . $key);
        }
        $this->rules[$key] = $rule;
    }

    public function allows(int $userId, MenuDefinition $menu): bool
    {
        $matches = array_values(array_filter(
            $this->rules,
            static fn (ConsoleMenuAccessRule $rule): bool => $rule->supports($menu)
        ));
        if (count($matches) !== 1) {
            return false;
        }

        return $matches[0]->allows($userId, $menu);
    }
}
