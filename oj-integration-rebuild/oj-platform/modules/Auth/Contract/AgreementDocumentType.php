<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

enum AgreementDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Copyright = 'copyright';
    case EmailCollectionRefusal = 'email-collection-refusal';
    case GuardianConsent = 'guardian-consent';

    public function label(): string
    {
        return match ($this) {
            self::Terms => '이용약관',
            self::Privacy => '개인정보 처리방침',
            self::Copyright => '저작권 정책',
            self::EmailCollectionRefusal => '이메일 수집 거부',
            self::GuardianConsent => '법정대리인 동의서',
        };
    }

    public function isGuardianOnly(): bool
    {
        return $this === self::GuardianConsent;
    }

    public function publicPath(): string
    {
        return match ($this) {
            self::Terms => '/terms',
            self::Privacy => '/privacy',
            self::Copyright => '/copyright',
            self::EmailCollectionRefusal => '/email-collection-refusal',
            self::GuardianConsent => '/guardian-consent-policy',
        };
    }
}
