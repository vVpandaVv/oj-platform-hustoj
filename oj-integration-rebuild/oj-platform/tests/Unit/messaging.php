<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessageReportService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingScopePolicy;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingSettingsService;
use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageReportRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\Message;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageDraft;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessageReport;
use CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingScope;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$messagingAssertions = 0;

function messagingAssert(bool $condition, string $message): void
{
    global $messagingAssertions;
    ++$messagingAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function messagingSame(mixed $expected, mixed $actual, string $message): void
{
    messagingAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function messagingThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        messagingAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

function messagingInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        messagingAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

final class MessagingTestSettings implements SettingsManager
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

    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
    {
        if (($this->revisions[$key] ?? 0) !== $expectedRevision) {
            throw new DomainException('설정 개정 번호가 일치하지 않습니다.');
        }
        $revision = $expectedRevision + 1;
        $this->values[$key] = $value;
        $this->revisions[$key] = $revision;

        return new SettingsSnapshot($revision, $value);
    }
}

final class MessagingTestFeatures implements FeatureFlags
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

final class MessagingTestGroupMembership implements GroupMembershipProvider
{
    /** @var array<int, list<int>> */
    public array $groupsByUser = [];

    public function activeGroupIdsForUser(int $userId): array
    {
        return $this->groupsByUser[$userId] ?? [];
    }

    public function activeUserIdsForGroups(array $groupIds): array
    {
        $users = [];
        foreach ($this->groupsByUser as $userId => $groups) {
            if (array_intersect($groups, $groupIds) !== []) {
                $users[] = $userId;
            }
        }

        return $users;
    }
}

final class MessagingTestProfiles implements UserProfileRepository
{
    /** @var array<int, UserProfile> */
    public array $profiles = [];

    public function find(int $userId): ?UserProfile
    {
        return $this->profiles[$userId] ?? null;
    }

    public function create(UserProfile $profile): void
    {
        $this->profiles[$profile->userId()] = $profile;
    }

    public function save(UserProfile $profile, ?int $changedByUserId = null): void
    {
        unset($changedByUserId);
        $this->profiles[$profile->userId()] = $profile;
    }

    public function recordLogin(int $userId): void
    {
        unset($userId);
    }

    public function saveRegistrationRequirements(int $userId, RegistrationRequirements $requirements): void
    {
        unset($userId, $requirements);
    }

    public function registrationRequirements(int $userId): ?RegistrationRequirements
    {
        unset($userId);

        return null;
    }

    public function findPage(int $limit, int $offset = 0): array
    {
        unset($limit, $offset);

        return [];
    }

    public function findByStatus(AccessStatus $status, int $limit, int $offset = 0): array
    {
        unset($status, $limit, $offset);

        return [];
    }

    public function findFiltered(?array $userIds, ?AccessStatus $status, int $limit, int $offset = 0): array
    {
        unset($userIds, $status, $limit, $offset);

        return [];
    }

    public function countFiltered(?array $userIds, ?AccessStatus $status): int
    {
        unset($userIds, $status);

        return 0;
    }
}

final class MessagingTestMessageRepository implements MessageRepository, \CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageInboxStatus
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    private int $nextId = 1;
    public string $lastAdminFrom = '';
    public string $lastAdminTo = '';

    public function save(MessageDraft $draft): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'thread_key' => $draft->threadKeyValue(),
            'sender_user_id' => $draft->senderUserId(),
            'recipient_user_id' => $draft->recipientUserId(),
            'body' => $draft->body(),
            'sent_at' => '2026-08-20 12:00:00',
            'read_at' => null,
            'sender_deleted_at' => null,
            'recipient_deleted_at' => null,
            'hidden_at' => null,
            'hidden_by_user_id' => null,
            'hidden_reason' => null,
        ];

        return $id;
    }

    public function find(int $id): ?Message
    {
        return isset($this->rows[$id]) ? Message::fromRow($this->rows[$id]) : null;
    }

    public function threadMessages(string $threadKey, int $viewerUserId, int $limit, int $offset): array
    {
        $items = array_values(array_filter(
            array_map(static fn (array $row): Message => Message::fromRow($row), $this->rows),
            static fn (Message $message): bool =>
                $message->threadKey() === $threadKey
                && $message->isParticipant($viewerUserId)
                && ! $message->isHidden()
                && ! $message->isDeletedFor($viewerUserId)
        ));

        return array_slice($items, $offset, $limit);
    }

    public function inboxThreads(int $userId, int $limit, int $offset): array
    {
        $latestByThread = [];
        foreach ($this->rows as $row) {
            $message = Message::fromRow($row);
            if ($message->isHidden() || ! $message->isParticipant($userId) || $message->isDeletedFor($userId)) {
                continue;
            }
            $existing = $latestByThread[$message->threadKey()] ?? null;
            if ($existing === null || $message->id() > $existing->id()) {
                $latestByThread[$message->threadKey()] = $message;
            }
        }
        $items = array_values($latestByThread);
        usort($items, static fn (Message $a, Message $b): int => $b->id() <=> $a->id());

        return array_slice($items, $offset, $limit);
    }

    public function sent(int $userId, int $limit, int $offset): array
    {
        $items = array_values(array_filter(
            array_map(static fn (array $row): Message => Message::fromRow($row), $this->rows),
            static fn (Message $message): bool =>
                $message->senderUserId() === $userId
                && ! $message->isHidden()
                && ! $message->isDeletedFor($userId)
        ));
        usort($items, static fn (Message $a, Message $b): int => $b->id() <=> $a->id());

        return array_slice($items, $offset, $limit);
    }

    public function unreadCount(int $userId): int
    {
        return count(array_filter(
            array_map(static fn (array $row): Message => Message::fromRow($row), $this->rows),
            static fn (Message $message): bool =>
                $message->recipientUserId() === $userId
                && ! $message->isHidden()
                && ! $message->isDeletedFor($userId)
                && ! $message->isRead()
        ));
    }

    public function unreadThreadKeys(int $userId, array $threadKeys): array
    {
        $keys = [];
        foreach ($this->rows as $row) {
            $message = Message::fromRow($row);
            if ($message->recipientUserId() === $userId && ! $message->isRead()
                && ! $message->isHidden() && ! $message->isDeletedFor($userId)
                && in_array($message->threadKey(), $threadKeys, true)) {
                $keys[$message->threadKey()] = true;
            }
        }
        return array_keys($keys);
    }

    public function unreadInboxThreads(int $userId, int $limit, int $offset): array
    {
        $latest = [];
        foreach ($this->rows as $row) {
            $message = Message::fromRow($row);
            if ($message->recipientUserId() === $userId && ! $message->isRead()
                && ! $message->isHidden() && ! $message->isDeletedFor($userId)) {
                $latest[$message->threadKey()] = $message;
            }
        }
        $messages = array_values($latest);
        usort($messages, static fn (Message $a, Message $b): int => $b->id() <=> $a->id());
        return array_slice($messages, $offset, $limit);
    }

    public function markThreadRead(string $threadKey, int $viewerUserId): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['thread_key'] === $threadKey && $row['recipient_user_id'] === $viewerUserId) {
                $this->rows[$id]['read_at'] = '2026-08-20 12:05:00';
            }
        }
    }

    public function softDeleteForSender(int $messageId): void
    {
        $this->rows[$messageId]['sender_deleted_at'] = '2026-08-20 12:10:00';
    }

    public function softDeleteForRecipient(int $messageId): void
    {
        $this->rows[$messageId]['recipient_deleted_at'] = '2026-08-20 12:10:00';
    }

    public function setHidden(int $messageId, bool $hidden, int $actorUserId, string $reason): void
    {
        $this->rows[$messageId]['hidden_at'] = $hidden ? '2026-08-20 12:15:00' : null;
        $this->rows[$messageId]['hidden_by_user_id'] = $hidden ? $actorUserId : null;
        $this->rows[$messageId]['hidden_reason'] = $hidden ? $reason : null;
    }

    public function adminSearch(int $userId, string $fromDate, string $toDate, int $limit, int $offset): array
    {
        $this->lastAdminFrom = $fromDate;
        $this->lastAdminTo = $toDate;
        $items = array_values(array_filter(
            array_map(static fn (array $row): Message => Message::fromRow($row), $this->rows),
            static fn (Message $message): bool => $userId === 0 || $message->isParticipant($userId)
        ));

        return array_slice($items, $offset, $limit);
    }

    public function adminThreadMessages(string $threadKey, int $limit, int $offset): array
    {
        $items = array_values(array_filter(
            array_map(static fn (array $row): Message => Message::fromRow($row), $this->rows),
            static fn (Message $message): bool => $message->threadKey() === $threadKey
        ));

        return array_slice($items, $offset, $limit);
    }
}

