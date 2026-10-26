<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Inspection;

final class InspectionResult
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const SYSTEM_PENDING = 'system_pending';

    /**
     * @param list<array<string,mixed>> $findings
     * @param array<string,mixed> $facts
     */
    private function __construct(
        private readonly string $status,
        private readonly array $findings,
        private readonly array $facts
    ) {
    }

    /** @param list<array<string,mixed>> $findings @param array<string,mixed> $facts */
    public static function accepted(array $findings, array $facts): self
    {
        return new self(self::ACCEPTED, $findings, $facts);
    }

    /** @param list<array<string,mixed>> $findings @param array<string,mixed> $facts */
    public static function rejected(array $findings, array $facts = []): self
    {
        return new self(self::REJECTED, $findings, $facts);
    }

    /** @param list<array<string,mixed>> $findings @param array<string,mixed> $facts */
    public static function systemPending(array $findings, array $facts = []): self
    {
        return new self(self::SYSTEM_PENDING, $findings, $facts);
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<array<string,mixed>> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return array<string,mixed> */
    public function facts(): array
    {
        return $this->facts;
    }

    public function firstFailureCode(): ?string
    {
        foreach ($this->findings as $finding) {
            if (($finding['severity'] ?? '') === 'error' && is_string($finding['code'] ?? null)) {
                return $finding['code'];
            }
        }

        return null;
    }
}
