<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingWrapper;

interface VisualGradingTemplateSource
{
    public function wrapper(VisualGradingMode $mode): VisualGradingWrapper;

    /**
     * Single-file C++17 image comparison special judge.
     */
    public function spjSource(): string;
}
