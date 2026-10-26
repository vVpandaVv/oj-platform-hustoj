<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorSnapshotV1;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;

/** @internal */
final class UnavailableAddonEditorGateway implements AddonEditorGatewayV1
{
    public function current(): AddonEditorSnapshotV1
    {
        return new AddonEditorSnapshotV1(AceEditorThemeCatalog::DEFAULT_THEME, 16, false, '', '');
    }
}
