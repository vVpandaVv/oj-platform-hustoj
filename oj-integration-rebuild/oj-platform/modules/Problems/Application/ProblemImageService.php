<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemImageStore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage;
use DomainException;

/**
 * Accepts images for problem text (description, input, output, hint).
 */
final class ProblemImageService
{
    public function __construct(
        private readonly ProblemAccessPolicy $access,
        private readonly ProblemImageStore $store
    ) {
    }

    /**
     * @return array{url: string, width: int, height: int}
     */
    public function upload(int $actorUserId, ?ProblemId $problemId, string $bytes): array
    {
        $allowed = $problemId === null
            ? $this->access->canCreate($actorUserId)
            : $this->access->canEdit($actorUserId, $problemId);
        if (! $allowed) {
            throw new DomainException('이 문제에 이미지를 올릴 권한이 없습니다.');
        }
        $image = ProblemImage::fromBytes($bytes);

        return [
            'url' => $this->store->store($image),
            'width' => $image->width(),
            'height' => $image->height(),
        ];
    }
}
