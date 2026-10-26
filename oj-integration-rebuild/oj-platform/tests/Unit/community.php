<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityPostView;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityCommentView;
use CodingPanda\OjPlatform\Modules\Board\Presentation\CommunityHtml;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Board\Application\AttachmentService;
use CodingPanda\OjPlatform\Modules\Board\Application\BoardSettingsService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommentService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityAccessPolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityContextRegistry;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityFeaturePolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Application\ModerationService;
use CodingPanda\OjPlatform\Modules\Board\Application\NoticeService;
use CodingPanda\OjPlatform\Modules\Board\Application\ThreadService;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContentSanitizer;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContextProvider;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentRootProvider;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityComment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContext;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityContextAccess;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPost;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostDraft;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunitySettings;
use CodingPanda\OjPlatform\Modules\Board\Domain\ProtectedAttachmentIntegrityReport;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\Storage\WordPressProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemDetailSectionRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeNoticeItem;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeNoticeProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomeNoticeProviderRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$communityAssertions = 0;

function communityAssert(bool $condition, string $message): void
{
    global $communityAssertions;
    ++$communityAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function communitySame(mixed $expected, mixed $actual, string $message): void
{
    communityAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function communityThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        communityAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

function communityInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        communityAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

communityInvalid(
    static fn (): CommunityAttachment => new CommunityAttachment(
        1,
        2,
        0,
        'answer.txt',
        'text/plain',
        10,
        'protected-1.txt'
    ),
    '댓글 첨부 번호는 양수이거나 null이어야 합니다.'
);
communityInvalid(
    static fn (): CommunityAttachment => new CommunityAttachment(
        1,
        2,
        null,
        "answer\n.txt",
        'text/plain',
        10,
        'protected-1.txt'
    ),
    '첨부 표시 이름에 제어 문자를 허용하면 안 됩니다.'
);
communityInvalid(
    static fn (): CommunityAttachment => new CommunityAttachment(
        1,
        2,
        null,
        'answer.txt',
        "text/plain\r\nx-test: injected",
        10,
        'protected-1.txt'
    ),
    '첨부 MIME 값에 응답 헤더 문자를 허용하면 안 됩니다.'
);
communityInvalid(
    static fn (): CommunityAttachment => new CommunityAttachment(
        1,
        2,
        null,
        'answer.txt',
        'text/plain',
        10,
        '../protected-1.txt'
    ),
    '첨부 저장 참조에 경로 구분자를 허용하면 안 됩니다.'
);
$communityWpAttachmentPosts = [];
$communityWpPostMeta = [];
$communityWpParents = [];
$communityWpComments = [];

if (! function_exists('get_posts')) {
    function get_posts(array $arguments = []): array
    {
        global $communityWpAttachmentPosts;
        unset($arguments);

        return $communityWpAttachmentPosts;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = false): mixed
    {
        global $communityWpPostMeta;
        unset($single);

        return $communityWpPostMeta[$postId][$key] ?? '';
    }
}

if (! function_exists('get_post')) {
    function get_post(int $postId): mixed
    {
        global $communityWpParents;

        return $communityWpParents[$postId] ?? null;
    }
}

if (! function_exists('get_comment')) {
    function get_comment(int $commentId): mixed
    {
        global $communityWpComments;

        return $communityWpComments[$commentId] ?? null;
    }
}

final class CommunityTestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-29 12:00:00');
    }
}

final class CommunityTestSettings implements SettingsManager
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $revisions = [];

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(
            $this->revisions[$key] ?? 0,
            $this->values[$key] ?? $default
        );
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        if (($this->revisions[$key] ?? 0) !== $expectedRevision) {
            throw new DomainException('설정 개정 번호가 일치하지 않습니다.');
        }
        $revision = $expectedRevision + 1;
        $this->values[$key] = $value;
        $this->revisions[$key] = $revision;

        return new SettingsSnapshot($revision, $value);
    }
}

final class CommunityTestFeatures implements FeatureFlags
{
    /** @param array<string, bool> $enabled */
    public function __construct(public array $enabled)
    {
    }

    public function isEnabled(string $featureKey): bool
    {
        return $this->enabled[$featureKey] ?? false;
    }
}

final class CommunityTestAuthorization implements Authorization
{
    /** @param array<int, list<string>> $capabilities */
    public function __construct(private readonly array $capabilities)
    {
    }

    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($context);

        return in_array(
            $capability,
            $this->capabilities[$userId] ?? [],
            true
        );
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $userId === 2;
    }
}

final class CommunityTestContextProvider implements CommunityContextProvider
{
    /** @var array<int, bool> */
    public array $protected = [200 => true];

    public function type(): string
    {
        return 'problem';
    }

    public function resolve(int $targetId, int $userId): ?CommunityContextAccess
    {
        if (! in_array($targetId, [100, 200, 300], true)) {
            return null;
        }
        $context = new CommunityContext(
            'problem',
            $targetId,
            '문제 ' . $targetId
        );
        if (($this->protected[$targetId] ?? false) && $userId !== 2) {
            return new CommunityContextAccess(
                $context,
                false,
                false,
                false,
                true
            );
        }

        return new CommunityContextAccess(
            $context,
            true,
            $targetId !== 300,
            $userId === 2
        );
    }

    public function choices(int $userId, int $limit = 200): array
    {
        $items = [];
        foreach ([100, 200, 300] as $targetId) {
            $access = $this->resolve($targetId, $userId);
            if ($access !== null) {
                $items[] = $access;
            }
        }

        return array_slice($items, 0, $limit);
    }
}

final class CommunityTestSanitizer implements CommunityContentSanitizer
{
    public function title(string $value): string
    {
        return trim(strip_tags($value));
    }

    public function body(string $value): string
    {
        return trim($value);
    }

    public function comment(string $value): string
    {
        return trim(strip_tags($value));
    }
}

final class CommunityTestRepository implements CommunityRepository
{
    /** @var array<int, CommunityPost> */
    public array $posts = [];

    /** @var array<int, CommunityComment> */
    public array $commentsById = [];

    /** @var array<string, true> */
    private array $commentHashes = [];

    private int $nextPostId = 1;
    private int $nextCommentId = 1;

