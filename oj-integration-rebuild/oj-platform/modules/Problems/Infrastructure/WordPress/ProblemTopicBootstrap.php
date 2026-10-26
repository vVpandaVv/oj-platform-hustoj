<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use Throwable;

final class ProblemTopicBootstrap
{
    public function __construct(
        private readonly WordPressProblemTopicDictionary $topics,
        private readonly ErrorReporter $errors
    ) {
    }

    public function run(): void
    {
        try {
            $this->topics->bootstrap();
        } catch (Throwable $error) {
            $this->errors->report($error);
        }
    }
}
