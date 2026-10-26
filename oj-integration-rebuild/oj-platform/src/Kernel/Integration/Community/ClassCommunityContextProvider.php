<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Community;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use Throwable;

final class ClassCommunityContextProvider implements CommunityContextProvider
{
    public function __construct(private readonly ClassService $classes)
    {
    }

    public function type(): string
    {
        return 'class';
    }

    public function resolve(int $targetId, int $userId): ?CommunityContextAccess
    {
        try {
            $class = $this->classes->findVisible($targetId, $userId);
            $participant = in_array(
                $userId,
                $this->classes->participantUserIds($targetId, 'active'),
                true
            );
            $instructor = in_array(
                $userId,
                $this->classes->instructorUserIds($targetId),
                true
            );
            if (
                (! $participant && ! $instructor)
                || ! $class->isEnabled()
                || $class->isDeleted()
            ) {
                return null;
            }

            return new CommunityContextAccess(
                new CommunityContext('class', $targetId, '수업 · ' . $class->name()),
                true,
                true,
                $instructor
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function choices(int $userId, int $limit = 200): array
    {
        $items = [];
        foreach ($this->classes->visibleFor($userId) as $class) {
            $access = $this->resolve($class->id(), $userId);
            if ($access !== null) {
                $items[] = $access;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}
