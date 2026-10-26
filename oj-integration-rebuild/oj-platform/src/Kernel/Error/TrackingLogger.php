<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Error;

interface TrackingLogger
{
    public function log(string $trackingId, string $errorClass): void;
}
