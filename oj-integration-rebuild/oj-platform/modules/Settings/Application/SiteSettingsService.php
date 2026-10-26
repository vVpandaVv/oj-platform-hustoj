<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteFooterVisibilityProvider;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SiteSettings;

final class SiteSettingsService implements SiteBrandingProvider, SiteFooterVisibilityProvider
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('site', SiteSettings::fromArray([])->toArray());
    }

    public function current(): SiteSettings
    {
        $value = $this->snapshot()->value();

        return SiteSettings::fromArray(is_array($value) ? $value : []);
    }

    /**
     * @param list<array{label: string, url: string}> $footerLinks
     */
    public function save(
        string $serviceName,
        string $shortDescription,
        string $supportLine,
        string $copyrightNotice,
        string $administratorEmail,
        array $footerLinks,
        int $expectedRevision,
        ?array $footerVisibility = null
    ): SettingsSnapshot {
        $site = new SiteSettings(
            trim($serviceName),
            trim($shortDescription),
            trim($supportLine),
            trim($copyrightNotice),
            trim($administratorEmail),
            $footerLinks,
            $footerVisibility ?? $this->current()->toArray()['footer_visibility']
        );

        return $this->settings->save('site', $site->toArray(), $expectedRevision);
    }

    public function serviceName(): string
    {
        return $this->current()->serviceName();
    }

    public function shortDescription(): string
    {
        return $this->current()->shortDescription();
    }

    public function supportLine(): string
    {
        return $this->current()->supportLine();
    }

    public function copyrightNotice(): string
    {
        return $this->current()->copyrightNotice();
    }

    public function administratorEmail(): string
    {
        return $this->current()->administratorEmail();
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function footerLinks(): array
    {
        return $this->current()->footerLinks();
    }

    public function footerLinkVisible(string $path): bool
    {
        return $this->current()->footerLinkVisible($path);
    }
}
