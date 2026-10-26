<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli;

/** Repositories release their borrow; the connector owns the physical connection. */
final class RequestReadConnection extends \mysqli
{
    private bool $retained = false;

    public function retain(): void { $this->retained = true; }

    public function close(): bool
    {
        return $this->retained ? true : parent::close();
    }

    public function release(): void
    {
        if ($this->retained) { $this->retained = false; parent::close(); }
    }
}
