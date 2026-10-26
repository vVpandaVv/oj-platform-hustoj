<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Kernel\Environment\RequiredPhpExtensions;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;

final class RuntimeSystemStatusProvider implements SystemStatusProvider
{
    public function key(): string
    {
        return 'runtime';
    }

    public function current(): SystemStatusItem
    {
        $missing = RequiredPhpExtensions::missing();

        return new SystemStatusItem(
            $this->key(),
            'PHP 실행 환경',
            $missing === [] ? 'success' : 'danger',
            $missing === []
                ? '필수 PHP 확장과 현재 요청 실행 상태가 정상입니다.'
                : '필수 PHP 확장이 누락되었습니다: ' . implode(', ', $missing),
            $missing === []
                ? '현재 조치가 필요하지 않습니다.'
                : 'PHP 확장을 설치한 뒤 웹 서버를 다시 시작하세요.',
            [
                'PHP 버전' => PHP_VERSION,
                'SAPI' => PHP_SAPI,
                '플러그인 버전' => defined('OJ_PLATFORM_VERSION')
                    ? (string) OJ_PLATFORM_VERSION
                    : '개발 검사',
            ],
            'oj_view_system_status'
        );
    }
}
