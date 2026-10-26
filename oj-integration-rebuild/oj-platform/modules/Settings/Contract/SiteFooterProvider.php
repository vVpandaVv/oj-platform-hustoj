<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SiteFooterProvider
{
    public function copyrightNotice(): string;

    public function administratorEmail(): string;

    /**
     * @return list<array{label: string, url: string}>
     */
    public function footerLinks(): array;
}
