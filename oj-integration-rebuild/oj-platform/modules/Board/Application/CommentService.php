<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContentSanitizer;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use DomainException;
use Throwable;

final class CommentService
{
    public function __construct(
        private readonly CommunityRepository $posts,
        private readonly CommunityContentSanitizer $sanitizer,
        private readonly CommunityAccessPolicy $access,
        private readonly AttachmentService $attachments
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     */
    public function add(
        int $actorUserId,
        int $postId,
        string $expectedKind,
        string $body,
        array $files = []
    ): int {
        $post = $this->posts->findPost($postId);
        if ($post === null || $post->kind() !== $expectedKind) {
            throw new DomainException('댓글을 작성할 글을 찾을 수 없습니다.');
        }
        if (! $this->access->canComment($actorUserId, $post)) {
            throw new DomainException('이 글에 댓글을 작성할 수 없습니다.');
        }
        if ($expectedKind === 'notice' && $files !== []) {
            throw new DomainException('공지 댓글에는 파일을 첨부할 수 없습니다.');
        }
        $body = $this->sanitizer->comment($body);
        $length = function_exists('mb_strlen')
            ? mb_strlen($body, 'UTF-8')
            : strlen($body);
        if ($length < 1 || $length > 5000) {
            throw new DomainException('댓글은 1자 이상 5,000자 이하로 입력해 주세요.');
        }
        $bodyHash = hash('sha256', preg_replace('/\s+/u', ' ', trim($body)) ?? $body);
        if ($this->posts->hasRecentDuplicateComment(
            $postId,
            $actorUserId,
            $bodyHash,
            60
        )) {
            throw new DomainException('같은 댓글을 잠시 뒤 다시 등록해 주세요.');
        }
        $this->attachments->validateCommentFiles($files);
        $commentId = $this->posts->createComment(
            $postId,
            $actorUserId,
            $body,
            $bodyHash
        );
        try {
            $this->attachments->storeCommentFiles(
                $files,
                $postId,
                $commentId,
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->posts->trashComment(
                $commentId,
                $actorUserId,
                '첨부파일 저장 실패로 댓글 작성 취소'
            );
            throw $error;
        }

        return $commentId;
    }
}