    public function search(
        CommunityPostSearch $search,
        int $limit,
        int $offset
    ): array {
        $items = array_values(array_filter(
            $this->posts,
            static function (CommunityPost $post) use ($search): bool {
                if (
                    $post->kind() !== $search->kind()
                    || ! in_array($post->status(), $search->statuses(), true)
                ) {
                    return false;
                }
                if (
                    $search->contextType() !== null
                    && $post->context()->type() !== $search->contextType()
                ) {
                    return false;
                }
                if (
                    $search->contextId() !== null
                    && $post->context()->targetId() !== $search->contextId()
                ) {
                    return false;
                }

                return $search->keyword() === ''
                    || str_contains($post->title(), $search->keyword());
            }
        ));
        usort(
            $items,
            static function (CommunityPost $left, CommunityPost $right): int {
                $leftPriority = $left->isPinned()
                    ? 2
                    : ($left->isImportant() ? 1 : 0);
                $rightPriority = $right->isPinned()
                    ? 2
                    : ($right->isImportant() ? 1 : 0);

                return $rightPriority <=> $leftPriority
                    ?: $right->id() <=> $left->id();
            }
        );

        return array_slice($items, $offset, $limit);
    }

    public function findPost(int $postId): ?CommunityPost
    {
        return $this->posts[$postId] ?? null;
    }

    public function createPost(CommunityPostDraft $draft): int
    {
        $postId = $this->nextPostId++;
        $this->posts[$postId] = $this->fromDraft($postId, $draft);

        return $postId;
    }

    public function updatePost(int $postId, CommunityPostDraft $draft): void
    {
        $existing = $this->posts[$postId] ?? null;
        if ($existing === null) {
            throw new DomainException('게시글이 없습니다.');
        }
        $this->posts[$postId] = $this->fromDraft(
            $postId,
            $draft,
            $existing->isHidden()
        );
    }

    public function setPostHidden(
        int $postId,
        bool $hidden,
        int $actorUserId,
        string $reason
    ): void {
        unset($actorUserId, $reason);
        $this->posts[$postId] = $this->copyPost(
            $this->posts[$postId],
            hidden: $hidden
        );
    }

    public function setPostPinned(int $postId, bool $pinned): void
    {
        $this->posts[$postId] = $this->copyPost(
            $this->posts[$postId],
            pinned: $pinned
        );
    }

    public function trashPost(
        int $postId,
        int $actorUserId,
        string $reason
    ): void {
        unset($actorUserId, $reason);
        $this->posts[$postId] = $this->copyPost(
            $this->posts[$postId],
            status: 'trash'
        );
    }

    public function restorePost(int $postId, int $actorUserId): void
    {
        unset($actorUserId);
        $this->posts[$postId] = $this->copyPost(
            $this->posts[$postId],
            status: 'publish'
        );
    }

    public function commentCounts(array $postIds): array
    {
        $counts = array_fill_keys($postIds, 0);
        foreach ($this->commentsById as $comment) {
            if (
                isset($counts[$comment->postId()])
                && ! $comment->isHidden()
                && ! $comment->isTrashed()
            ) {
                ++$counts[$comment->postId()];
            }
        }

        return $counts;
    }

    public function comments(int $postId, bool $includeModerated = false): array
    {
        return array_values(array_filter(
            $this->commentsById,
            static fn (CommunityComment $comment): bool =>
                $comment->postId() === $postId
                && (
                    $includeModerated
                    || (! $comment->isHidden() && ! $comment->isTrashed())
                )
        ));
    }

    public function findComment(int $commentId): ?CommunityComment
    {
        return $this->commentsById[$commentId] ?? null;
    }

    public function createComment(
        int $postId,
        int $authorUserId,
        string $body,
        string $bodyHash
    ): int {
        $commentId = $this->nextCommentId++;
        $this->commentsById[$commentId] = new CommunityComment(
            $commentId,
            $postId,
            $authorUserId,
            $body,
            'approve',
            false,
            '2026-07-29 12:00:00'
        );
        $this->commentHashes[$postId . ':' . $authorUserId . ':' . $bodyHash] = true;

        return $commentId;
    }

    public function hasRecentDuplicateComment(
        int $postId,
        int $authorUserId,
        string $bodyHash,
        int $windowSeconds
    ): bool {
        unset($windowSeconds);

        return isset(
            $this->commentHashes[$postId . ':' . $authorUserId . ':' . $bodyHash]
        );
    }

    public function setCommentHidden(
        int $commentId,
        bool $hidden,
        int $actorUserId,
        string $reason
    ): void {
        unset($actorUserId, $reason);
        $comment = $this->commentsById[$commentId];
        $this->commentsById[$commentId] = new CommunityComment(
            $comment->id(),
            $comment->postId(),
            $comment->authorUserId(),
            $comment->body(),
            $comment->status(),
            $hidden,
            $comment->createdAt()
        );
    }

    public function trashComment(
        int $commentId,
        int $actorUserId,
        string $reason
    ): void {
        unset($actorUserId, $reason);
        $this->replaceCommentStatus($commentId, 'trash');
    }

    public function restoreComment(int $commentId, int $actorUserId): void
    {
        unset($actorUserId);
        $this->replaceCommentStatus($commentId, 'approve');
    }

    private function fromDraft(
        int $postId,
        CommunityPostDraft $draft,
        bool $hidden = false
    ): CommunityPost {
        return new CommunityPost(
            $postId,
            $draft->kind(),
            $draft->authorUserId(),
            $draft->title(),
            $draft->body(),
            $draft->status(),
            $draft->context(),
            $draft->isPinned(),
            $draft->isImportant(),
            $hidden,
            $draft->isSecret(),
            $draft->commentsAllowed(),
            $draft->publishAt(),
            $draft->endsAt(),
            '2026-07-29 12:00:00',
            '2026-07-29 12:00:00'
        );
    }

    private function copyPost(
        CommunityPost $post,
        ?bool $hidden = null,
        ?bool $pinned = null,
        ?string $status = null
    ): CommunityPost {
        return new CommunityPost(
            $post->id(),
            $post->kind(),
            $post->authorUserId(),
            $post->title(),
            $post->body(),
            $status ?? $post->status(),
            $post->context(),
            $pinned ?? $post->isPinned(),
            $post->isImportant(),
            $hidden ?? $post->isHidden(),
            $post->isSecret(),
            $post->commentsAllowed(),
            $post->publishAt(),
            $post->endsAt(),
            $post->createdAt(),
            '2026-07-29 12:05:00'
        );
    }

