<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use RuntimeException;

final class WordPressPrivateDataRootProvider implements PrivateDataRootProvider
{
    public function root(): string
    {
        if (defined('OJ_PLATFORM_PRIVATE_DATA_DIR')) {
            $configured = constant('OJ_PLATFORM_PRIVATE_DATA_DIR');
            if (is_string($configured) && trim($configured) !== '') {
                return rtrim(trim($configured), '/\\');
            }
        }
        if (defined('WP_CONTENT_DIR')) {
            $content = constant('WP_CONTENT_DIR');
            if (is_string($content) && trim($content) !== '') {
                return rtrim(trim($content), '/\\')
                    . DIRECTORY_SEPARATOR . 'oj-platform-private';
            }
        }

        throw new RuntimeException(
            '비공개 작업 데이터 경로를 결정할 수 없습니다.'
        );
    }
}
