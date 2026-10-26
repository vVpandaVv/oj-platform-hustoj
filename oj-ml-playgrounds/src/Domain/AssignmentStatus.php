<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

final class AssignmentStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const CLOSED = 'closed';
    public const ARCHIVED = 'archived';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::PUBLISHED, self::CLOSED, self::ARCHIVED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
