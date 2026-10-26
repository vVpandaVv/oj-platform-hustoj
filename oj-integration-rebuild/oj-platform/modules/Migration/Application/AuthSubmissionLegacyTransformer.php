<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;
use DomainException;

final class AuthSubmissionLegacyTransformer implements LegacyDomainTransformer
{
    private const SUPPORTED = [
        'user_profiles',
        'user_preferences',
        'agreement_documents',
        'guardian_verifications',
        'user_agreement_log',
        'deleted_users',
        'submission_language_settings_log',
        'submission_events',
    ];

    public function __construct(private readonly LegacyTransformValues $values)
    {
    }

    public function supports(string $transformer): bool
    {
        return in_array($transformer, self::SUPPORTED, true);
    }

    public function transform(
        MigrationTable $table,
        array $row,
        MigrationPlan $plan
    ): array {
        unset($plan);

        return [match ($table->transformer()) {
            'user_profiles' => $this->userProfile($row),
            'user_preferences' => $this->userPreferences($row),
            'agreement_documents' => $this->agreementDocument($row),
            'guardian_verifications' => $this->guardianVerification($row),
            'user_agreement_log' => $this->agreementLog($row),
            'deleted_users' => $this->deletedUser($row),
            'submission_language_settings_log' => $this->languageLog($row),
            'submission_events' => $this->submissionEvent($row),
            default => throw new DomainException('계정·제출 이전 변환기를 찾지 못했습니다.'),
        }];
    }

    /** @param array<string, mixed> $row */
    private function userProfile(array $row): array
    {
        $status = match ((string) ($row['status'] ?? 'active')) {
            'pending' => 'pending',
            'inactive', 'suspended', 'blocked' => 'suspended',
            default => 'active',
        };

        return [
            'user_id' => $this->values->user($row, 'wp_user_id', 'user_id'),
            'affiliation' => $this->values->text($row['school'] ?? null),
            'bio' => null,
            'birth_date' => $this->values->dateOnly($row['birth_date'] ?? null),
            'access_status' => $status,
            'must_change_password' => 0,
            'access_status_changed_by_user_id' => null,
            'access_status_changed_at' => null,
            'last_login_at' => $this->values->dateTime($row['last_login_at'] ?? null),
            'registration_requirements' => null,
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function userPreferences(array $row): array
    {
        $isPublic = $this->values->flag($row['nickname_public'] ?? 0)
            || $this->values->flag($row['email_public'] ?? 0)
            || $this->values->flag($row['school_public'] ?? 0);
        $legacyTheme = $this->values->text($row['editor_theme'] ?? null) ?? '';

        return [
            'user_id' => $this->values->user($row, 'wp_user_id', 'oj_user_id'),
            'profile_visibility' => $isPublic ? 'members' : 'hidden',
            'ranking_visibility' => 'members',
            'activity_visibility' => 'members',
            'default_language' => $this->languageId($row['default_language'] ?? null),
            'editor_theme' => $this->editorTheme($legacyTheme),
            'code_template' => $this->values->text($row['code_template'] ?? null),
            'default_editor' => 'code',
            'editor_font_size' => 16,
            'first_login_agreed_at' => $this->values->dateTime(
                $row['first_login_agreed_at'] ?? null
            ),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    private function languageId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $languageId = (int) $value;

        return $languageId >= 0 && $languageId <= 24
            ? $languageId
            : null;
    }

    private function editorTheme(string $legacyTheme): string
    {
        return AceEditorThemeCatalog::normalize($legacyTheme);
    }

    /** @param array<string, mixed> $row */
    private function agreementDocument(array $row): array
    {
        $row['created_by_user_id'] = $this->values->user(
            $row,
            'created_by_wp_user_id'
        );
        unset($row['created_by_wp_user_id']);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function guardianVerification(array $row): array
    {
        $row['user_id'] = $this->values->user(
            $row,
            'wp_user_id',
            'child_user_id',
            true
        );
        unset($row['wp_user_id'], $row['child_user_id']);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function agreementLog(array $row): array
    {
        $row['user_id'] = $this->values->user($row, 'wp_user_id');
        $row['ip_address'] = $this->values->text($row['ip_address'] ?? null);
        unset($row['wp_user_id']);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function deletedUser(array $row): array
    {
        $userId = $this->values->positive(
            $row['wp_user_id'] ?? null,
            '삭제 사용자 번호'
        );
        $fingerprint = hash('sha256', $this->values->json([
            'legacy_user_id' => (string) ($row['oj_user_id'] ?? ''),
            'legacy_snapshot' => (string) ($row['snapshot'] ?? ''),
        ]));

        return [
            'id' => $this->values->positive($row['id'] ?? null, '삭제 사용자 기록 번호'),
            'user_id' => $userId,
            'snapshot' => $this->values->json([
                'anonymous_label' => '익명 사용자 ' . $userId,
                'legacy_record_hash' => $fingerprint,
            ]),
            'deleted_by_user_id' => $this->values->user(
                $row,
                'deleted_by_wp_user_id'
            ),
            'deleted_at' => $this->values->requiredDateTime(
                $row['deleted_at'] ?? null
            ),
            'reason' => $this->values->text($row['reason'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function languageLog(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '언어 설정 기록 번호'),
            'language_mask' => max(0, (int) ($row['language_mask'] ?? 0)),
            'created_by_user_id' => $this->values->user($row, 'created_by'),
            'created_at' => $this->values->requiredDateTime(
                $row['created_at'] ?? null
            ),
        ];
    }

    /** @param array<string, mixed> $row */
    private function submissionEvent(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '제출 사건 번호'),
            'submission_id' => $this->values->positiveOrNull(
                $row['submission_id'] ?? null
            ),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'actor_user_id' => $this->values->user(
                $row,
                'actor_wp_user_id',
                'oj_user_id'
            ),
            'request_key' => null,
            'message' => substr((string) ($row['message'] ?? ''), 0, 500),
            'metadata' => $this->values->nullableJson($row['metadata'] ?? null)
                ?? '{}',
            'created_at' => $this->values->requiredDateTime(
                $row['created_at'] ?? null
            ),
        ];
    }
}

