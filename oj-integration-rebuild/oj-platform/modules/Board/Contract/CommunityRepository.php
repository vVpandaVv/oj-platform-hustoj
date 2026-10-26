<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Contract;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityComment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostDraft;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;

interface CommunityRepository
{
    /**
     * @return list<CommunityPost>
     */
    public function search(
        CommunityPostSearch $search,
        int $limit,
        int $offset
    ): array;

    public function findPost(int $postId): ?CommunityPost;

    public function createPost(CommunityPostDraft $draft): int;

    public function updatePost(int $postId, CommunityPostDraft $draft): void;

    public function setPostHidden(
        int $postId,
        bool $hidden,
        int $actorUserId,
        string $reason
    ): void;

    public function setPostPinned(int $postId, bool $pinned): void;

    public function trashPost(
        int $postId,
        int $actorUserId,
        string $reason
    ): void;

    public function restorePost(int $postId, int $actorUserId): void;

    /**
     * @param list<int> $postIds
     * @return array<int, int>
     */
    public function commentCounts(array $postIds): array;

    /**
     * @return list<CommunityComment>
     */
    public function comments(int $postId, bool $includeModerated = false): array;

    public function findComment(int $commentId): ?CommunityComment;

    public function createComment(
        int $postId,
        int $authorUserId,
        string $body,
        string $bodyHash
    ): int;

    public function hasRecentDuplicateComment(
        int $postId,
        int $authorUserId,
        string $bodyHash,
        int $windowSeconds
    ): bool;

    public function setCommentHidden(
        int $commentId,
        bool $hidden,
        int $actorUserId,
        string $reason
    ): void;

    public function trashComment(
        int $commentId,
        int $actorUserId,
        string $reason
    ): void;

    public function restoreComment(int $commentId, int $actorUserId): void;
}