    private function replaceCommentStatus(int $commentId, string $status): void
    {
        $comment = $this->commentsById[$commentId];
        $this->commentsById[$commentId] = new CommunityComment(
            $comment->id(),
            $comment->postId(),
            $comment->authorUserId(),
            $comment->body(),
            $status,
            $comment->isHidden(),
            $comment->createdAt()
        );
    }
}

final class CommunityTestStorage implements ProtectedAttachmentStorage
{
    /** @var array<int, CommunityAttachment> */
    public array $attachments = [];

    private int $nextId = 1;
    private int $storeCalls = 0;

    public ?int $failOnStoreCall = null;

    public function validateMany(
        array $files,
        CommunitySettings $settings,
        int $maximumCount
    ): void {
        if (count($files) > $maximumCount) {
            throw new DomainException('첨부 가능한 파일 개수를 초과했습니다.');
        }
        foreach ($files as $file) {
            if (
                ! $file instanceof UploadedFile
                || ! $file->isSuccessful()
                || $file->size() > $settings->maxFileBytes()
            ) {
                throw new DomainException('첨부파일이 올바르지 않습니다.');
            }
        }
    }

    public function store(
        UploadedFile $file,
        int $postId,
        ?int $commentId,
        int $actorUserId,
        CommunitySettings $settings
    ): CommunityAttachment {
        unset($actorUserId, $settings);
        ++$this->storeCalls;
        if ($this->failOnStoreCall === $this->storeCalls) {
            throw new RuntimeException('테스트 첨부 저장 실패');
        }
        $id = $this->nextId++;
        $attachment = new CommunityAttachment(
            $id,
            $postId,
            $commentId,
            $file->clientName(),
            'text/plain',
            $file->size(),
            'protected-' . $id
        );
        $this->attachments[$id] = $attachment;

        return $attachment;
    }

    public function forPost(int $postId): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (CommunityAttachment $attachment): bool =>
                $attachment->postId() === $postId
                && $attachment->commentId() === null
        ));
    }

    public function forComment(int $commentId): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (CommunityAttachment $attachment): bool =>
                $attachment->commentId() === $commentId
        ));
    }

    public function find(int $attachmentId): ?CommunityAttachment
    {
        return $this->attachments[$attachmentId] ?? null;
    }

    public function read(CommunityAttachment $attachment): string
    {
        return 'protected-content-' . $attachment->id();
    }

    public function detach(int $attachmentId, int $actorUserId): void
    {
        unset($actorUserId);
        unset($this->attachments[$attachmentId]);
    }

    public function integrityReport(
        int $limit = 5000
    ): ProtectedAttachmentIntegrityReport {
        unset($limit);

        return new ProtectedAttachmentIntegrityReport(
            count($this->attachments),
            count($this->attachments),
            [],
            false
        );
    }
}

final class CommunityTestUsers implements WordPressUserGateway
{
    /** @var array<int, UserIdentity> */
    private array $users;

    public function __construct()
    {
        $this->users = [
            1 => new UserIdentity(1, 'learner', '', '학습자'),
            2 => new UserIdentity(2, 'manager', '', '운영자'),
            3 => new UserIdentity(3, 'other', '', '다른 학습자'),
        ];
    }

    public function currentUser(): ?UserIdentity { return $this->users[1]; }
    public function findById(int $userId): ?UserIdentity { return $this->users[$userId] ?? null; }
    public function findByLogin(string $userLogin): ?UserIdentity { return null; }
    public function findByEmail(string $email): ?UserIdentity { return null; }
    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity { return null; }
    public function establishSession(int $userId, bool $remember): void {}
    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        throw new LogicException('사용하지 않는 테스트 메서드입니다.');
    }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity
    {
        throw new LogicException('사용하지 않는 테스트 메서드입니다.');
    }
    public function setRoles(int $userId, array $roles): void {}
    public function setPassword(int $userId, string $password): void {}
    public function invalidateSessions(int $userId): void {}
    public function requestPasswordReset(string $userLogin): void {}
    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void {}
    public function deleteUser(int $userId): void {}

    public function findMany(array $userIds): array
    {
        return array_values(array_intersect_key(
            $this->users,
            array_fill_keys($userIds, true)
        ));
    }
}

