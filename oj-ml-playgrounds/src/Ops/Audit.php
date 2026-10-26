<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Domain\Problem;
use Throwable;

final class Audit
{
    public static function log(Throwable $error, string $correlationId = ''): void
    {
        // Never log exception text, SQL, filesystem paths, uploaded data, or credentials.
        error_log((string) wp_json_encode([
            'component' => 'oj-ml-playgrounds', 'event' => 'operation.failed',
            'code' => $error instanceof Problem ? $error->problemCode() : 'OJ_ML_INTERNAL_ERROR',
            'correlationId' => substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $correlationId) ?? '', 0, 100),
            'at' => gmdate(DATE_ATOM),
        ]));
    }
}
