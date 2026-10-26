<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianConsentNotifier;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianVerificationChallenge;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use RuntimeException;

final class WordPressGuardianConsentNotifier implements GuardianConsentNotifier
{
    public function __construct(
        private readonly SiteDateFormatter $dates = new SiteDateFormatter()
    ) {
    }

    public function send(
        string $childEmail,
        string $guardianName,
        string $guardianEmail,
        GuardianVerificationChallenge $challenge
    ): void {
        $consentUrl = home_url(
            '/guardian-consent/' . rawurlencode($challenge->token())
        );
        $expiresAt = $this->dates->format(
            $challenge->expiresAt()->format(DATE_ATOM)
        ) . ' (' . $this->dates->timezoneLabel() . ')';

        $guardianSent = wp_mail(
            $guardianEmail,
            '법정대리인 동의 요청',
            $guardianName . "님,\n\n"
            . "아래 주소에서 학습용 채점 시스템 가입 동의를 확인해 주세요.\n\n"
            . $consentUrl . "\n\n"
            . '확인 기한: ' . $expiresAt
        );
        $childSent = wp_mail(
            $childEmail,
            '법정대리인 동의 확인 코드',
            "법정대리인이 동의를 완료한 뒤 회원가입 화면에 아래 코드를 입력하세요.\n\n"
            . $challenge->code() . "\n\n"
            . '확인 기한: ' . $expiresAt
        );

        if (! $guardianSent || ! $childSent) {
            throw new RuntimeException('법정대리인 동의 안내를 보내지 못했습니다.');
        }
    }
}