final class MessagingTestMessageReportRepository implements MessageReportRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    private int $nextId = 1;

    public function save(int $messageId, int $reporterUserId, string $reason): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'message_id' => $messageId,
            'reporter_user_id' => $reporterUserId,
            'reason' => $reason,
            'status' => 'pending',
            'reported_at' => '2026-08-20 12:00:00',
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_action' => null,
            'resolution_note' => null,
        ];

        return $id;
    }

    public function find(int $id): ?MessageReport
    {
        return isset($this->rows[$id]) ? MessageReport::fromRow($this->rows[$id]) : null;
    }

    public function existsForReporter(int $messageId, int $reporterUserId): bool
    {
        foreach ($this->rows as $row) {
            if ($row['message_id'] === $messageId && $row['reporter_user_id'] === $reporterUserId) {
                return true;
            }
        }

        return false;
    }

    public function pendingQueue(int $limit, int $offset): array
    {
        $items = array_values(array_filter(
            array_map(static fn (array $row): MessageReport => MessageReport::fromRow($row), $this->rows),
            static fn (MessageReport $report): bool => $report->isPending()
        ));

        return array_slice($items, $offset, $limit);
    }

    public function resolve(int $reportId, int $actorUserId, string $action, string $note): void
    {
        $this->rows[$reportId]['status'] = $action === 'none' ? 'dismissed' : 'resolved';
        $this->rows[$reportId]['resolved_by_user_id'] = $actorUserId;
        $this->rows[$reportId]['resolved_at'] = '2026-08-20 12:20:00';
        $this->rows[$reportId]['resolution_action'] = $action;
        $this->rows[$reportId]['resolution_note'] = $note === '' ? null : $note;
    }
}

