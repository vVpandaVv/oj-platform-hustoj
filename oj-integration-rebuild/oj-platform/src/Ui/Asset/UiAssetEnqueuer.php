<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Asset;

interface UiAssetEnqueuer
{
    public function enqueue(): void;
}
