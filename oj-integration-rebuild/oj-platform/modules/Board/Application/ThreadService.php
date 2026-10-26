<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContentSanitizer;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostDraft;
use DomainException;
use Throwable;

final class ThreadService
{
    public function __construct(
        private readonly CommunityRepository $posts,
        private readonly CommunityContentSanitizer $sanitizer,
        private readonly CommunityContextRegistry $contexts,
        private readonly CommunityAccessPolicy $access,
        private readonly BoardSettingsService $settings,
        private readonly AttachmentService $attachments
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     */
    public function create(
        int $actorUserId,
        string $title,
        string $body,
        string $contextType,
        int $contextId,
        array $files,
        bool $secret = false
    ): int {
        $context = $this->contexts->resolve(
            $contextType,
            $contextId,
            $actorUserId
        );
        if (
            $context->isProtected()
            || $context->isOrphaned()
            || (! $context->canPost() && ! $context->canModerate())
        ) {
            throw new DomainException('이 공간에 게시글을 작성할 수 없습니다.');
        }

        $this->attachments->validatePostFiles($files);
        $draft = new CommunityPostDraft(
            'board',
            $actorUserId,
            $this->sanitizer->title($title),
            $this->sanitizer->body($body),
            'publish',
            $context->context(),
            false,
            false,
            $this->settings->current()->boardComments(),
            null,
            null,
            $secret
        );
        $postId = $this->posts->createPost($draft);
        try {
            $this->attachments->storePostFiles($files, $postId, $actorUserId);
        } catch (Throwable $error) {
            $this->posts->trashPost(
                $postId,
                $actorUserId,
                '첨부파일 저장 실패로 작성 취소'
            );
            throw $error;
        }

        return $postId;
    }

    /**
     * @param list<UploadedFile> $files
     * @param list<int> $removeAttachmentIds
     */
    public function update(
        int $actorUserId,
        int $postId,
        string $title,
        string $body,
        array $files,
        array $removeAttachmentIds,
        bool $management = false,
        ?string $contextType = null,
        ?int $contextId = null,
        ?bool $secret = null
    ): void {
        $post = $this->requireThread($postId);
        if ($post->isTrashed()) {
            throw new DomainException('휴지통의 게시글은 복원한 뒤 수정해 주세요.');
        }
        $allowed = $management
            ? $this->access->canModerateThread($actorUserId, $post)
            : $this->access->canEditThread($actorUserId, $post);
        if (! $allowed) {
            throw new DomainException('게시글을 수정할 권한이 없습니다.');
        }

        $context = $post->context();
        if ($management && $contextType !== null && $contextId !== null) {
            $target = $this->contexts->resolve(
                $contextType,
                $contextId,
                $actorUserId
            );
            if (
                $target->isProtected()
                || $target->isOrphaned()
                || (! $target->canPost() && ! $target->canModerate())
            ) {
                throw new DomainException('변경할 연결 공간에 접근할 수 없습니다.');
            }
            $context = $target->context();
        }

        $existing = $this->attachments->postAttachments($postId);
        $remove = array_fill_keys($removeAttachmentIds, true);
        $remaining = count(array_filter(
            $existing,
            static fn ($attachment): bool => ! isset($remove[$attachment->id()])
        ));
        $this->attachments->validatePostFiles($files, $remaining);
        $draft = new CommunityPostDraft(
            'board',
            $post->authorUserId(),
            $this->sanitizer->title($title),
            $this->sanitizer->body($body),
            'publish',
            $context,
            $post->isPinned(),
            false,
            $post->commentsAllowed(),
            null,
            null,
            $secret ?? $post->isSecret()
        );
        $this->posts->updatePost($postId, $draft);
        $this->attachments->storePostFiles($files, $postId, $actorUserId);
        $this->attachments->detachPostFiles(
            $removeAttachmentIds,
            $postId,
            $actorUserId
        );
    }

    private function requireThread(int $postId): CommunityPost
    {
        $post = $this->posts->findPost($postId);
        if ($post === null || $post->kind() !== 'board') {
            throw new DomainException('게시글을 찾을 수 없습니다.');
        }

        return $post;
    }
}
