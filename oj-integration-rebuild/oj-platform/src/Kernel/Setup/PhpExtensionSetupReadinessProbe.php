<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

use CodingPanda\OjPlatform\Kernel\Environment\RequiredPhpExtensions;

final class PhpExtensionSetupReadinessProbe implements SetupReadinessProbe
{
    public function blockers(): array
    {
        $blockers = [];
        $labels = RequiredPhpExtensions::labels();
        foreach (RequiredPhpExtensions::missing() as $extension) {
            $blockers[] = sprintf(
                'PHP %s 확장을 설치하고 웹 서버를 다시 시작해 주세요.',
                $labels[$extension]
            );
        }

        return $blockers;
    }
}
