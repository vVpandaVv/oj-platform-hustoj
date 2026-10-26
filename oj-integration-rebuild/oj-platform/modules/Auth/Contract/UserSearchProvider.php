<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface UserSearchProvider
{
    /**
     * 로그인 아이디·이메일·표시 이름을 대상으로 검색합니다.
     *
     * @return list<int>
     */
    public function searchUserIds(string $keyword, int $limit = 500): array;
}
