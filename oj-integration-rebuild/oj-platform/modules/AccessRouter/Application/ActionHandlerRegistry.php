<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use InvalidArgumentException;

final class ActionHandlerRegistry
{
    /**
     * @var array<string, ActionHandler>
     */
    private array $handlers = [];

    public function register(string $actionKey, ActionHandler $handler): void
    {
        if (preg_match('/^oj_[a-z0-9_]+$/', $actionKey) !== 1) {
            throw new InvalidArgumentException('Action Handler 키 형식이 올바르지 않습니다.');
        }

        if (isset($this->handlers[$actionKey])) {
            throw new InvalidArgumentException(sprintf(
                'Action Handler가 중복 등록되었습니다: %s',
                $actionKey
            ));
        }

        $this->handlers[$actionKey] = $handler;
    }

    public function find(string $actionKey): ?ActionHandler
    {
        return $this->handlers[$actionKey] ?? null;
    }

    /**
     * @return array<string, ActionHandler>
     */
    public function all(): array
    {
        ksort($this->handlers);

        return $this->handlers;
    }
}