$settingsManager = new CommunityTestSettings();
$settings = new BoardSettingsService($settingsManager);
$features = new CommunityTestFeatures([
    'board' => true,
    'notices' => true,
    'problems' => true,
    'classes' => true,
    'contests' => true,
    'groups' => true,
]);
$featurePolicy = new CommunityFeaturePolicy($features);
communityAssert(
    $featurePolicy->boardEnabled() && $featurePolicy->noticesEnabled(),
    '게시판과 공지 기능의 활성 상태를 독립적으로 읽어야 합니다.'
);
$features->enabled['board'] = false;
communityAssert(
    ! $featurePolicy->boardEnabled() && $featurePolicy->noticesEnabled(),
    '게시판을 꺼도 공지 기능은 유지되어야 합니다.'
);
communityThrows(
    static fn () => $featurePolicy->requireBoard(),
    '게시판 OFF 상태의 직접 작업을 차단해야 합니다.'
);
$features->enabled['notices'] = false;
communityThrows(
    static fn () => $featurePolicy->requireAny(),
    '게시판과 공지가 모두 OFF이면 설정 저장도 차단해야 합니다.'
);
$features->enabled['board'] = true;
$features->enabled['notices'] = true;
$homeNotices = new HomeNoticeProviderRegistry();
$homeNotices->register('broken', new class implements HomeNoticeProvider {
    public function current(int $userId, int $limit = 5): array
    {
        throw new RuntimeException('선택형 홈 공지 Provider 실패');
    }
});
$homeNotices->register('stable', new class implements HomeNoticeProvider {
    public function current(int $userId, int $limit = 5): array
    {
        unset($userId, $limit);

        return [
            new HomeNoticeItem(1, '최신 공지', '2026-07-29 11:00:00', false, false, '/notices/1'),
            new HomeNoticeItem(2, '중요 공지', '2026-07-28 11:00:00', false, true, '/notices/2'),
            new HomeNoticeItem(3, '고정 공지', '2026-07-27 11:00:00', true, false, '/notices/3'),
        ];
    }
});
communitySame(
    [3, 2, 1],
    array_map(
        static fn (HomeNoticeItem $item): int => $item->noticeId(),
        $homeNotices->current(1, 5)
    ),
    '홈 공지는 실패한 Provider를 격리하고 고정·중요·최신 순으로 정렬해야 합니다.'
);
$detailSections = new ProblemDetailSectionRegistry();
$detailSections->register(new class implements ProblemDetailSectionProvider {
    public function key(): string { return 'broken'; }
    public function provide(int $problemId, int $userId, int $limit = 5): ?ProblemDetailSection
    {
        throw new RuntimeException('선택형 문제 상세 Provider 실패');
    }
});
$detailSections->register(new class implements ProblemDetailSectionProvider {
    public function key(): string { return 'stable'; }
    public function provide(int $problemId, int $userId, int $limit = 5): ?ProblemDetailSection
    {
        unset($problemId, $userId, $limit);

        return new ProblemDetailSection(
            'stable',
            '질문',
            [],
            '질문하기',
            '/board/new',
            '전체 질문',
            '/board'
        );
    }
});
communitySame(
    ['stable'],
    array_map(
        static fn (ProblemDetailSection $section): string => $section->key(),
        $detailSections->sections(100, 1)
    ),
    '문제 상세는 실패한 선택형 Provider만 제외하고 나머지 Section을 유지해야 합니다.'
);
$authorization = new CommunityTestAuthorization([
    2 => ['oj_manage_board', 'oj_manage_notices', 'oj_manage_settings'],
]);
$contextProvider = new CommunityTestContextProvider();
$contexts = new CommunityContextRegistry(
    $settings,
    $features,
    $authorization
);
$contexts->register($contextProvider);
$contexts->register(new class implements CommunityContextProvider {
    public function type(): string { return 'class'; }
    public function resolve(int $targetId, int $userId): ?CommunityContextAccess
    {
        throw new RuntimeException('선택형 수업 문맥 Provider 실패');
    }
    public function choices(int $userId, int $limit = 200): array
    {
        throw new RuntimeException('선택형 수업 문맥 Provider 실패');
    }
});
communityAssert(
    $contexts->choices(1, 200, false) !== [],
    '실패한 선택 문맥 Provider를 제외하고 다른 게시판 문맥은 유지해야 합니다.'
);
$clock = new CommunityTestClock();
$access = new CommunityAccessPolicy(
    $contexts,
    $settings,
    $authorization,
    $clock
);
$repository = new CommunityTestRepository();
$storage = new CommunityTestStorage();
$attachments = new AttachmentService(
    $repository,
    $storage,
    $settings,
    $access
);
$sanitizer = new CommunityTestSanitizer();
$threads = new ThreadService(
    $repository,
    $sanitizer,
    $contexts,
    $access,
    $settings,
    $attachments
);
$notices = new NoticeService(
    $repository,
    $sanitizer,
    $access,
    $settings,
    $attachments,
    $clock
);
$comments = new CommentService(
    $repository,
    $sanitizer,
    $access,
    $attachments
);
$moderation = new ModerationService($repository, $access);
$queries = new CommunityQueryService(
    $repository,
    $storage,
    $contexts,
    $access,
    new CommunityTestUsers()
);

communityAssert(
    $contexts->resolve('global', 0, 1)->canPost(),
    '기본 전체 게시판에는 로그인 사용자가 글을 쓸 수 있어야 합니다.'
);
communityAssert(
    $contexts->resolve('global', 0, 2)->canModerate(),
    '게시판 운영자는 전체 게시판을 운영할 수 있어야 합니다.'
);
communityAssert(
    $contexts->resolve('problem', 100, 1)->canView(),
    '접근 가능한 문제 문맥을 열 수 있어야 합니다.'
);
communityAssert(
    $contexts->resolve('problem', 200, 1)->isProtected(),
    '진행 중 평가 문제 문맥은 참여자에게 보호되어야 합니다.'
);
communityAssert(
    $contexts->resolve('problem', 200, 2)->canModerate(),
    '담당 운영자는 보호 문맥을 운영할 수 있어야 합니다.'
);
communityAssert(
    $contexts->resolve('class', 999, 1)->isOrphaned(),
    'Provider가 없는 문맥은 연결 끊김 상태여야 합니다.'
);
communityAssert(
    $contexts->resolve('class', 999, 2)->canModerate(),
    '전체 게시판 운영자는 연결 끊긴 글을 정리할 수 있어야 합니다.'
);
communityThrows(
    static fn () => $contexts->register(new CommunityTestContextProvider()),
    '동일 문맥 Provider의 중복 등록을 막아야 합니다.'
);

$threadId = $threads->create(
    1,
    ' 첫 질문 ',
    '<p>질문 내용</p>',
    'problem',
    100,
    []
);
communitySame(1, $threadId, '첫 게시글 번호가 올바르지 않습니다.');
communitySame(
    '첫 질문',
    $repository->findPost($threadId)?->title(),
    '게시글 제목을 정규화해 저장해야 합니다.'
);
communitySame(
    'problem:100',
    $repository->findPost($threadId)?->context()->key(),
    '문제 연결 문맥을 저장해야 합니다.'
);
communityThrows(
    static fn () => $threads->create(
        1,
        '보호 질문',
        '내용',
        'problem',
        200,
        []
    ),
    '진행 중 평가의 문제 문맥에는 질문을 작성할 수 없어야 합니다.'
);
communityThrows(
    static fn () => $threads->create(
        1,
        '읽기 전용 질문',
        '내용',
        'problem',
        300,
        []
    ),
    '글쓰기 권한이 없는 문맥에는 질문을 작성할 수 없어야 합니다.'
);

$post = $repository->findPost($threadId);
communityAssert(
    $post !== null && $access->canEditThread(1, $post),
    '작성자는 자신의 게시글을 수정할 수 있어야 합니다.'
);
communityAssert(
    $post !== null && ! $access->canEditThread(3, $post),
    '다른 일반 사용자는 게시글을 수정할 수 없어야 합니다.'
);
$threads->update(1, $threadId, '수정 질문', '수정 내용', [], []);
communitySame(
    '수정 질문',
    $repository->findPost($threadId)?->title(),
    '작성자 수정 내용을 반영해야 합니다.'
);

$commentId = $comments->add(3, $threadId, 'board', ' 첫 답변 ');
communitySame(1, $commentId, '첫 댓글 번호가 올바르지 않습니다.');
communitySame(
    '첫 답변',
    $repository->findComment($commentId)?->body(),
    '댓글을 정규화해 저장해야 합니다.'
);
communityThrows(
    static fn () => $comments->add(3, $threadId, 'board', '첫   답변'),
    '같은 댓글의 단시간 중복 등록을 막아야 합니다.'
);

