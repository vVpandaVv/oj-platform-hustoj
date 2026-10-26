<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use DomainException;
use Throwable;

final class AttachmentService
{
    public function __construct(
        private readonly CommunityRepository $posts,
        private readonly ProtectedAttachmentStorage $storage,
        private readonly BoardSettingsService $settings,
        private readonly CommunityAccessPolicy $access
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     */
    public function validatePostFiles(array $files, int $existingCount = 0): void
    {
        $settings = $this->settings->current();
        if (! $settings->attachments() && $files !== []) {
            throw new DomainException('현재 첨부파일을 추가할 수 없습니다.');
        }
        $remaining = max(0, $settings->maxPostFiles() - $existingCount);
        $this->storage->validateMany($files, $settings, $remaining);
    }

    /**
     * @param list<UploadedFile> $files
     */
    public function validateCommentFiles(array $files): void
    {
        $settings = $this->settings->current();
        if (! $settings->attachments() && $files !== []) {
            throw new DomainException('현재 첨부파일을 추가할 수 없습니다.');
        }
        $this->storage->validateMany(
            $files,
            $settings,
            $settings->maxCommentFiles()
        );
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<CommunityAttachment>
     */
    public function storePostFiles(
        array $files,
        int $postId,
        int $actorUserId
    ): array {
        return $this->storeMany($files, $postId, null, $actorUserId);
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<CommunityAttachment>
     */
    public function storeCommentFiles(
        array $files,
        int $postId,
        int $commentId,
        int $actorUserId
    ): array {
        return $this->storeMany(
            $files,
            $postId,
            $commentId,
            $actorUserId
        );
    }

    /**
     * @param list<int> $attachmentIds
     */
    public function detachPostFiles(
        array $attachmentIds,
        int $postId,
        int $actorUserId
    ): void {
        foreach (array_values(array_unique($attachmentIds)) as $attachmentId) {
            $attachment = $this->storage->find($attachmentId);
            if (
                $attachment === null
                || $attachment->postId() !== $postId
                || $attachment->commentId() !== null
            ) {
                throw new DomainException('제거할 첨부파일 관계가 올바르지 않습니다.');
            }
            $this->storage->detach($attachmentId, $actorUserId);
        }
    }

    /**
     * @return list<CommunityAttachment>
     */
    public function postAttachments(int $postId): array
    {
        return $this->storage->forPost($postId);
    }

    public function download(int $userId, int $attachmentId): ProtectedDownload
    {
        $attachment = $this->storage->find($attachmentId);
        if ($attachment === null) {
            throw new DomainException('첨부파일을 찾을 수 없습니다.');
        }
        $post = $this->posts->findPost($attachment->postId());
        if ($post === null) {
            throw new DomainException('첨부파일에 접근할 수 없습니다.');
        }
        $canModerate = $post->kind() === 'board'
            ? $this->access->canModerateThread($userId, $post)
            : $this->access->canManageNotice($userId);
        if (! $this->access->canViewBody($userId, $post) && ! $canModerate) {
            throw new DomainException('첨부파일에 접근할 수 없습니다.');
        }
        if ($attachment->commentId() !== null) {
            $comment = $this->posts->findComment($attachment->commentId());
            if (
                $comment === null
                || $comment->postId() !== $post->id()
                || (($comment->isHidden() || $comment->isTrashed()) && ! $canModerate)
            ) {
                throw new DomainException('첨부파일에 접근할 수 없습니다.');
            }
        }

        return new ProtectedDownload(
            $attachment,
            $this->storage->read($attachment)
        );
    }

    /**
     * @param list<UploadedFile> $files
     * @return list<CommunityAttachment>
     */
    private function storeMany(
        array $files,
        int $postId,
        ?int $commentId,
        int $actorUserId
    ): array {
        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->storage->store(
                    $file,
                    $postId,
                    $commentId,
                    $actorUserId,
                    $this->settings->current()
                );
            }
        } catch (Throwable $error) {
            foreach (array_reverse($stored) as $attachment) {
                $this->storage->detach($attachment->id(), $actorUserId);
            }
            throw $error;
        }

        return $stored;
    }
}
