<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

use InvalidArgumentException;

enum MigrationWave: string
{
    case Jobs = 'jobs';
    case Auth = 'auth';
    case Problems = 'problems';
    case Submissions = 'submissions';
    case Classes = 'classes';
    case Contests = 'contests';
    case Groups = 'groups';
    case AiFeedback = 'ai_feedback';
    case Messaging = 'messaging';
    case Research = 'research';
    case Settings = 'settings';

    public function order(): int
    {
        return match ($this) {
            self::Jobs => 0,
            self::Auth => 1,
            self::Problems => 2,
            self::Submissions => 3,
            self::Classes => 4,
            self::Contests => 5,
            self::Groups => 6,
            self::AiFeedback => 7,
            self::Messaging => 8,
            self::Research => 9,
            self::Settings => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Jobs => '공통 작업',
            self::Auth => '사용자 기반',
            self::Problems => '문제',
            self::Submissions => '제출',
            self::Classes => '수업',
            self::Contests => '대회·평가',
            self::Groups => '그룹',
            self::AiFeedback => 'AI 피드백',
            self::Messaging => '쪽지',
            self::Research => '수업 연구',
            self::Settings => '공통 운영',
        };
    }

    public function ownerModule(): string
    {
        return $this->value;
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Jobs => self::Auth,
            self::Auth => self::Problems,
            self::Problems => self::Submissions,
            self::Submissions => self::Classes,
            self::Classes => self::Contests,
            self::Contests => self::Groups,
            self::Groups => self::AiFeedback,
            self::AiFeedback => self::Messaging,
            self::Messaging => self::Research,
            self::Research => self::Settings,
            self::Settings => null,
        };
    }

    public static function fromKey(string $key): self
    {
        $wave = self::tryFrom(trim($key));
        if ($wave === null) {
            throw new InvalidArgumentException('등록되지 않은 이전 파동입니다.');
        }

        return $wave;
    }
}
