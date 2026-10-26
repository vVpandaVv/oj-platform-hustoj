<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

interface Module
{
    public function definition(): ModuleDefinition;

    public function register(): void;

    public function connect(): void;
}