$moderation->setThreadHidden(2, $threadId, true, '운영 확인');
$hiddenPost = $repository->findPost($threadId);
communityAssert(
    $hiddenPost !== null && ! $access->canViewBody(1, $hiddenPost),
    '숨김 게시글 본문은 작성자에게도 공개하지 않아야 합니다.'
);
communityAssert(
    $hiddenPost !== null && $access->canSeeThreadState(1, $hiddenPost),
    '숨김 게시글의 작성자에게는 처리 상태를 보여야 합니다.'
);
communityAssert(
    $hiddenPost !== null && $access->canViewBody(2, $hiddenPost),
    '운영자는 숨김 게시글 본문을 확인할 수 있어야 합니다.'
);
$moderation->setThreadHidden(2, $threadId, false, '');
$moderation->setThreadPinned(2, $threadId, true);
communityAssert(
    $repository->findPost($threadId)?->isPinned() === true,
    '운영자의 상단 고정 처리를 반영해야 합니다.'
);

$file = new UploadedFile('answer.txt', 'test-upload', 12, UPLOAD_ERR_OK);
$integrityRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'oj-platform-community-'
    . bin2hex(random_bytes(6));
if (! mkdir($integrityRoot, 0700, true) && ! is_dir($integrityRoot)) {
    throw new RuntimeException('첨부 무결성 테스트 저장소를 만들지 못했습니다.');
}
file_put_contents($integrityRoot . DIRECTORY_SEPARATOR . 'linked.txt', 'linked');
file_put_contents($integrityRoot . DIRECTORY_SEPARATOR . 'linked-2.txt', 'linked');
file_put_contents($integrityRoot . DIRECTORY_SEPARATOR . 'orphan.bin', 'orphan');
$communityWpAttachmentPosts = [
    (object) ['ID' => 11, 'post_parent' => 100],
    (object) ['ID' => 12, 'post_parent' => 0],
    (object) ['ID' => 13, 'post_parent' => 100],
    (object) ['ID' => 14, 'post_parent' => 100],
];
$communityWpPostMeta = [
    11 => [
        '_oj_protected_path' => 'missing.txt',
        '_oj_attachment_detached' => '0',
        '_oj_attachment_comment_id' => '',
    ],
    12 => [
        '_oj_protected_path' => 'linked.txt',
        '_oj_attachment_detached' => '0',
        '_oj_attachment_comment_id' => '',
    ],
    13 => [
        '_oj_protected_path' => 'linked-2.txt',
        '_oj_attachment_detached' => '0',
        '_oj_attachment_comment_id' => '77',
    ],
    14 => [
        '_oj_protected_path' => 'linked-2.txt',
        '_oj_attachment_detached' => '0',
        '_oj_attachment_comment_id' => '',
    ],
];
$communityWpParents = [
    100 => (object) ['ID' => 100, 'post_type' => 'oj_board_post'],
];
$communityWpComments = [];
$integrityStorage = new WordPressProtectedAttachmentStorage(
    new class($integrityRoot) implements ProtectedAttachmentRootProvider {
        public function __construct(private readonly string $path)
        {
        }

        public function root(): string
        {
            return $this->path;
        }
    }
);
$integrityReport = $integrityStorage->integrityReport();
$integrityKinds = array_map(
    static fn ($issue): string => $issue->kind(),
    $integrityReport->issues()
);
sort($integrityKinds);
communitySame(
    [
        'comment_missing',
        'duplicate_reference',
        'file_missing',
        'file_orphaned',
        'parent_missing',
    ],
    $integrityKinds,
    '보호 첨부 무결성 보고서는 레코드·파일·부모·댓글 고아 상태를 구분해야 합니다.'
);
communitySame(4, $integrityReport->recordCount(), '보호 첨부 검사 레코드 수가 올바르지 않습니다.');
communitySame(3, $integrityReport->fileCount(), '보호 첨부 검사 파일 수가 올바르지 않습니다.');
foreach (scandir($integrityRoot) ?: [] as $entry) {
    $integrityEntryPath = $integrityRoot . DIRECTORY_SEPARATOR . $entry;
    if (is_file($integrityEntryPath)) {
        unlink($integrityEntryPath);
    }
}
rmdir($integrityRoot);
$communityWpAttachmentPosts = [];
$communityWpPostMeta = [];
$communityWpParents = [];
$communityWpComments = [];

$rollbackStorage = new CommunityTestStorage();
$rollbackStorage->failOnStoreCall = 2;
$rollbackAttachments = new AttachmentService(
    $repository,
    $rollbackStorage,
    $settings,
    $access
);
$storeFailed = false;
try {
    $rollbackAttachments->storePostFiles([$file, $file], 999, 1);
} catch (RuntimeException) {
    $storeFailed = true;
}
communityAssert(
    $storeFailed && $rollbackStorage->attachments === [],
    '여러 첨부 중 저장이 실패하면 먼저 저장된 파일 연결도 롤백해야 합니다.'
);
$fileThreadId = $threads->create(
    1,
    '첨부 질문',
    '첨부 내용',
    'problem',
    100,
    [$file]
);
$fileAttachment = $storage->forPost($fileThreadId)[0] ?? null;
communityAssert(
    $fileAttachment instanceof CommunityAttachment,
    '게시글 첨부파일을 보호 저장소에 연결해야 합니다.'
);
communitySame(
    'protected-content-' . $fileAttachment->id(),
    $attachments->download(1, $fileAttachment->id())->contents(),
    '접근 가능한 사용자는 보호 첨부파일을 내려받을 수 있어야 합니다.'
);
$contextProvider->protected[100] = true;
communityThrows(
    static fn () => $attachments->download(1, $fileAttachment->id()),
    '진행 중 평가가 시작되면 첨부파일 직접 주소도 차단해야 합니다.'
);
communitySame(
    'protected-content-' . $fileAttachment->id(),
    $attachments->download(2, $fileAttachment->id())->contents(),
    '담당 운영자는 보호된 첨부파일을 확인할 수 있어야 합니다.'
);
$contextProvider->protected[100] = false;

