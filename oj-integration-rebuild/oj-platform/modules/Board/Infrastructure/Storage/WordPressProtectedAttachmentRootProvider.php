<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Infrastructure\Storage;

use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentRootProvider;
use RuntimeException;

final class WordPressProtectedAttachmentRootProvider implements ProtectedAttachmentRootProvider
{
    public function root(): string
    {
        if (defined('OJ_PLATFORM_PRIVATE_DATA_DIR')) {
            $configured = constant('OJ_PLATFORM_PRIVATE_DATA_DIR');
            if (is_string($configured) && trim($configured) !== '') {
                return rtrim(trim($configured), '/\\')
                    . DIRECTORY_SEPARATOR
                    . 'community-attachments';
            }
        }
        if (defined('WP_CONTENT_DIR')) {
            $content = constant('WP_CONTENT_DIR');
            if (is_string($content) && trim($content) !== '') {
                return rtrim(trim($content), '/\\')
                    . DIRECTORY_SEPARATOR
                    . 'oj-platform-private'
                    . DIRECTORY_SEPARATOR
                    . 'community-attachments';
            }
        }

        throw new RuntimeException(
            '보호 첨부 저장 경로를 결정할 수 없습니다.'
        );
    }
}