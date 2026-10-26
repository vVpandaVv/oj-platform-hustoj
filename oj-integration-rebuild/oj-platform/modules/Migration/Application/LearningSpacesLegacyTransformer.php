<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use DomainException;

final class LearningSpacesLegacyTransformer implements LegacyDomainTransformer
{
    private const SUPPORTED = [
        'classes',
        'class_instructors',
        'class_enrollments',
        'class_group_links',
        'class_problem_links',
        'contest_settings',
        'contest_problem_settings',
        'contest_managers',
        'contest_participants',
        'contest_ip_logs',
        'deleted_contests',
        'learning_groups',
        'group_managers',
        'group_memberships',
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
        if ($table->transformer() === 'contest_problem_settings') {
            return $this->contestProblemSettings($row);
        }

        return [match ($table->transformer()) {
            'classes' => $this->learningClass($row, $plan),
            'class_instructors' => $this->classInstructor($row, $plan),
            'class_enrollments' => $this->classEnrollment($row),
            'class_group_links' => $this->classGroupLink($row),
            'class_problem_links' => $this->classProblemLink($row),
            'contest_settings' => $this->contestSettings($row, $plan),
            'contest_managers' => $this->contestManager($row, $plan),
            'contest_participants' => $this->contestParticipant($row),
            'contest_ip_logs' => $this->contestIpLog($row),
            'deleted_contests' => $this->deletedContest($row),
            'learning_groups' => $this->learningGroup($row, $plan),
            'group_managers' => $this->groupManager($row),
            'group_memberships' => $this->groupMembership($row),
            default => throw new DomainException('학습 공간 이전 변환기를 찾지 못했습니다.'),
        }];
    }

