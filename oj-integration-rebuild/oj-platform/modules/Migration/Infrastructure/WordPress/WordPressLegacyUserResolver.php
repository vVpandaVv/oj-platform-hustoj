<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyUserResolver;
use DomainException;

final class WordPressLegacyUserResolver implements LegacyUserResolver
{
    public function resolve(
        ?int $wordpressUserId,
        ?string $loginId,
        bool $allowMissing = false
    ): ?int {
        if ($wordpressUserId !== null && $wordpressUserId > 0) {
            $user = function_exists('get_userdata')
                ? get_userdata($wordpressUserId)
                : null;
            if (is_object($user) && (int) ($user->ID ?? 0) === $wordpressUserId) {
                return $wordpressUserId;
            }
        }

        $loginId = $loginId === null ? '' : trim($loginId);
        if ($loginId !== '' && function_exists('get_user_by')) {
            $user = get_user_by('login', $loginId);
            $storedLogin = is_object($user) ? (string) ($user->user_login ?? '') : '';
            if ($storedLogin !== '' && hash_equals($storedLogin, $loginId)) {
                $resolved = (int) ($user->ID ?? 0);
                if ($resolved > 0) {
                    return $resolved;
                }
            }
        }

        if ($allowMissing) {
            return null;
        }

        throw new DomainException(
            'WordPress 사용자 번호와 로그인 아이디의 정확한 일치 항목을 찾지 못했습니다.'
        );
    }
}

