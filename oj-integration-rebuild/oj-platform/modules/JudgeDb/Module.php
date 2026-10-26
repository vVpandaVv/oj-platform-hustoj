<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeProfileDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<JudgeProfileDefinition> $profiles
     */
    public function __construct(
        private readonly DefinitionRegistry $profiles,
        private readonly JudgeProfileDefinition $profile
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('judge_db'),
            ModuleType::Required,
            ['settings']
        );
    }

    public function register(): void
    {
        $this->profiles->register($this->profile);
    }

    public function connect(): void
    {
    }
}
