<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Access;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\ConsoleRouteAccessRule;
use InvalidArgumentException;

final class ConsoleRouteAccessRuleRegistry
{
    /**
     * @var array<string, ConsoleRouteAccessRule>
     */
    private array $rules = [];

    public function register(string $key, ConsoleRouteAccessRule $rule): void
    {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1) {
            throw new InvalidArgumentException(
                '관리 화면 문맥 권한 키 형식이 올바르지 않습니다.'
            );
        }
        if (isset($this->rules[$key])) {
            throw new InvalidArgumentException(
                '관리 화면 문맥 권한이 중복되었습니다: ' . $key
            );
        }
        $this->rules[$key] = $rule;
    }

    public function allows(int $userId, RouteMatch $route): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->supports($route) && $rule->allows($userId, $route)) {
                return true;
            }
        }

        return false;
    }
}
