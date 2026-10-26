<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

final class InstallationPlan
{
    /**
     * @var list<InstallationStep>
     */
    private readonly array $installationSteps;

    /**
     * @var list<string>
     */
    private readonly array $missingSchemaNames;

    /**
     * @var list<string>
     */
    private readonly array $collisionNames;

    /**
     * @param list<InstallationStep> $steps
     * @param list<string> $missingSchemas
     * @param list<string> $collisions
     */
    public function __construct(
        private readonly string $installationId,
        array $steps,
        array $missingSchemas,
        array $collisions
    ) {
        $this->installationSteps = array_values($steps);
        $this->missingSchemaNames = array_values($missingSchemas);
        $this->collisionNames = array_values($collisions);
    }

    public function installationId(): string
    {
        return $this->installationId;
    }

    /**
     * @return list<InstallationStep>
     */
    public function steps(): array
    {
        return $this->installationSteps;
    }

    /**
     * @return list<string>
     */
    public function missingSchemas(): array
    {
        return $this->missingSchemaNames;
    }

    /**
     * @return list<string>
     */
    public function collisions(): array
    {
        return $this->collisionNames;
    }

    public function canExecute(): bool
    {
        return $this->collisionNames === [];
    }

    /** @return list<string> */
    public function upgradeTables(): array
    {
        return array_values(array_map(
            static fn (InstallationStep $step): string =>
                $step->schema()->key(),
            array_filter(
                $this->installationSteps,
                static fn (InstallationStep $step): bool =>
                    $step->requiresUpgrade()
            )
        ));
    }

    public function isCompleteAfterExecution(): bool
    {
        return $this->canExecute() && $this->missingSchemaNames === [];
    }

    public function hash(): string
    {
        $values = array_map(
            static fn (InstallationStep $step): array => [
                $step->wave(),
                $step->schema()->key(),
                $step->schema()->fingerprint(),
                $step->requiresCreation(),
                $step->requiresUpgrade(),
                $step->isAlreadyInstalled(),
            ],
            $this->installationSteps
        );

        return hash(
            'sha256',
            (string) json_encode(
                [$this->installationId, $values, $this->missingSchemaNames],
                JSON_UNESCAPED_SLASHES
            )
        );
    }
}
