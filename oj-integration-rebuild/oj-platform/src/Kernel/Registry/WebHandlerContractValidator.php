<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;

final class WebHandlerContractValidator implements RegistrySetValidator
{
    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     * @param DefinitionRegistry<ActionDefinition> $actions
     */
    public function __construct(
        private readonly DefinitionRegistry $routes,
        private readonly DefinitionRegistry $actions,
        private readonly RouteHandlerRegistry $routeHandlers,
        private readonly ActionHandlerRegistry $actionHandlers,
        private readonly array $completedOwnerModules = [],
        private readonly array $deferredActions = []
    ) {
    }

    public function validate(): void
    {
        $completedOwners = $this->completedOwners();
        $deferredActions = $this->validatedDeferredActions();

        foreach ($this->routeHandlers->keys() as $routeName) {
            if (! $this->routes->find($routeName) instanceof RouteDefinition) {
                throw KernelException::invalidRegistry(sprintf(
                    '정의되지 않은 Route Handler입니다: %s',
                    $routeName
                ));
            }
        }

        foreach (array_keys($this->actionHandlers->all()) as $actionKey) {
            if (! $this->actions->find($actionKey) instanceof ActionDefinition) {
                throw KernelException::invalidRegistry(sprintf(
                    '정의되지 않은 Action Handler입니다: %s',
                    $actionKey
                ));
            }
        }

        foreach ($this->routes->all() as $route) {
            if (
                isset($completedOwners[$route->owner()->value()])
                && $this->routeHandlers->find($route->key()) === null
            ) {
                throw KernelException::invalidRegistry(sprintf(
                    '완료 모듈의 Route Handler가 없습니다: %s (%s)',
                    $route->key(),
                    $route->owner()->value()
                ));
            }
        }

        foreach ($this->actions->all() as $action) {
            if (
                isset($completedOwners[$action->owner()->value()])
                && ! isset($deferredActions[$action->key()])
                && $this->actionHandlers->find($action->key()) === null
            ) {
                throw KernelException::invalidRegistry(sprintf(
                    '완료 모듈의 Action Handler가 없습니다: %s (%s)',
                    $action->key(),
                    $action->owner()->value()
                ));
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function completedOwners(): array
    {
        $owners = [];
        foreach ($this->completedOwnerModules as $owner) {
            if (
                ! is_string($owner)
                || preg_match('/^[a-z][a-z0-9_]*$/', $owner) !== 1
                || isset($owners[$owner])
            ) {
                throw KernelException::invalidRegistry(
                    '완료 모듈 Handler 검사 범위가 올바르지 않습니다.'
                );
            }
            $owners[$owner] = true;
        }

        return $owners;
    }

    /**
     * @return array<string, true>
     */
    private function validatedDeferredActions(): array
    {
        $deferred = [];
        foreach ($this->deferredActions as $actionKey => $contract) {
            $definition = is_string($actionKey)
                ? $this->actions->find($actionKey)
                : null;
            if (
                ! $definition instanceof ActionDefinition
                || ! is_array($contract)
                || ($contract['owner_module'] ?? null)
                    !== $definition->owner()->value()
                || preg_match(
                    '/^R-[0-9]{2}$/',
                    (string) ($contract['implementation_stage'] ?? '')
                ) !== 1
                || trim((string) ($contract['reason'] ?? '')) === ''
            ) {
                throw KernelException::invalidRegistry(sprintf(
                    'Action 유예 계약이 올바르지 않습니다: %s',
                    is_string($actionKey) ? $actionKey : '(invalid)'
                ));
            }
            if ($this->actionHandlers->find($actionKey) !== null) {
                throw KernelException::invalidRegistry(sprintf(
                    '구현된 Action에 유예 계약이 남아 있습니다: %s',
                    $actionKey
                ));
            }
            $deferred[$actionKey] = true;
        }

        return $deferred;
    }
}