    /** @param array<string, mixed> $row */
    private function learningClass(array $row, MigrationPlan $plan): array
    {
        $id = $this->values->positive($row['id'] ?? null, '수업 번호');
        $owner = $this->values->user(
            $row,
            'created_by_wp_user_id',
            '',
            true
        ) ?? $this->values->assignedOwner($plan, 'class', $id);

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'visibility' => (string) ($row['visibility'] ?? 'private'),
            'progress_visibility' => 'private',
            'enabled' => $this->values->flag($row['enabled'] ?? 1) ? 1 : 0,
            'starts_at' => $this->values->dateTime($row['start_at'] ?? null),
            'ends_at' => $this->values->dateTime($row['end_at'] ?? null),
            'password_hash' => $this->values->text($row['password_hash'] ?? null),
            'blockly_profile_key' => null,
            'owner_user_id' => $owner,
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
            'deleted_at' => $this->values->dateTime($row['deleted_at'] ?? null),
            'deleted_by_user_id' => $this->values->user(
                $row,
                'deleted_by_wp_user_id',
                '',
                true
            ),
        ];
    }

    /** @param array<string, mixed> $row */
    private function classInstructor(array $row, MigrationPlan $plan): array
    {
        $id = $this->values->positive($row['id'] ?? null, '수업 번호');
        $owner = $this->values->user(
            $row,
            'created_by_wp_user_id',
            '',
            true
        ) ?? $this->values->assignedOwner($plan, 'class', $id);
        $created = $this->values->requiredDateTime($row['created_at'] ?? null);

        return [
            'id' => $id,
            'class_id' => $id,
            'user_id' => $owner,
            'role' => 'owner',
            'status' => 'active',
            'granted_by_user_id' => $owner,
            'granted_at' => $created,
            'revoked_at' => null,
            'created_at' => $created,
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function classEnrollment(array $row): array
    {
        $enrolled = $this->values->dateTime($row['enrolled_at'] ?? null);

        return [
            'id' => $this->values->positive($row['id'] ?? null, '수업 참여 번호'),
            'class_id' => $this->values->positive($row['class_id'] ?? null, '수업 번호'),
            'user_id' => $this->values->user($row, 'wp_user_id', 'oj_user_id'),
            'status' => (string) ($row['status'] ?? '') === 'enrolled'
                ? 'active'
                : (string) ($row['status'] ?? 'pending'),
            'requested_at' => $enrolled,
            'invited_at' => null,
            'invited_by_user_id' => null,
            'approved_at' => $enrolled,
            'approved_by_user_id' => null,
            'rejected_at' => null,
            'rejected_by_user_id' => null,
            'left_at' => $this->values->dateTime($row['left_at'] ?? null),
            'removed_at' => null,
            'removed_by_user_id' => null,
            'reactivated_at' => null,
            'reactivated_by_user_id' => null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function classGroupLink(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '수업 그룹 연결 번호'),
            'class_id' => $this->values->positive($row['class_id'] ?? null, '수업 번호'),
            'group_id' => $this->values->positive($row['group_id'] ?? null, '그룹 번호'),
            'created_by_user_id' => $this->values->user(
                $row,
                'created_by_wp_user_id'
            ),
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function classProblemLink(array $row): array
    {
        $created = $this->values->requiredDateTime($row['created_at'] ?? null);

        return [
            'id' => $this->values->positive($row['id'] ?? null, '수업 문제 연결 번호'),
            'class_id' => $this->values->positive($row['class_id'] ?? null, '수업 번호'),
            'problem_id' => $this->values->positive($row['problem_id'] ?? null, '문제 번호'),
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'is_visible' => 1,
            'available_from' => null,
            'available_until' => null,
            'created_by_user_id' => $this->values->user(
                $row,
                'created_by_wp_user_id'
            ),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /** @param array<string, mixed> $row */
    private function contestSettings(array $row, MigrationPlan $plan): array
    {
        $id = $this->values->positive($row['contest_id'] ?? null, '대회 번호');
        $metadata = $this->values->object($row['metadata'] ?? null);

        return [
            'contest_id' => $id,
            'owner_user_id' => $this->values->assignedOwner($plan, 'contest', $id),
            'visibility' => (string) ($row['visibility'] ?? 'private'),
            'group_id' => $this->values->positiveOrNull($row['group_id'] ?? null),
            'result_visibility' => 'participants',
            'ranking_visibility' => 'private',
            'wrong_penalty_points' => max(
                0,
                (int) ($metadata['wrong_penalty_points'] ?? 5)
            ),
            'time_score_percent' => max(
                0,
                min(100, (int) ($metadata['time_score_percent'] ?? 10))
            ),
            'partial_score_policy' => (string) (
                $metadata['partial_score_policy'] ?? 'none'
            ),
            'scoring_policy' => (string) (
                $metadata['scoring_policy'] ?? 'standard_score_v1'
            ),
            'ranking_policy' => (string) (
                $metadata['ranking_policy'] ?? 'score_solved_time_v1'
            ),
            'freeze_minutes' => max(0, (int) ($row['freeze_minutes'] ?? 0)),
            'blockly_profile_key' => null,
            'allow_ai_feedback' => $this->values->flag(
                $row['allow_ai_feedback'] ?? 0
            ) ? 1 : 0,
            'wrong_answer_policy' => $this->values->flag(
                $row['show_wrong_answer_detail'] ?? 0
            ) ? 'show_detail' : 'penalty_before_accept',
            'ip_policy' => $this->values->flag($row['ip_lock_enabled'] ?? 0)
                ? 'locked'
                : 'none',
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function contestProblemSettings(array $row): array
    {
        $contestId = $this->values->positive(
            $row['contest_id'] ?? null,
            '대회 번호'
        );
        $metadata = $this->values->object($row['metadata'] ?? null);
        $scores = is_array($metadata['problem_scores'] ?? null)
            ? $metadata['problem_scores']
            : [];
        $createdAt = $this->values->requiredDateTime(
            $row['created_at'] ?? null
        );
        $updatedAt = $this->values->requiredDateTime(
            $row['updated_at'] ?? null
        );
        $result = [];
        foreach ($scores as $problemId => $score) {
            $normalizedProblemId = filter_var(
                $problemId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($normalizedProblemId === false || ! is_numeric($score)) {
                continue;
            }
            $normalizedScore = (float) $score;
            if (! is_finite($normalizedScore) || $normalizedScore < 0) {
                continue;
            }
            $result[] = [
                'contest_id' => $contestId,
                'problem_id' => $normalizedProblemId,
                'score' => $normalizedScore,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function contestManager(array $row, MigrationPlan $plan): array
    {
        $id = $this->values->positive($row['contest_id'] ?? null, '대회 번호');
        $owner = $this->values->assignedOwner($plan, 'contest', $id);
        $created = $this->values->requiredDateTime($row['created_at'] ?? null);

        return [
            'id' => $id,
            'contest_id' => $id,
            'user_id' => $owner,
            'role' => 'owner',
            'status' => 'active',
            'granted_by_user_id' => $owner,
            'granted_at' => $created,
            'revoked_at' => null,
            'created_at' => $created,
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function contestParticipant(array $row): array
    {
        $created = $this->values->requiredDateTime($row['created_at'] ?? null);

        return [
            'id' => $this->values->positive($row['id'] ?? null, '대회 참가 번호'),
            'contest_id' => $this->values->positive($row['contest_id'] ?? null, '대회 번호'),
            'user_id' => $this->values->user($row, 'wp_user_id', 'oj_user_id'),
            'status' => 'active',
            'allowed_ip' => $this->values->text($row['allowed_ip'] ?? null),
            'requested_at' => $created,
            'approved_at' => $created,
            'approved_by_user_id' => null,
            'withdrawn_at' => null,
            'removed_at' => null,
            'removed_by_user_id' => null,
            'reactivated_at' => null,
            'reactivated_by_user_id' => null,
            'last_accessed_at' => $this->values->dateTime($row['last_seen_at'] ?? null),
            'last_submitted_at' => $this->values->dateTime(
                $row['last_submit_at'] ?? null
            ),
        ];
    }

    /** @param array<string, mixed> $row */
    private function contestIpLog(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '대회 IP 기록 번호'),
            'contest_id' => $this->values->positive($row['contest_id'] ?? null, '대회 번호'),
            'user_id' => $this->values->user($row, 'wp_user_id', 'oj_user_id'),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'action' => (string) ($row['action'] ?? ''),
            'metadata' => $this->values->nullableJson($row['metadata'] ?? null)
                ?? '{}',
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function deletedContest(array $row): array
    {
        return [
            'contest_id' => $this->values->positive(
                $row['contest_id'] ?? null,
                '삭제 대회 번호'
            ),
            'title' => (string) ($row['title'] ?? ''),
            'snapshot' => $this->values->nullableJson($row['snapshot'] ?? null)
                ?? '{}',
            'deleted_by_user_id' => $this->values->user(
                $row,
                'deleted_by_wp_user_id'
            ),
            'deleted_at' => $this->values->requiredDateTime(
                $row['deleted_at'] ?? null
            ),
        ];
    }

    /** @param array<string, mixed> $row */
    private function learningGroup(array $row, MigrationPlan $plan): array
    {
        $id = $this->values->positive($row['id'] ?? null, '그룹 번호');
        $owner = $this->values->user(
            $row,
            'created_by_wp_user_id',
            '',
            true
        ) ?? $this->values->assignedOwner($plan, 'group', $id);
        $mode = (string) ($row['evaluation_mode'] ?? 'normal');

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'visibility' => (string) ($row['visibility'] ?? 'private'),
            'approval_mode' => (string) ($row['approval_mode'] ?? 'manual'),
            'enabled' => $this->values->flag($row['enabled'] ?? 1) ? 1 : 0,
            'blockly_profile_key' => null,
            'evaluation_mode' => in_array($mode, ['normal', 'performance'], true)
                ? $mode
                : 'normal',
            'evaluation_mode_changed_by_user_id' => null,
            'evaluation_mode_changed_at' => null,
            'join_code_hash' => null,
            'owner_user_id' => $owner,
            'created_at' => $this->values->requiredDateTime($row['created_at'] ?? null),
            'updated_at' => $this->values->requiredDateTime($row['updated_at'] ?? null),
            'deleted_at' => $this->values->dateTime($row['deleted_at'] ?? null),
            'deleted_by_user_id' => $this->values->user(
                $row,
                'deleted_by_wp_user_id',
                '',
                true
            ),
        ];
    }

    /** @param array<string, mixed> $row */
    private function groupManager(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '그룹 관리자 번호'),
            'group_id' => $this->values->positive($row['group_id'] ?? null, '그룹 번호'),
            'user_id' => $this->values->user($row, 'wp_user_id'),
            'granted_by_user_id' => $this->values->user(
                $row,
                'granted_by_wp_user_id'
            ),
            'granted_at' => $this->values->requiredDateTime($row['granted_at'] ?? null),
            'revoked_at' => $this->values->dateTime($row['revoked_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function groupMembership(array $row): array
    {
        return [
            'id' => $this->values->positive($row['id'] ?? null, '그룹 참여 번호'),
            'group_id' => $this->values->positive($row['group_id'] ?? null, '그룹 번호'),
            'user_id' => $this->values->user($row, 'wp_user_id', 'oj_user_id'),
            'status' => (string) ($row['status'] ?? 'pending'),
            'requested_at' => $this->values->dateTime($row['requested_at'] ?? null),
            'approved_at' => $this->values->dateTime($row['approved_at'] ?? null),
            'approved_by_user_id' => $this->values->user(
                $row,
                'approved_by_wp_user_id',
                '',
                true
            ),
            'rejected_at' => $this->values->dateTime($row['rejected_at'] ?? null),
            'rejected_by_user_id' => $this->values->user(
                $row,
                'rejected_by_wp_user_id',
                '',
                true
            ),
            'left_at' => $this->values->dateTime($row['left_at'] ?? null),
            'removed_at' => null,
            'removed_by_user_id' => null,
        ];
    }
}

