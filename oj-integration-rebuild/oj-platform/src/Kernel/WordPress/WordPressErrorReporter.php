<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use Throwable;

final class WordPressErrorReporter implements ErrorReporter
{
    public function report(Throwable $error): void
    {
        $detail = (new \CodingPanda\OjPlatform\Kernel\Error\ExceptionDiagnostics())->describe($error);
        // Raw messages and trace arguments may contain credentials, queries or learner source.
        error_log(sprintf('[OJ Platform] %s [%s] %s: %s in %s',
            $detail['error_class'], $detail['code'], $detail['operation'], $detail['summary'], $detail['source']));
    }
}