messagingInvalid(
    static fn (): MessageDraft => new MessageDraft(1, 1, '내용', 1000),
    '자기 자신에게 보내는 쪽지는 거부해야 합니다.'
);
messagingInvalid(
    static fn (): MessageDraft => new MessageDraft(1, 2, '   ', 1000),
    '빈 본문은 거부해야 합니다.'
);
messagingInvalid(
    static fn (): MessageDraft => new MessageDraft(1, 2, str_repeat('가', 1001), 1000),
    '최대 길이를 초과한 본문은 거부해야 합니다.'
);
messagingSame(
    '1_2',
    MessageDraft::threadKey(1, 2),
    '스레드 키는 작은 사용자 번호가 먼저 와야 합니다.'
);
messagingSame(
    '1_2',
    MessageDraft::threadKey(2, 1),
    '스레드 키는 사용자 번호 순서와 무관하게 동일해야 합니다.'
);

$openSettingsManager = new MessagingTestSettings();
$openFeatures = new MessagingTestFeatures(['groups' => true]);
$openSettings = new MessagingSettingsService($openSettingsManager, $openFeatures);
$groupMembership = new MessagingTestGroupMembership();
$openScopePolicy = new MessagingScopePolicy($openSettings, $groupMembership);
messagingAssert(
    $openScopePolicy->canMessage(1, 2),
    '전체 허용 범위에서는 그룹 관계와 무관하게 쪽지를 보낼 수 있어야 합니다.'
);

