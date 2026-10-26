<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Editor;

interface AceCoreAssetLocator
{
    /**
     * @return array{core: string, base: string}
     */
    public function urls(): array;
}
