<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SiteFooterVisibilityProvider extends SiteFooterProvider
{
    public const LINKS = [
        '/terms' => '이용약관',
        '/privacy' => '개인정보 처리방침',
        '/copyright' => '저작권 정책',
        '/email-collection-refusal' => '이메일 수집 거부',
        '/guardian-consent-policy' => '법정대리인 동의서',
        '/open-source-licenses' => '오픈소스 라이선스',
    ];

    public function footerLinkVisible(string $path): bool;
}
