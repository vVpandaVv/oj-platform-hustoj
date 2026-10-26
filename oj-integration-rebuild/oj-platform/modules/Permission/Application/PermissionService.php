<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\ContextRule;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Permission\Domain\CapabilityScope;

final class PermissionService implements Authorization
{
    /**
     * @var list<ContextRule>
     */
    private array $contextRules;

    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param list<ContextRule> $contextRules
     */
    public function __construct(
        private readonly DefinitionRegistry $capabilities,
        private readonly CapabilityChecker $checker,
        array $contextRules = []
    ) {
        $this->contextRules = array_values($contextRules);
    }

    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        if ($userId < 1) {
            return false;
        }

        $definition = $this->capabilities->find($capability);
        if (! $definition instanceof CapabilityDefinition) {
            return false;
        }

        if ($this->checker->isSuperAdmin($userId)) {
            return true;
        }

        $scope = $definition->scope();
        $hasGlobalCapability = $this->checker->userCan($userId, $capability);

        if ($scope === CapabilityScope::Global) {
            return $hasGlobalCapability;
        }

        if ($scope === CapabilityScope::Creation) {
            return $context === null && $hasGlobalCapability;
        }

        if ($scope === CapabilityScope::GlobalOrContext && $hasGlobalCapability) {
            return true;
        }

        if ($context === null) {
            return false;
        }

        $matchingRules = array_values(array_filter(
            $this->contextRules,
            static fn (ContextRule $rule): bool => $rule->supports($capability, $context)
        ));

        if (count($matchingRules) !== 1) {
            return false;
        }

        return $matchingRules[0]->allows($userId, $capability, $context);
    }

    /**
     * @param list<string> $capabilities
     */
    public function canAny(int $userId, array $capabilities): bool
    {
        foreach (array_values(array_unique($capabilities)) as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $userId > 0 && $this->checker->isSuperAdmin($userId);
    }
}
