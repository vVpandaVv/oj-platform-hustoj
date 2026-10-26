<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

/** Retryable provider/runner outage; semantic and authorization errors never use this class. */
final class TransientGenerationException extends GenerationFailure
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 0, string $failureCode = 'transient_failure') { parent::__construct($message, $failureCode, true); }
}
