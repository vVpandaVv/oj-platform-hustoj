<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

interface ActionUrlProvider
{
    public function actionUrl(): string;

    public function ajaxUrl(): string;
}
