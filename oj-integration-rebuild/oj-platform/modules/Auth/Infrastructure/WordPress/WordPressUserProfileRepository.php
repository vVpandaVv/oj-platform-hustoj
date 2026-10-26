<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use RuntimeException;

final class WordPressUserProfileRepository implements UserProfileRepository
{
    private const TABLE = 'user_profiles';

    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        return new self(self::databaseFromGlobals());
    }

    public function find(int $userId): ?UserProfile
    {
        $row = $this->row(
            'SELECT user_id, affiliation, bio, birth_date, access_status, must_change_password'
            . ' FROM `' . self::TABLE . '` WHERE user_id = %d',
            [$userId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(UserProfile $profile): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $result = $this->database->insert(
            self::TABLE,
            [
                'user_id' => $profile->userId(),
                'affiliation' => $profile->affiliation(),
                'bio' => $profile->bio(),
                'birth_date' => $profile->birthDate(),
                'access_status' => $profile->accessStatus()->value,
                'must_change_password' => $profile->mustChangePassword() ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($result === false) {
            throw new RuntimeException('사용자 프로필을 만들지 못했습니다.');
        }
    }

    public function save(UserProfile $profile, ?int $changedByUserId = null): void
    {
        $data = [
            'affiliation' => $profile->affiliation(),
            'bio' => $profile->bio(),
            'birth_date' => $profile->birthDate(),
            'access_status' => $profile->accessStatus()->value,
            'must_change_password' => $profile->mustChangePassword() ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%d', '%s'];

        if ($changedByUserId !== null) {
            $data['access_status_changed_by_user_id'] = $changedByUserId;
            $data['access_status_changed_at'] = gmdate('Y-m-d H:i:s');
            $formats[] = '%d';
            $formats[] = '%s';
        }

        $result = $this->database->update(
            self::TABLE,
            $data,
            ['user_id' => $profile->userId()],
            $formats,
            ['%d']
        );

        if ($result === false) {
            throw new RuntimeException('사용자 프로필을 저장하지 못했습니다.');
        }
    }

    public function recordLogin(int $userId): void
    {
        $result = $this->database->update(
            self::TABLE,
            [
                'last_login_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['user_id' => $userId],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RuntimeException('마지막 로그인 시각을 기록하지 못했습니다.');
        }
    }

    public function saveRegistrationRequirements(
        int $userId,
        RegistrationRequirements $requirements
    ): void {
        $encoded = json_encode(
            [
                'agreement_document_ids' => $requirements->agreementDocumentIds(),
                'guardian_consent_required' => $requirements->guardianConsentRequired(),
            ],
            JSON_THROW_ON_ERROR
        );
        $result = $this->database->update(
            self::TABLE,
            [
                'registration_requirements' => $encoded,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['user_id' => $userId],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RuntimeException('가입 승인 요구사항을 저장하지 못했습니다.');
        }
    }

    public function registrationRequirements(
        int $userId
    ): ?RegistrationRequirements {
        $row = $this->row(
            'SELECT registration_requirements FROM `' . self::TABLE . '` WHERE user_id = %d',
            [$userId]
        );
        $encoded = $row['registration_requirements'] ?? null;
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        try {
            $value = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('저장된 가입 승인 요구사항이 올바르지 않습니다.', 0, $error);
        }
        if (! is_array($value)) {
            throw new RuntimeException('저장된 가입 승인 요구사항이 올바르지 않습니다.');
        }

        return new RegistrationRequirements(
            is_array($value['agreement_document_ids'] ?? null)
                ? $value['agreement_document_ids']
                : [],
            (bool) ($value['guardian_consent_required'] ?? false)
        );
    }

    public function findByStatus(AccessStatus $status, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $rows = $this->rows(
            'SELECT user_id, affiliation, bio, birth_date, access_status, must_change_password'
            . ' FROM `' . self::TABLE . '` WHERE access_status = %s'
            . ' ORDER BY created_at ASC LIMIT %d OFFSET %d',
            [$status->value, $limit, $offset]
        );

        return array_map(fn (array $row): UserProfile => $this->hydrate($row), $rows);
    }

    public function findPage(int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $rows = $this->rows(
            'SELECT user_id, affiliation, bio, birth_date, access_status, must_change_password'
            . ' FROM `' . self::TABLE . '`'
            . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
            [$limit, $offset]
        );

        return array_map(fn (array $row): UserProfile => $this->hydrate($row), $rows);
    }

    public function findFiltered(
        ?array $userIds,
        ?AccessStatus $status,
        int $limit,
        int $offset = 0
    ): array {
        if ($userIds === []) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$where, $params] = $this->filterClause($userIds, $status);
        $rows = $this->rows(
            'SELECT user_id, affiliation, bio, birth_date, access_status, must_change_password'
            . ' FROM `' . self::TABLE . '`' . $where
            . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
            [...$params, $limit, $offset]
        );

        return array_map(fn (array $row): UserProfile => $this->hydrate($row), $rows);
    }

    public function countFiltered(?array $userIds, ?AccessStatus $status): int
    {
        if ($userIds === []) {
            return 0;
        }
        [$where, $params] = $this->filterClause($userIds, $status);
        $row = $this->row(
            'SELECT COUNT(*) AS `count` FROM `' . self::TABLE . '`' . $where,
            $params
        );

        return (int) ($row['count'] ?? 0);
    }

    /**
     * @param list<int>|null $userIds
     * @return array{0: string, 1: list<mixed>}
     */
    private function filterClause(?array $userIds, ?AccessStatus $status): array
    {
        $conditions = [];
        $params = [];
        if ($userIds !== null) {
            $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
            $conditions[] = 'user_id IN (' . $placeholders . ')';
            foreach ($userIds as $userId) {
                $params[] = (int) $userId;
            }
        }
        if ($status !== null) {
            $conditions[] = 'access_status = %s';
            $params[] = $status->value;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UserProfile
    {
        $status = AccessStatus::tryFrom((string) ($row['access_status'] ?? ''));
        if ($status === null) {
            throw new RuntimeException('저장된 사용자 이용 상태가 올바르지 않습니다.');
        }

        return new UserProfile(
            (int) ($row['user_id'] ?? 0),
            $this->nullableString($row['affiliation'] ?? null),
            $this->nullableString($row['birth_date'] ?? null),
            $status,
            (int) ($row['must_change_password'] ?? 0) === 1,
            $this->nullableString($row['bio'] ?? null)
        );
    }

    /**
     * @param list<mixed> $values
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $values): ?array
    {
        $prepared = $this->prepare($sql, $values);
        $row = $this->database->get_row($prepared, 'ARRAY_A');
        if (trim((string) ($this->database->last_error ?? '')) !== '') {
            throw new RuntimeException('사용자 프로필을 조회하지 못했습니다.');
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $values): array
    {
        $prepared = $this->prepare($sql, $values);
        $rows = $this->database->get_results($prepared, 'ARRAY_A');

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param list<mixed> $values
     */
    private function prepare(string $sql, array $values): string
    {
        if (! method_exists($this->database, 'prepare')) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        return (string) $this->database->prepare($sql, ...$values);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function databaseFromGlobals(): object
    {
        $database = $GLOBALS['wpdb'] ?? null;
        if (! is_object($database)) {
            return new \stdClass();
        }

        return $database;
    }
}