$noticeId = $notices->create(
    2,
    '현재 공지',
    '<p>공지 내용</p>',
    'publish',
    null,
    null,
    true,
    false,
    null,
    []
);
$currentNotice = $repository->findPost($noticeId);
communityAssert(
    $currentNotice !== null && $access->canViewNotice(1, $currentNotice),
    '게시 중인 공지를 로그인 사용자에게 보여야 합니다.'
);
communityAssert(
    $currentNotice !== null && ! $currentNotice->commentsAllowed(),
    '새 공지는 설정의 댓글 기본값을 따라야 합니다.'
);
communityThrows(
    static fn () => $comments->add(1, $noticeId, 'notice', '댓글'),
    '댓글이 닫힌 공지에는 댓글을 작성할 수 없어야 합니다.'
);
communityThrows(
    static fn () => $notices->create(
        1,
        '권한 없는 공지',
        '내용',
        'publish',
        null,
        null,
        false,
        false,
        false,
        []
    ),
    '공지 관리 권한이 없는 사용자의 공지 작성을 막아야 합니다.'
);
communityThrows(
    static fn () => $notices->create(
        2,
        '잘못된 예약',
        '내용',
        'future',
        '2026-07-29 11:00',
        null,
        false,
        false,
        false,
        []
    ),
    '과거 시각으로 예약 공지를 등록할 수 없어야 합니다.'
);

$endedNoticeId = $notices->create(
    2,
    '종료 공지',
    '종료 내용',
    'publish',
    '2026-07-28 09:00',
    '2026-07-28 18:00',
    false,
    false,
    false,
    []
);
$endedNotice = $repository->findPost($endedNoticeId);
communityAssert(
    $endedNotice !== null && ! $access->canViewNotice(1, $endedNotice),
    '게시 기간이 끝난 공지 본문은 일반 사용자에게 공개하지 않아야 합니다.'
);
communityAssert(
    $endedNotice !== null && $access->canSeeNoticeState(1, $endedNotice),
    '종료된 공지는 직접 접근 시 종료 상태를 안내할 수 있어야 합니다.'
);
communityAssert(
    $endedNotice !== null && $access->canViewNotice(2, $endedNotice),
    '공지 운영자는 종료된 공지 본문을 확인할 수 있어야 합니다.'
);

$listing = $queries->listing(
    1,
    new CommunityPostSearch('notice', '', null, null, 'all', ['publish']),
    1,
    20
);
communitySame(
    [$noticeId],
    array_map(
        static fn ($item): int => $item->post()->id(),
        $listing->items()
    ),
    '일반 공지 목록에는 현재 게시 중인 공지만 표시해야 합니다.'
);
communitySame(
    '운영자',
    $listing->items()[0]->authorName(),
    '작성자 표시 이름을 WordPress 사용자 번호로 조회해야 합니다.'
);

$moderation->trashPost(2, $threadId, 'board', '정리');
$trashedThread = $repository->findPost($threadId);
communityAssert(
    $trashedThread !== null && $trashedThread->isTrashed(),
    '게시글 휴지통 상태를 저장해야 합니다.'
);
communityAssert(
    $trashedThread !== null && $access->canSeeThreadState(2, $trashedThread),
    '운영자는 휴지통 게시글 상태를 확인할 수 있어야 합니다.'
);
communityAssert(
    $trashedThread !== null && ! $access->canComment(2, $trashedThread),
    '운영자도 휴지통 게시글에는 댓글을 작성할 수 없어야 합니다.'
);
communityThrows(
    static fn () => $threads->update(
        2,
        $threadId,
        '휴지통 수정',
        '수정하면 안 되는 내용',
        [],
        [],
        true,
        'problem',
        100
    ),
    '휴지통 게시글은 직접 수정할 수 없어야 합니다.'
);
$moderation->restorePost(2, $threadId, 'board');
communitySame(
    'publish',
    $repository->findPost($threadId)?->status(),
    '게시글 복원 상태를 반영해야 합니다.'
);

$moderation->setCommentHidden(2, $commentId, 'board', true, '정리');
communityAssert(
    $repository->findComment($commentId)?->isHidden() === true,
    '댓글 숨김 상태를 반영해야 합니다.'
);
$moderation->setCommentTrashed(2, $commentId, 'board', true, '정리');
communityAssert(
    $repository->findComment($commentId)?->isTrashed() === true,
    '댓글 휴지통 상태를 반영해야 합니다.'
);
$moderation->setCommentTrashed(2, $commentId, 'board', false, '');
communityAssert(
    $repository->findComment($commentId)?->isTrashed() === false,
    '댓글 복원 상태를 반영해야 합니다.'
);

$moderation->trashPost(2, $noticeId, 'notice', '정리');
$trashedNotice = $repository->findPost($noticeId);
communityAssert(
    $trashedNotice !== null && $access->canViewNotice(2, $trashedNotice),
    '공지 운영자는 휴지통 공지를 확인할 수 있어야 합니다.'
);
communityAssert(
    $queries->detail(2, $noticeId, true) !== null,
    '공지 관리 화면에서 휴지통 공지를 열 수 있어야 합니다.'
);
communityAssert(
    $trashedNotice !== null && ! $access->canComment(2, $trashedNotice),
    '공지 운영자도 휴지통 공지에는 댓글을 작성할 수 없어야 합니다.'
);
communityThrows(
    static fn () => $notices->update(
        2,
        $noticeId,
        '휴지통 공지 수정',
        '수정하면 안 되는 내용',
        'publish',
        null,
        null,
        false,
        false,
        false,
        [],
        []
    ),
    '휴지통 공지는 직접 수정할 수 없어야 합니다.'
);
$moderation->restorePost(2, $noticeId, 'notice');

$settings->save(
    ['pdf', 'txt'],
    true,
    true,
    true,
    false,
    true,
    false,
    true,
    true,
    false,
    2097152,
    2,
    1,
    0
);
communitySame(
    2,
    $settings->current()->maxPostFiles(),
    '저장한 게시글 첨부 개수 제한을 읽어야 합니다.'
);
communitySame(
    1,
    $settings->current()->maxCommentFiles(),
    '저장한 댓글 첨부 개수 제한을 읽어야 합니다.'
);
communityAssert(
    $settings->current()->noticeCommentsDefault(),
    '저장한 공지 댓글 기본값을 읽어야 합니다.'
);
communityThrows(
    static fn () => $attachments->validatePostFiles([$file, $file, $file]),
    '설정한 게시글 첨부 개수 제한을 서버에서도 적용해야 합니다.'
);

