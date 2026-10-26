<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface HostAddressResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
