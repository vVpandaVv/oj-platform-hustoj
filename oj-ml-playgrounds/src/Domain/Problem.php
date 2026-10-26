<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

use RuntimeException;
use WP_Error;

final class Problem extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $params;

    public function __construct(
        private readonly string $problemCode,
        string $message,
        private readonly int $httpStatus = 400,
        array $params = []
    ) {
        parent::__construct($message);
        $this->params = $params;
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function toWpError(string $correlationId): WP_Error
    {
        return new WP_Error(
            $this->problemCode,
            $this->getMessage(),
            [
                'status' => $this->httpStatus,
                'params' => $this->params,
                'correlationId' => $correlationId,
            ]
        );
    }
}
