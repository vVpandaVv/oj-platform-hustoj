<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class CommunityContext
{
    private const TYPES = ['global', 'problem', 'class', 'contest', 'group'];

    public function __construct(
        private readonly string $type,
        private readonly int $targetId,
        private readonly string $label
    ) {
        if (! in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException('지원하지 않는 커뮤니티 문맥입니다.');
        }
        if (
            ($this->type === 'global' && $this->targetId !== 0)
            || ($this->type !== 'global' && $this->targetId < 1)
        ) {
            throw new InvalidArgumentException('커뮤니티 문맥 대상이 올바르지 않습니다.');
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException('커뮤니티 문맥 이름이 필요합니다.');
        }
    }

    public static function global(): self
    {
        return new self('global', 0, '전체 게시판');
    }

    public function type(): string
    {
        return $this->type;
    }

    public function targetId(): int
    {
        return $this->targetId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function key(): string
    {
        return $this->type . ':' . $this->targetId;
    }
}
