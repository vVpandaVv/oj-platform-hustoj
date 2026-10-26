<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Error;

use Throwable;

interface ExceptionTrackingLogger extends TrackingLogger
{
    public function logException(string $trackingId, Throwable $error): void;
}
