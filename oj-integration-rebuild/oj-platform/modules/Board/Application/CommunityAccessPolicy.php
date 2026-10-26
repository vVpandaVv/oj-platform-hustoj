<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class CommunityAccessPolicy
{
    public function __construct(
        private readonly CommunityContextRegistry $contexts,
        private readonly BoardSettingsService $settings,
        private readonly Authorization $permissions,
        private readonly Clock $clock
    ) {
    }

    public function canViewThread(int $userId, CommunityPost $post): bool
    {
        if ($post->kind() === 'notice') {
            return $this->canViewNotice($userId, $post);
        }
        if ($post->isTrashed()) {
            return false;
        }

        $context = $this->contexts->resolve(
            $post->context()->type(),
            $post->context()->targetId(),
            $userId
        );

        return $context->canView()
            && ! $context->isProtected()
            && (! $post->isHidden() || $context->canModerate());
    }

    public function canViewBody(int $userId, CommunityPost $post): bool
    {
        if (! $this->canViewThread($userId, $post)) {
            return false;
        }
        if (
            $post->kind() === 'board'
            && $post->isSecret()
            && $post->authorUserId() !== $userId
            && ! $this->canModerateThread($userId, $post)
        ) {
            return false;
        }

        return true;
    }

    public function canSeeThreadState(int $userId, CommunityPost $post): bool
    {
        if ($post->kind() !== 'board') {
            return false;
        }

        return $this->canModerateThread($userId, $post)
            || $this->canViewThread($userId, $post)
            || (
                ! $post->isTrashed()
                && $post->authorUserId() === $userId
            );
    }

    public function canEditThread(int $userId, CommunityPost $post): bool
    {
        if ($post->kind() !== 'board' || $post->isTrashed()) {
            return false;
        }
        $context = $this->context($userId, $post);
        if ($context->isProtected()) {
            return false;
        }

        return $context->canModerate()
            || (
                $post->authorUserId() === $userId
                && ! $post->isHidden()
                && $context->canView()
            );
    }

    public function canModerateThread(int $userId, CommunityPost $post): bool
    {
        if ($post->kind() !== 'board') {
            return false;
        }
        $context = $this->context($userId, $post);

        return ! $context->isProtected() && $context->canModerate();
    }

    public function canComment(int $userId, CommunityPost $post): bool
    {
        if (
            $post->isTrashed()
            || ! $post->commentsAllowed()
            || ! $this->canViewBody($userId, $post)
        ) {
            return false;
        }
        if ($post->kind() === 'notice') {
            return $this->isCurrentNotice($post);
        }

        return $this->settings->current()->boardComments()
            && $this->context($userId, $post)->canPost();
    }

    public function canManageNotice(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_manage_notices');
    }

    public function canViewNotice(int $userId, CommunityPost $post): bool
    {
        if ($post->kind() !== 'notice') {
            return false;
        }
        if ($this->canManageNotice($userId)) {
            return true;
        }
        if ($post->isTrashed()) {
            return false;
        }

        return $this->isCurrentNotice($post);
    }

    public function canSeeNoticeState(int $userId, CommunityPost $post): bool
    {
        if ($post->kind() !== 'notice') {
            return false;
        }
        if ($this->canManageNotice($userId)) {
            return true;
        }
        if ($post->isTrashed()) {
            return false;
        }
        if ($this->isCurrentNotice($post)) {
            return true;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $post->status() === 'publish'
            && $post->endsAt() !== null
            && $post->endsAt() < $now;
    }

    public function isCurrentNotice(CommunityPost $post): bool
    {
        if ($post->kind() !== 'notice' || $post->status() !== 'publish') {
            return false;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($post->publishAt() !== null && $post->publishAt() > $now) {
            return false;
        }

        return $post->endsAt() === null || $post->endsAt() >= $now;
    }

    private function context(
        int $userId,
        CommunityPost $post
    ): \CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess {
        return $this->contexts->resolve(
            $post->context()->type(),
            $post->context()->targetId(),
            $userId
        );
    }
}
