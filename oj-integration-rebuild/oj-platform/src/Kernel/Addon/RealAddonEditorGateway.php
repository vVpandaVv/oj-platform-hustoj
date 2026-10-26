<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorSnapshotV1;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;
use Throwable;

/** @internal */
final class RealAddonEditorGateway implements AddonEditorGatewayV1
{
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly UserSubmissionPreferenceProvider $preferences,
        private readonly SubmissionCodeEditorAssets $assets
    ) {
    }

    public function current(): AddonEditorSnapshotV1
    {
        $preferences = new UserSubmissionPreferences();
        try {
            $identity = $this->accounts->current()->identity();
            if ($identity !== null) {
                $preferences = $this->preferences->forUser($identity->userId());
            }
        } catch (Throwable) {
            // Appearance lookup failure must not stop an addon's editing surface.
        }

        try {
            if ($this->assets->isReady()) {
                $urls = $this->assets->urls();
                return new AddonEditorSnapshotV1(
                    $preferences->editorTheme(),
                    $preferences->editorFontSize(),
                    true,
                    $urls['core'],
                    $urls['base']
                );
            }
        } catch (Throwable) {
            // The addon can keep its plain text input when Ace is unavailable.
        }

        return new AddonEditorSnapshotV1(
            $preferences->editorTheme(),
            $preferences->editorFontSize(),
            false,
            '',
            ''
        );
    }
}
