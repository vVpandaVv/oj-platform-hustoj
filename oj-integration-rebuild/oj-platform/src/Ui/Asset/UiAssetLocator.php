<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Asset;

interface UiAssetLocator
{
    public function stylesheetUrl(): string;

    public function scriptUrl(): string;

    public function mathJaxLoaderUrl(): string;

    public function iconSpriteUrl(): string;

    public function faviconUrl(): string;
}
