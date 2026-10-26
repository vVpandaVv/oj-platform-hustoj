<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SystemOperationPolicy
{
    public function maintenanceEnabled(): bool;

    public function maintenanceMessage(): string;

    public function maintenanceEndsAt(): ?string;

    public function isModuleWriteFrozen(string $moduleKey): bool;

    public function allowsAction(string $moduleKey, string $actionKey): bool;

    public function allowsJob(string $moduleKey, string $jobKey): bool;
}