$groupOnlySettingsManager = new MessagingTestSettings();
$groupOnlySettings = new MessagingSettingsService($groupOnlySettingsManager, $openFeatures);
$groupOnlySettings->save(MessagingScope::GroupOnly, 10, 1000, 0);
$groupOnlyScopePolicy = new MessagingScopePolicy($groupOnlySettings, $groupMembership);
messagingAssert(
    ! $groupOnlyScopePolicy->canMessage(1, 2),
    '같은 그룹만 허용 범위에서 그룹이 없는 발신자는 차단해야 합니다.'
);
$groupMembership->groupsByUser[1] = [10];
messagingAssert(
    ! $groupOnlyScopePolicy->canMessage(1, 2),
    '발신자만 그룹이 있고 수신자와 공유 그룹이 없으면 차단해야 합니다.'
);
$groupMembership->groupsByUser[2] = [10];
messagingAssert(
    $groupOnlyScopePolicy->canMessage(1, 2),
    '같은 그룹을 공유하면 쪽지를 보낼 수 있어야 합니다.'
);
$groupMembership->groupsByUser[2] = [20];
messagingAssert(
    ! $groupOnlyScopePolicy->canMessage(1, 2),
    '서로 다른 그룹에 속하면 차단해야 합니다.'
);

$disabledGroupsFeatures = new MessagingTestFeatures(['groups' => false]);
$disabledGroupsSettingsManager = new MessagingTestSettings();
$disabledGroupsSettings = new MessagingSettingsService($disabledGroupsSettingsManager, $disabledGroupsFeatures);
messagingThrows(
    static fn () => $disabledGroupsSettings->save(MessagingScope::GroupOnly, 10, 1000, 0),
    '그룹 기능이 꺼져 있으면 같은 그룹만 모드 저장을 거부해야 합니다.'
);
$disabledGroupsSettings->save(MessagingScope::Open, 5, 500, 0);
messagingSame(
    5,
    $disabledGroupsSettings->current()->sendRateLimit(),
    '그룹 기능이 꺼진 상태에서도 전체 허용 모드는 저장할 수 있어야 합니다.'
);
messagingInvalid(
    static fn () => $disabledGroupsSettings->save(MessagingScope::Open, 31, 500, 1),
    '분당 발송 제한 상한을 넘는 값은 거부해야 합니다.'
);

$profiles = new MessagingTestProfiles();
$profiles->create(new UserProfile(1, null, null, AccessStatus::Active, false));
$profiles->create(new UserProfile(2, null, null, AccessStatus::Active, false));
$profiles->create(new UserProfile(3, null, null, AccessStatus::Suspended, false));

$messageRepository = new MessagingTestMessageRepository();
$serviceSettingsManager = new MessagingTestSettings();
$serviceFeatures = new MessagingTestFeatures(['groups' => true]);
$serviceSettings = new MessagingSettingsService($serviceSettingsManager, $serviceFeatures);
$serviceScopePolicy = new MessagingScopePolicy($serviceSettings, new MessagingTestGroupMembership());
$messaging = new MessagingService($messageRepository, $profiles, $serviceScopePolicy, $serviceSettings);
$siteMessaging = new MessagingService(
    $messageRepository,
    $profiles,
    $serviceScopePolicy,
    $serviceSettings,
    new class () implements Clock {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable(
                '2026-08-20 12:00:00',
                new DateTimeZone('Asia/Seoul')
            );
        }
    }
);
$siteMessaging->adminSearch(0, '2026-08-20', '2026-08-20', 20, 0);
messagingSame(
    ['2026-08-19 15:00:00', '2026-08-20 14:59:59'],
    [$messageRepository->lastAdminFrom, $messageRepository->lastAdminTo],
    '쪽지 조회 날짜는 WordPress 지역의 하루 전체를 UTC 경계로 변환해야 합니다.'
);
messagingThrows(
    static fn () => $siteMessaging->adminSearch(
        0,
        '2026-08-21',
        '2026-08-20',
        20,
        0
    ),
    '쪽지 조회 시작일이 종료일보다 늦으면 거부해야 합니다.'
);

messagingThrows(
    static fn () => $messaging->send(1, 3, '안녕하세요'),
    '이용 중지된 수신자에게는 쪽지를 보낼 수 없어야 합니다.'
);
messagingThrows(
    static fn () => $messaging->send(1, 999, '안녕하세요'),
    '존재하지 않는 수신자에게는 쪽지를 보낼 수 없어야 합니다.'
);

