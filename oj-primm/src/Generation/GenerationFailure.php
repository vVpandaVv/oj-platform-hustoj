<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

class GenerationFailure extends \DomainException
{
    public function __construct(string $message, public readonly string $failureCode, public readonly bool $retryable) { parent::__construct($message); }
}
