<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1\Research;

/** Versioned companion-plugin integration; registered before the module graph freezes. */
interface ResearchExtensionV1
{
    /** Additive definitions only. Existing routes, feature key and table names preserve installed data. */
    public function configuration(): array;
    public function build(ResearchHostV1 $host): ResearchRuntimeV1;
}
