<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Error;

use Throwable;

interface ErrorReporter
{
    public function report(Throwable $error): void;
}
