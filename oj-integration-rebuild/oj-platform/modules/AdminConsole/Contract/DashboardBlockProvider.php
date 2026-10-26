<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;

interface DashboardBlockProvider
{
    public function key(): string;
    public function title(): string;
    public function kind(): string;
    public function priority(): int;
    public function load(DashboardContext $context): ?DashboardBlock;
}
