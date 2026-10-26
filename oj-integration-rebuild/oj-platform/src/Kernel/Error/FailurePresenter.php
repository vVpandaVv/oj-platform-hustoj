<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Error;

interface FailurePresenter
{
    /**
     * @param list<string> $messages
     */
    public function present(array $messages): void;
}
