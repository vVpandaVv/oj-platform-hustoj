<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityComment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use DomainException;

final class ModerationService
{
    public function __construct(
        private readonly CommunityRepository $posts,
        private readonly CommunityAccessPolicy $access
    ) {
    }

    public function setThreadHidden(
        int $actorUserId,
        int $postId,
        bool $hidden,
        string $reason
    ): void {
        $post = $this->managedThread($actorUserId, $postId);
        $this->posts->setPostHidden(
            $post->id(),
            $hidden,
            $actorUserId,
            $this->reason($reason)
        );
    }

    public function setThreadPinned(
        int $actorUserId,
        int $postId,
        bool $pinned
    ): void {
        $post = $this->managedThread($actorUserId, $postId);
        $this->posts->setPostPinned($post->id(), $pinned);
    }

    public function trashPost(
        int $actorUserId,
        int $postId,
        string $expectedKind,
        string $reason
    ): void {
        $post = $this->managedPost($actorUserId, $postId, $expectedKind);
        $this->posts->trashPost(
            $post->id(),
            $actorUserId,
            $this->reason($reason)
        );
    }

    public function restorePost(
        int $actorUserId,
        int $postId,
        string $expectedKind
    ): void {
        $post = $this->managedPost($actorUserId, $postId, $expectedKind);
        $this->posts->restorePost($post->id(), $actorUserId);
    }

    public function setCommentHidden(
        int $actorUserId,
        int $commentId,
        string $expectedKind,
        bool $hidden,
        string $reason
    ): void {
        $comment = $this->managedComment(
            $actorUserId,
            $commentId,
            $expectedKind
        );
        $this->posts->setCommentHidden(
            $comment->id(),
            $hidden,
            $actorUserId,
            $this->reason($reason)
        );
    }

    public function setCommentTrashed(
        int $actorUserId,
        int $commentId,
        string $expectedKind,
        bool $trashed,
        string $reason
    ): void {
        $comment = $this->managedComment(
            $actorUserId,
            $commentId,
            $expectedKind
        );
        if ($trashed) {
            $this->posts->trashComment(
                $comment->id(),
                $actorUserId,
                $this->reason($reason)
            );
            return;
        }
        $this->posts->restoreComment($comment->id(), $actorUserId);
    }

    private function managedThread(int $userId, int $postId): CommunityPost
    {
        return $this->managedPost($userId, $postId, 'board');
    }

    private function managedPost(
        int $userId,
        int $postId,
        string $expectedKind
    ): CommunityPost {
        $post = $this->posts->findPost($postId);
        if ($post === null || $post->kind() !== $expectedKind) {
            throw new DomainException('운영할 글을 찾을 수 없습니다.');
        }
        $allowed = $expectedKind === 'board'
            ? $this->access->canModerateThread($userId, $post)
            : $this->access->canManageNotice($userId);
        if (! $allowed) {
            throw new DomainException('이 글을 운영할 권한이 없습니다.');
        }

        return $post;
    }

    private function managedComment(
        int $userId,
        int $commentId,
        string $expectedKind
    ): CommunityComment {
        $comment = $this->posts->findComment($commentId);
        if ($comment === null) {
            throw new DomainException('운영할 댓글을 찾을 수 없습니다.');
        }
        $this->managedPost($userId, $comment->postId(), $expectedKind);

        return $comment;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        return $reason === '' ? '운영 정책에 따른 처리' : $reason;
    }
}
