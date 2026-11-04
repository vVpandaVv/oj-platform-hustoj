<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

final class AccountProtectionDiagnostic
{
    /** @var list<string> */
    private readonly array $blockingCapabilities;

    /** @param list<string> $blockingCapabilities */
    public function __construct(
        private readonly bool $protected,
        private readonly string $reason,
        array $blockingCapabilities = [],
        private readonly ?string $fault = null,
        private readonly ?string $exceptionType = null,
        private readonly int $learnerCapabilityCount = 0,
        private readonly ?string $sourceLocation = null
    ) {
        if (! in_array($reason, ['administrator_role', 'super_admin', 'lookup_unavailable', 'account_missing',
            'identity_mismatch', 'capability_data_invalid', 'capability_blocked', 'check_failed', 'not_protected'], true)
            || ($fault !== null && ! in_array($fault, ['super_admin_check_failed', 'user_lookup_failed',
                'capability_data_failed', 'addon_filter_failed', 'addon_filter_invalid', 'addon_catalog_failed',
                'addon_catalog_invalid', 'capability_scan_failed'], true))
            || ($exceptionType !== null && ! in_array($exceptionType, ['ArgumentCountError', 'TypeError', 'Error',
                'RuntimeException', 'LogicException', 'Throwable'], true))
            || $learnerCapabilityCount < 0
            || ($sourceLocation !== null && preg_match('/^(?:[a-z][a-z0-9-]{0,63}\/)?[A-Za-z0-9_.-]{1,100}\.php:[1-9][0-9]*$/D', $sourceLocation) !== 1)) {
            throw new \InvalidArgumentException('Invalid account protection diagnostic.');
        }
        $this->blockingCapabilities = array_values(array_unique(array_map(
            static fn (string $key): string => preg_match('/^[a-z][a-z0-9_]{0,168}$/D', $key) === 1
                ? $key : '[비표준 권한 키]',
            $blockingCapabilities
        )));
    }

    public function isProtected(): bool { return $this->protected; }
    public function reason(): string { return $this->reason; }
    /** @return list<string> */
    public function blockingCapabilities(): array { return $this->blockingCapabilities; }
    public function fault(): ?string { return $this->fault; }
    public function exceptionType(): ?string { return $this->exceptionType; }
    public function learnerCapabilityCount(): int { return $this->learnerCapabilityCount; }
    public function sourceLocation(): ?string { return $this->sourceLocation; }
}