$settings->save(
    ['pdf', 'txt'],
    true,
    true,
    true,
    false,
    true,
    false,
    true,
    true,
    false,
    2097152,
    2,
    1,
    $settings->snapshot()->revision(),
    3,
    12
);
communitySame(
    3,
    $settings->current()->postRateLimit(),
    '저장한 10분당 게시글 작성 제한을 읽어야 합니다.'
);
communitySame(
    12,
    $settings->current()->commentRateLimit(),
    '저장한 10분당 댓글 작성 제한을 읽어야 합니다.'
);
communityInvalid(
    static fn () => new CommunitySettings(
        true,
        true,
        true,
        true,
        true,
        true,
        false,
        true,
        ['pdf'],
        false,
        2097152,
        1,
        1,
        6,
        20
    ),
    '10분당 게시글 작성 제한은 5건을 넘을 수 없어야 합니다.'
);
communityInvalid(
    static fn () => new CommunitySettings(
        true,
        true,
        true,
        true,
        true,
        true,
        false,
        true,
        ['pdf'],
        false,
        2097152,
        1,
        1,
        5,
        21
    ),
    '10분당 댓글 작성 제한은 20건을 넘을 수 없어야 합니다.'
);

$secretThreadId = $threads->create(
    1,
    '비밀 질문',
    '비밀 내용',
    'problem',
    100,
    [],
    true
);
$secretPost = $repository->findPost($secretThreadId);
communityAssert(
    $secretPost !== null && $secretPost->isSecret(),
    '비밀글 등록 여부를 저장해야 합니다.'
);
communityAssert(
    $secretPost !== null && $access->canViewBody(1, $secretPost),
    '작성자는 자신의 비밀글 본문을 볼 수 있어야 합니다.'
);
communityAssert(
    $secretPost !== null && ! $access->canViewBody(3, $secretPost),
    '작성자·운영자가 아닌 사용자는 비밀글 본문을 볼 수 없어야 합니다.'
);
communityAssert(
    $secretPost !== null && $access->canViewBody(2, $secretPost),
    '운영자는 비밀글 본문을 볼 수 있어야 합니다.'
);
communityAssert(
    $secretPost !== null && $access->canViewThread(3, $secretPost),
    '비밀글이라도 목록에서는 제목을 볼 수 있어야 합니다.'
);
communityAssert(
    $secretPost !== null && $access->canEditThread(1, $secretPost),
    '작성자는 자신의 비밀글을 계속 수정할 수 있어야 합니다.'
);
$secretListing = $queries->listing(
    3,
    new CommunityPostSearch('board', '', 'problem', 100, 'all', ['publish']),
    1,
    20
);
communityAssert(
    in_array(
        $secretThreadId,
        array_map(
            static fn ($item): int => $item->post()->id(),
            $secretListing->items()
        ),
        true
    ),
    '비밀글도 다른 사용자의 목록 조회 결과에 포함되어야 합니다.'
);
$secretView = $queries->detail(3, $secretThreadId);
communityAssert(
    $secretView !== null && ! $secretView->bodyVisible(),
    '비밀글은 당사자가 아닌 조회자에게 상세 화면에서 본문을 감춰야 합니다.'
);
communityInvalid(
    static fn () => new CommunityPostDraft(
        'notice',
        2,
        '공지 제목',
        '공지 내용',
        'publish',
        CommunityContext::global(),
        false,
        false,
        true,
        null,
        null,
        true
    ),
    '공지는 비밀글로 등록할 수 없어야 합니다.'
);

$nonProblemChoices = $contexts->choices(1, 200, true, ['problem']);
communityAssert(
    array_filter(
        $nonProblemChoices,
        static fn ($choice): bool => $choice->context()->type() === 'problem'
    ) === [],
    'excludeTypes로 문제 문맥을 제외할 수 있어야 합니다.'
);
$recentProblemChoices = $contexts->recentProblemChoices(1, 5);
communityAssert(
    $recentProblemChoices !== [] && array_filter(
        $recentProblemChoices,
        static fn ($choice): bool => $choice->context()->type() !== 'problem'
    ) === [],
    '최근 문제 문맥 조회는 문제 유형만 반환해야 합니다.'
);

final class CommunityTestActionUrls implements ActionUrlProvider
{
    public function actionUrl(): string
    {
        return '/wp-admin/admin-post.php';
    }

    public function ajaxUrl(): string
    {
        return '/wp-admin/admin-ajax.php';
    }
}

final class CommunityTestActionSecurity implements ActionSecurity
{
    public function hiddenFields(string $actionKey): string
    {
        unset($actionKey);

        return '';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        unset($actionKey, $request);

        return true;
    }
}

$authorLinkHtml = new CommunityHtml(
    new CommunityTestActionUrls(),
    new CommunityTestActionSecurity(),
    $settings
);
$authorLinkView = new CommunityPostView(
    $secretPost,
    '다른 학습자',
    [],
    [],
    true,
    false,
    false,
    false
);
communityAssert(
    str_contains($authorLinkHtml->detail($authorLinkView, ''), '/users/1'),
    '게시글 작성자 이름은 프로필로 이동하는 링크가 되어야 합니다.'
);

communityAssert(
    str_contains(
        $authorLinkHtml->postForm('oj_board_create_thread', '등록', null, '', 3),
        '<label class="ojp-toggle-row"><input type="checkbox" name="secret"'
    ),
    '게시글 작성 폼의 비밀글 체크박스는 ojp-toggle-row 스타일을 사용해야 합니다.'
);

// Load real formatting/KSES functions when an isolated WordPress checkout is available.
$wordpressRoot = getenv('OJ_TEST_WORDPRESS_ROOT');
if (is_string($wordpressRoot) && $wordpressRoot !== '') {
    define('ABSPATH', rtrim($wordpressRoot, '/\\') . '/');
    define('WPINC', 'wp-includes');
    foreach (['compat.php', 'compat-utf8.php', 'utf8.php', 'class-wp-token-map.php'] as $file) {
        $path = ABSPATH . WPINC . '/' . $file;
        if (is_file($path)) { require_once $path; }
    }
    foreach (['plugin.php', 'formatting.php', 'functions.php', 'kses.php'] as $file) {
        require_once ABSPATH . WPINC . '/' . $file;
    }
    add_filter('pre_option_blog_charset', static fn (): string => 'UTF-8');
    foreach (['html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php'] as $file) {
        $path = ABSPATH . WPINC . '/html-api/' . $file;
        if (is_file($path)) { require_once $path; }
    }
}

