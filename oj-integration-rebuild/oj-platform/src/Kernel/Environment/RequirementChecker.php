<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Environment;

use CodingPanda\OjPlatform\Shared\Result\Result;

final class RequirementChecker
{
    /**
     * @return Result<null>
     */
    public function check(
        PluginEnvironment $environment,
        string $wordpressVersion,
        string $phpVersion,
        bool $multisite = false
    ): Result {
        $errors = [];

        if (version_compare($phpVersion, $environment->minimumPhpVersion(), '<')) {
            $errors[] = sprintf(
                'OJ Platform은 PHP %s 이상이 필요합니다. 현재 버전은 %s입니다.',
                $environment->minimumPhpVersion(),
                $phpVersion
            );
        }

        if (version_compare($wordpressVersion, $environment->minimumWordPressVersion(), '<')) {
            $errors[] = sprintf(
                'OJ Platform은 WordPress %s 이상이 필요합니다. 현재 버전은 %s입니다.',
                $environment->minimumWordPressVersion(),
                $wordpressVersion
            );
        }

        if ($multisite) {
            $errors[] = '접두어 없는 플랫폼 테이블을 사용하므로 WordPress Multisite에는 설치할 수 없습니다.';
        }

        return $errors === [] ? Result::success() : Result::failure(...$errors);
    }
}
