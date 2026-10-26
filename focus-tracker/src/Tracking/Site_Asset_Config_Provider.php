<?php
declare(strict_types=1);

namespace FocusTracker\Tracking;

use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetConfigProviderV1;

/** Configures tracking inside the platform's standalone HTML documents. */
final class Site_Asset_Config_Provider implements AddonSiteAssetConfigProviderV1 {
    public function config(int $actorUserId): array {
        return Tracked_Page_Enqueuer::config($actorUserId);
    }
}
