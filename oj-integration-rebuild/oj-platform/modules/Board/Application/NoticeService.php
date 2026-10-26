<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContentSanitizer;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostDraft;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;
use Throwable;

final class NoticeService
{
    public function __construct(
        private readonly CommunityRepository $posts,
        private readonly CommunityContentSanitizer $sanitizer,
        private readonly CommunityAccessPolicy $access,
        private readonly BoardSettingsService $settings,
        private readonly AttachmentService $attachments,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     */
    public function create(
        int $actorUserId,
        string $title,
        string $body,
        string $status,
        ?string $publishAt,
        ?string $endsAt,
        bool $important,
        bool $pinned,
        ?bool $commentsAllowed,
        array $files
    ): int {
        if (! $this->access->canManageNotice($actorUserId)) {
            throw new DomainException('공지를 작성할 권한이 없습니다.');
        }
        $this->attachments->validatePostFiles($files);
        $draft = $this->draft(
            $actorUserId,
            $title,
            $body,
            $status,
            $publishAt,
            $endsAt,
            $important,
            $pinned,
            $commentsAllowed
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
        string $status,
        ?string $publishAt,
        ?string $endsAt,
        bool $important,
        bool $pinned,
        bool $commentsAllowed,
        array $files,
        array $removeAttachmentIds
    ): void {
        if (! $this->access->canManageNotice($actorUserId)) {
            throw new DomainException('공지를 수정할 권한이 없습니다.');
        }
        $post = $this->requireNotice($postId);
        if ($post->isTrashed()) {
            throw new DomainException('휴지통의 공지는 복원한 뒤 수정해 주세요.');
        }
        $existing = $this->attachments->postAttachments($postId);
        $remove = array_fill_keys($removeAttachmentIds, true);
        $remaining = count(array_filter(
            $existing,
            static fn ($attachment): bool => ! isset($remove[$attachment->id()])
        ));
        $this->attachments->validatePostFiles($files, $remaining);
        $this->posts->updatePost($postId, $this->draft(
            $post->authorUserId(),
            $title,
            $body,
            $status,
            $publishAt,
            $endsAt,
            $important,
            $pinned,
            $commentsAllowed
        ));
        $this->attachments->storePostFiles($files, $postId, $actorUserId);
        $this->attachments->detachPostFiles(
            $removeAttachmentIds,
            $postId,
            $actorUserId
        );
    }

    private function draft(
        int $authorUserId,
        string $title,
        string $body,
        string $status,
        ?string $publishAt,
        ?string $endsAt,
        bool $important,
        bool $pinned,
        ?bool $commentsAllowed
    ): CommunityPostDraft {
        $publishAt = $this->date($publishAt);
        $endsAt = $this->date($endsAt);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($status === 'future') {
            if ($publishAt === null || $publishAt <= $now) {
                throw new DomainException('예약 공지의 게시 시작 시각을 현재 이후로 지정해 주세요.');
            }
        } elseif ($status === 'publish') {
            $publishAt ??= $now;
        }
        if (
            $endsAt !== null
            && $publishAt !== null
            && $endsAt <= $publishAt
        ) {
            throw new DomainException('공지 종료 시각은 게시 시작 이후여야 합니다.');
        }

        return new CommunityPostDraft(
            'notice',
            $authorUserId,
            $this->sanitizer->title($title),
            $this->sanitizer->body($body),
            $status,
            CommunityContext::global(),
            $pinned,
            $important,
            $commentsAllowed ?? $this->settings->current()->noticeCommentsDefault(),
            $publishAt,
            $endsAt
        );
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }
        if (
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1
            || strtotime($value) === false
        ) {
            throw new DomainException('공지 시각 형식이 올바르지 않습니다.');
        }

        return $value;
    }

    private function requireNotice(int $postId): CommunityPost
    {
        $post = $this->posts->findPost($postId);
        if ($post === null || $post->kind() !== 'notice') {
            throw new DomainException('공지를 찾을 수 없습니다.');
        }

        return $post;
    }
}
