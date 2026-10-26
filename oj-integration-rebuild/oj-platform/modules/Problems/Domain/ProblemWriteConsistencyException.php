<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use DomainException;
use Throwable;

final class ProblemWriteConsistencyException extends DomainException
{
    public function __construct(
        private readonly ProblemId $problemId,
        string $message,
        private readonly ?Throwable $recoveryError,
        Throwable $previous
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function recoveryError(): ?Throwable
    {
        return $this->recoveryError;
    }
}
