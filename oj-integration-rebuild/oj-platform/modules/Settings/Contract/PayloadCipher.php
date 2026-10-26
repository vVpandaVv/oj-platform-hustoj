<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface PayloadCipher
{
    /**
     * @return array<string, mixed>
     */
    public function encrypt(mixed $value, string $associatedData): array;

    /**
     * @param array<string, mixed> $record
     */
    public function decrypt(array $record, string $associatedData): mixed;
}