$lineBreakPost = static function (string $kind, string $body): CommunityPost {
    return new CommunityPost(
        900, $kind, 1, '줄바꿈 확인', $body, 'publish', CommunityContext::global(),
        false, false, false, false, true, null, null,
        '2026-10-13 10:00:00', '2026-10-13 10:00:00'
    );
};
$lineBreakView = static function (CommunityPost $post, array $comments = []): CommunityPostView {
    return new CommunityPostView($post, '작성자', $comments, [], true, true, false, false);
};
foreach (['board', 'notice'] as $kind) {
    foreach ([null, $lineBreakPost($kind, '<table><tbody><tr><td>Saved cell</td></tr></tbody></table>')] as $editingPost) {
        $form = $authorLinkHtml->postForm(
            $kind === 'board' ? 'oj_board_create_thread' : 'oj_notice_create',
            '등록', $editingPost, '', 0, [], $kind === 'notice'
        );
        communityAssert(str_contains($form, 'data-ojp-rich-text-editor'), 'Post and notice create/edit forms use the common rich editor.');
        communityAssert(str_contains($form, 'name="body"'), 'The rich editor submits the existing body field.');
        communityAssert(str_contains($form, 'data-required="true"'), 'Post bodies remain required.');
        if ($editingPost !== null) {
            $editorContent = (new \CodingPanda\OjPlatform\Ui\Editor\RichTextContent())->render($editingPost->body());
            communityAssert(str_contains($form, htmlspecialchars($editorContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'Editing preserves sanitized HTML in the editor source.');
        }
    }
    $storedBody = "첫 줄\r\n둘째 줄\r\n\r\n다음 문단";
    $post = $lineBreakPost($kind, $storedBody);
    $rendered = $authorLinkHtml->detail($lineBreakView($post), '');
    communityAssert(
        preg_match('/첫 줄<br\s*\/?>\s*둘째 줄/u', $rendered) === 1,
        '게시글과 공지의 Enter는 화면에서 줄바꿈으로 표시되어야 합니다.'
    );
    communitySame($storedBody, $post->body(), '화면 표시가 기존 저장 본문을 변경하면 안 됩니다.');
    if (function_exists('wpautop')) {
        communityAssert(
            str_contains($rendered, '<p>다음 문단</p>'),
            '빈 줄은 WordPress 문단으로 표시되어야 합니다.'
        );
    }
}
$comment = new CommunityComment(901, 900, 1, "댓글 첫 줄\n댓글 둘째 줄", 'approve', false, '2026-10-13 10:00:00');
$renderedComment = $authorLinkHtml->comments($lineBreakView(
    $lineBreakPost('board', '본문'),
    [new CommunityCommentView($comment, '댓글 작성자', [])]
));
communityAssert(
    preg_match('/댓글 첫 줄<br\s*\/?>\s*댓글 둘째 줄/u', $renderedComment) === 1,
    '댓글도 같은 본문 표시 경로에서 줄바꿈이 유지되어야 합니다.'
);
if (function_exists('wpautop') && function_exists('wp_kses_post')) {
    $tableBody = '<table><caption>Results</caption><thead><tr><th scope="col">Score</th></tr></thead><tbody><tr><td colspan="2" rowspan="2">42</td></tr></tbody></table>';
    $realSanitizer = new \CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityContentSanitizer();
    communitySame($tableBody, $realSanitizer->body($tableBody), 'Safe HTML tables and merged cells survive storage sanitization.');
    $formatted = '<h2>Heading</h2><p><span style="font-size: 20px; color: red;">Large text</span></p>';
    $formattedSaved = $realSanitizer->body($formatted);
    communityAssert(str_contains($formattedSaved, '<h2>Heading</h2>') && str_contains($formattedSaved, 'font-size: 20px'), 'Common heading and font-size formatting survives board storage.');
    $unsafeStyle = $realSanitizer->body('<p style="position: fixed; top: 0; font-size: 20px;" onclick="alert(1)">Text</p>');
    communityAssert(! str_contains($unsafeStyle, 'position:') && ! str_contains($unsafeStyle, 'onclick='), 'WordPress must filter unsafe styles and events from rich board content.');
    communityAssert(! str_contains($realSanitizer->comment($tableBody), '<table'), 'Comments retain their existing sanitization allowlist.');
    foreach (['board', 'notice'] as $kind) {
        $renderedTable = $authorLinkHtml->detail($lineBreakView($lineBreakPost($kind, $realSanitizer->body($tableBody))), '');
        communityAssert(str_contains((string) preg_replace('/>\s+</', '><', $renderedTable), $tableBody), 'Post and notice display preserves HTML table structure.');
    }
    $unsafeTable = '<table onclick="alert(1)"><tr><td onmouseover="alert(1)"><script>alert(1)</script><a href="javascript:alert(1)">Cell</a></td></tr></table>';
    $cleanTable = $realSanitizer->body($unsafeTable);
    foreach (['<script', 'onclick=', 'onmouseover=', 'javascript:'] as $forbidden) {
        communityAssert(! str_contains($cleanTable, $forbidden), 'Table support must not permit executable HTML.');
    }
    $htmlBody = "<p>기존 <strong>강조</strong><br>다음 줄</p>\n<pre><code>first()\nsecond()</code></pre>";
    $rendered = $authorLinkHtml->detail($lineBreakView($lineBreakPost('board', $htmlBody)), '');
    communityAssert(str_contains($rendered, '<strong>강조</strong>'), '기존 HTML 서식을 유지해야 합니다.');
    communityAssert(
        str_contains($rendered, "<pre><code>first()\nsecond()</code></pre>"),
        '코드 블록 내부에 문단이나 br을 추가하면 안 됩니다.'
    );
    communitySame(1, preg_match_all('/<br\s*\/?>/i', $rendered), '기존 br에 중복 줄바꿈을 추가하면 안 됩니다.');
    $unsafe = '<p onclick="alert(1)">안내</p><script>alert(1)</script><a href="javascript:alert(1)">링크</a>';
    $rendered = $authorLinkHtml->detail($lineBreakView($lineBreakPost('board', $unsafe)), '');
    foreach (['<script', 'onclick=', 'javascript:'] as $forbidden) {
        communityAssert(! str_contains($rendered, $forbidden), '본문 정화 후 문단 변환에서도 위험한 HTML을 제거해야 합니다.');
    }
}

fwrite(
    STDOUT,
    sprintf(
        "R-09 커뮤니티 단위 검사 통과: assertion %d개\n",
        $communityAssertions
    )
);
