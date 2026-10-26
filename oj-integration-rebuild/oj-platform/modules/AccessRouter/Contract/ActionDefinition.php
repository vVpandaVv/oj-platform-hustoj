<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionTransport;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use InvalidArgumentException;

final class ActionDefinition implements RegistryDefinition
{
    public function __construct(
        private readonly string $actionKey,
        private readonly ModuleId $moduleOwner,
        private readonly ActionTransport $actionTransport,
        private readonly AuthenticationRequirement $authentication
    ) {
        if (preg_match('/^oj_[a-z0-9_]+$/', $this->actionKey) !== 1) {
            throw new InvalidArgumentException('Action 키 형식이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['key'] ?? ''),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            ActionTransport::from((string) ($definition['transport'] ?? '')),
            AuthenticationRequirement::from((string) ($definition['authentication'] ?? ''))
        );
    }

    public function key(): string
    {
        return $this->actionKey;
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function transport(): ActionTransport
    {
        return $this->actionTransport;
    }

    public function authentication(): AuthenticationRequirement
    {
        return $this->authentication;
    }
}
