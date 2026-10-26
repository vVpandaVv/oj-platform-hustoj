<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;

final class CommunityQueryService
{
    private const SCAN_SIZE = 100;

    public function __construct(
        private readonly CommunityRepository $posts,
        private readonly ProtectedAttachmentStorage $attachments,
        private readonly CommunityContextRegistry $contexts,
        private readonly CommunityAccessPolicy $access,
        private readonly WordPressUserGateway $users
    ) {
    }

    public function listing(
        int $userId,
        CommunityPostSearch $search,
        int $page,
        int $pageSize,
        bool $management = false,
        bool $countTotal = true
    ): CommunityListing {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $wantedOffset = ($page - 1) * $pageSize;
        $candidateOffset = 0;
        $visibleIndex = 0;
        $selected = [];
        $selectedCounts = [];

        do {
            $batch = $this->posts->search(
                $search,
                self::SCAN_SIZE,
                $candidateOffset
            );
            if ($batch === []) {
                break;
            }
            $candidateOffset += count($batch);
            $counts = $this->posts->commentCounts(array_map(
                static fn (CommunityPost $post): int => $post->id(),
                $batch
            ));

            foreach ($batch as $post) {
                $context = $this->contexts->resolve(
                    $post->context()->type(),
                    $post->context()->targetId(),
                    $userId
                );
                $count = $counts[$post->id()] ?? 0;
                if (
                    ! $this->matchesAccess($userId, $post, $management)
                    || ($search->orphanedOnly() && ! $context->isOrphaned())
                    || ! $this->matchesAnswerState($search->answerState(), $count)
                ) {
                    continue;
                }

                if (
                    $visibleIndex >= $wantedOffset
                    && count($selected) < $pageSize
                ) {
                    $selected[] = [$post, $context->isOrphaned()];
                    $selectedCounts[$post->id()] = $count;
                }
                $visibleIndex++;
            }
            if (! $countTotal && count($selected) >= $pageSize) {
                break;
            }
        } while (count($batch) === self::SCAN_SIZE);

        $names = $this->userNames(array_map(
            static fn (array $item): int => $item[0]->authorUserId(),
            $selected
        ));
        $items = [];
        foreach ($selected as [$post, $orphaned]) {
            $items[] = new CommunityListItem(
                $post,
                $names[$post->authorUserId()] ?? '사용자',
                $selectedCounts[$post->id()] ?? 0,
                $orphaned
            );
        }

        return new CommunityListing($items, $visibleIndex, $page, $pageSize);
    }

    public function detail(
        int $userId,
        int $postId,
        bool $management = false
    ): ?CommunityPostView {
        $post = $this->posts->findPost($postId);
        if ($post === null) {
            return null;
        }

        $bodyVisible = $this->access->canViewBody($userId, $post);
        $canModerate = $post->kind() === 'board'
            ? $this->access->canModerateThread($userId, $post)
            : $this->access->canManageNotice($userId);
        $stateVisible = $post->kind() === 'board'
            ? $this->access->canSeeThreadState($userId, $post)
            : $this->access->canSeeNoticeState($userId, $post);
        if (
            ! $stateVisible
            || ($management && ! $canModerate)
        ) {
            return null;
        }

        $comments = $bodyVisible || $canModerate
            ? $this->posts->comments($postId, $canModerate)
            : [];
        $userIds = [$post->authorUserId()];
        foreach ($comments as $comment) {
            $userIds[] = $comment->authorUserId();
        }
        $names = $this->userNames($userIds);
        $commentViews = [];
        foreach ($comments as $comment) {
            $commentViews[] = new CommunityCommentView(
                $comment,
                $names[$comment->authorUserId()] ?? '사용자',
                $this->attachments->forComment($comment->id())
            );
        }

        return new CommunityPostView(
            $post,
            $names[$post->authorUserId()] ?? '사용자',
            $commentViews,
            $bodyVisible || $canModerate
                ? $this->attachments->forPost($postId)
                : [],
            $bodyVisible || $canModerate,
            $post->kind() === 'board'
                ? $this->access->canEditThread($userId, $post)
                : $this->access->canManageNotice($userId),
            $this->access->canComment($userId, $post),
            $canModerate
        );
    }

    private function matchesAccess(
        int $userId,
        CommunityPost $post,
        bool $management
    ): bool {
        if ($post->kind() === 'notice') {
            return $management
                ? $this->access->canManageNotice($userId)
                : $this->access->canViewNotice($userId, $post);
        }

        return $management
            ? $this->access->canModerateThread($userId, $post)
            : $this->access->canViewThread($userId, $post);
    }

    private function matchesAnswerState(string $state, int $commentCount): bool
    {
        return match ($state) {
            'answered' => $commentCount > 0,
            'unanswered' => $commentCount === 0,
            default => true,
        };
    }

    /**
     * @param list<int> $userIds
     * @return array<int, string>
     */
    private function userNames(array $userIds): array
    {
        $names = [];
        $userIds = array_values(array_unique(array_filter(
            $userIds,
            static fn (int $userId): bool => $userId > 0
        )));
        foreach ($this->users->findMany($userIds) as $user) {
            $names[$user->userId()] = $user->displayName();
        }

        return $names;
    }
}
