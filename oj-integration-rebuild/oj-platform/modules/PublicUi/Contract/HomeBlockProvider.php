<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;

interface HomeBlockProvider
{
    public function key(): string;
    public function title(): string;
    public function load(HomeBlockContext $context, int $limit): ?HomeBlock;
}