$firstMessageId = $messaging->send(1, 2, '첫 쪽지입니다.');
messagingSame(1, $firstMessageId, '첫 쪽지 번호가 올바르지 않습니다.');
$secondMessageId = $messaging->send(2, 1, '답장입니다.');
messagingSame(['1_2'], $messaging->unreadThreadKeys(2, ['1_2']), '내가 답장해도 이전 수신 쪽지는 읽음으로 바뀌면 안 됩니다.');
messagingSame(1, count($messaging->inbox(2, 20, 0, true)), '읽지 않음 필터는 답장 이전의 수신 쪽지도 찾아야 합니다.');
messagingSame([], $messaging->inbox(2, 20, 1, true), '읽지 않음 필터에도 페이지 범위를 적용해야 합니다.');
messagingAssert($messaging->canReceiveFrom(1, 2), '활성 수신자는 선택할 수 있어야 합니다.');
messagingAssert(! $messaging->canReceiveFrom(1, 1), '자신은 수신자 검색에서 제외해야 합니다.');
messagingAssert(! $messaging->canReceiveFrom(1, 3), '중지된 수신자는 검색에서 제외해야 합니다.');
messagingAssert(! $messaging->canReceiveFrom(1, 999), '존재하지 않는 수신자는 제외해야 합니다.');

messagingSame(
    1,
    count($messaging->inbox(2, 20, 0)),
    '수신함에는 받은 쪽지의 스레드별 최신 항목이 표시되어야 합니다.'
);
messagingSame(
    1,
    $messaging->unreadCount(1),
    '안 읽은 쪽지 수를 정확히 세어야 합니다.'
);

$threadKey = MessageDraft::threadKey(1, 2);
$threadMessages = $messaging->thread(1, $threadKey, 20, 0);
messagingSame([], $messaging->inbox(1, 20, 0, true), '대화 열람 후 읽지 않음 목록에서 제외해야 합니다.');
messagingSame(2, count($threadMessages), '스레드에는 주고받은 쪽지가 모두 표시되어야 합니다.');
messagingSame(
    0,
    $messaging->unreadCount(1),
    '스레드를 열람하면 읽음 처리가 되어야 합니다.'
);

$messaging->softDelete(1, $secondMessageId);
messagingAssert(
    $messageRepository->find($secondMessageId) !== null,
    '소프트 삭제는 실제 행을 제거하지 않아야 합니다.'
);
messagingSame(
    1,
    count($messaging->thread(1, $threadKey, 20, 0)),
    '발신자가 삭제한 쪽지는 발신자 화면에서만 감춰야 합니다.'
);
messagingSame(
    2,
    count($messaging->thread(2, $threadKey, 20, 0)),
    '한쪽에서 삭제해도 상대방 화면에는 그대로 보여야 합니다.'
);

messagingInvalid(
    static fn () => $messaging->send(1, 2, str_repeat('x', 2000)),
    '설정된 최대 길이를 넘는 쪽지는 거부해야 합니다.'
);

$reportRepository = new MessagingTestMessageReportRepository();
$reports = new MessageReportService($reportRepository, $messageRepository);

messagingThrows(
    static fn () => $reports->report(999, $firstMessageId, '스팸입니다.'),
    '쪽지 당사자가 아닌 사용자의 신고는 거부해야 합니다.'
);
$reports->report(2, $firstMessageId, '스팸입니다.');
messagingThrows(
    static fn () => $reports->report(2, $firstMessageId, '또 신고합니다.'),
    '같은 쪽지에 대한 중복 신고는 거부해야 합니다.'
);
messagingSame(1, count($reports->listQueue(20, 0)), '대기 중인 신고 큐 항목 수가 올바르지 않습니다.');

$pendingReport = $reports->listQueue(20, 0)[0];
$reports->resolve(2, $pendingReport->id(), 'message_hidden', '확인 후 숨김');
$hiddenMessage = $messageRepository->find($firstMessageId);
messagingAssert(
    $hiddenMessage !== null && $hiddenMessage->isHidden(),
    '신고를 쪽지 숨김으로 처리하면 실제로 쪽지를 숨겨야 합니다.'
);
messagingSame(0, count($reports->listQueue(20, 0)), '처리한 신고는 대기 큐에서 제외되어야 합니다.');
messagingThrows(
    static fn () => $reports->resolve(2, $pendingReport->id(), 'none', ''),
    '이미 처리된 신고를 다시 처리하는 것은 거부해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "쪽지 단위 검사 통과: assertion %d개\n",
        $messagingAssertions
    )
);
