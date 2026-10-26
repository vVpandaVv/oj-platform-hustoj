<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunitySettings;
use CodingPanda\OjPlatform\Modules\Board\Domain\ProtectedAttachmentIntegrityReport;

interface ProtectedAttachmentStorage
{
    /**
     * @param list<UploadedFile> $files
     */
    public function validateMany(
        array $files,
        CommunitySettings $settings,
        int $maximumCount
    ): void;

    public function store(
        UploadedFile $file,
        int $postId,
        ?int $commentId,
        int $actorUserId,
        CommunitySettings $settings
    ): CommunityAttachment;

    /**
     * @return list<CommunityAttachment>
     */
    public function forPost(int $postId): array;

    /**
     * @return list<CommunityAttachment>
     */
    public function forComment(int $commentId): array;

    public function find(int $attachmentId): ?CommunityAttachment;

    public function read(CommunityAttachment $attachment): string;

    public function detach(int $attachmentId, int $actorUserId): void;

    public function integrityReport(
        int $limit = 5000
    ): ProtectedAttachmentIntegrityReport;
}
