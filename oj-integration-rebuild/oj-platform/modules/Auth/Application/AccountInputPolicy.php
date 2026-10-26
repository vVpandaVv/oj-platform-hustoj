<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use DomainException;

final class AccountInputPolicy
{
    public function __construct(
        private readonly Clock $clock = new SystemClock()
    ) {
    }

    public function userLogin(string $value): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 48
            || preg_match('/^[A-Za-z0-9._@-]+$/D', $value) !== 1
        ) {
            throw new DomainException(
                '로그인 아이디는 영문, 숫자, 마침표, 밑줄, 하이픈, @를 사용해 48자 이하로 입력해야 합니다.'
            );
        }

        return $value;
    }

    public function email(string $value): string
    {
        $value = trim($value);
        if (
            $value === ''
            || $this->length($value) > 100
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new DomainException('사용할 수 있는 이메일 주소를 입력해야 합니다.');
        }

        return $value;
    }

    public function displayName(string $value): string
    {
        return $this->plainText($value, 250, '표시 이름');
    }

    public function nickname(string $value): string
    {
        return $this->plainText($value, 100, '학생 이름');
    }

    public function password(string $value, string $label = '비밀번호'): string
    {
        if (strlen($value) < 12 || strlen($value) > 4096) {
            throw new DomainException($label . '는 12자 이상이어야 합니다.');
        }

        return $value;
    }

    public function affiliation(?string $value): ?string
    {
        return $this->nullableText($value, 191, '소속');
    }

    public function bio(?string $value): ?string
    {
        return $this->nullableText($value, 1000, '소개');
    }

    public function codeTemplate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (strlen($value) > 100000) {
            throw new DomainException('코드 템플릿은 100KB 이하로 입력해야 합니다.');
        }

        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    public function birthDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new DomainException('생년월일은 YYYY-MM-DD 형식으로 입력해야 합니다.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (
            ! checkdate($month, $day, $year)
            || $value > $this->clock->now()->format('Y-m-d')
        ) {
            throw new DomainException('생년월일을 실제 날짜로 입력해야 합니다.');
        }

        return $value;
    }

    private function plainText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if (
            $value === ''
            || $this->length($value) > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new DomainException($label . '을(를) ' . $maxLength . '자 이하로 입력해야 합니다.');
        }

        return $value;
    }

    private function nullableText(?string $value, int $maxLength, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (
            $this->length($value) > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new DomainException($label . '은(는) ' . $maxLength . '자 이하로 입력해야 합니다.');
        }

        return $value;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
