<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;

interface SecretVault
{
    public function store(mixed $value): SecretReference;

    public function reveal(SecretReference $reference): mixed;

    public function discard(SecretReference $reference): void;
}
