<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiTaskAssignment;
use DomainException;

final class AiTaskRoutingService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly AiProviderSettingsService $providers,
        private readonly AiProviderCatalog $catalog
    ) {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('ai_task_routing', ['assignments' => []]);
    }

    /**
     * @return array<string, AiTaskAssignment>
     */
    public function assignments(): array
    {
        return $this->assignmentsFrom($this->snapshot());
    }

    public function find(string $taskType): ?AiTaskAssignment
    {
        return $this->assignments()[$taskType] ?? null;
    }

    public function findAtRevision(
        string $taskType,
        int $expectedRevision
    ): ?AiTaskAssignment {
        $snapshot = $this->snapshot();
        if ($snapshot->revision() !== $expectedRevision) {
            throw new DomainException(
                'AI 작업 배정이 작업 등록 시점과 달라졌습니다. '
                . '현재 설정으로 새 작업을 등록해 주세요.'
            );
        }

        return $this->assignmentsFrom($snapshot)[$taskType] ?? null;
    }

    /**
     * @param list<AiTaskAssignment> $assignments
     */
    public function save(
        array $assignments,
        int $expectedRevision
    ): SettingsSnapshot {
        $profiles = [];
        foreach ($this->providers->profiles() as $profile) {
            $profiles[$profile->key()] = $profile;
        }
        $indexed = [];
        foreach ($assignments as $assignment) {
            if (
                ! $this->catalog->supportsTask($assignment->taskType())
                || isset($indexed[$assignment->taskType()])
            ) {
                throw new DomainException('AI 작업 배정이 중복되었거나 등록되지 않았습니다.');
            }
            $primary = $profiles[$assignment->primaryProfileKey()] ?? null;
            $fallbackKey = $assignment->fallbackProfileKey();
            $fallback = $fallbackKey !== null
                ? ($profiles[$fallbackKey] ?? null)
                : null;
            $this->assertProfile($primary, $assignment->taskType());
            if ($fallbackKey !== null) {
                $this->assertProfile($fallback, $assignment->taskType());
            }
            if ($this->catalog->requiresSubmissionSource($assignment->taskType())) {
                if (
                    ! $primary->allowsSubmissionSource()
                    || ($fallback !== null && ! $fallback->allowsSubmissionSource())
                ) {
                    throw new DomainException(
                        '제출 AI 피드백에는 제출 코드 처리를 승인한 프로필만 배정할 수 있습니다.'
                    );
                }
            }
            $indexed[$assignment->taskType()] = $assignment;
        }
        ksort($indexed);

        return $this->settings->save(
            'ai_task_routing',
            [
                'assignments' => array_map(
                    static fn (AiTaskAssignment $assignment): array =>
                        $assignment->toArray(),
                    array_values($indexed)
                ),
            ],
            $expectedRevision
        );
    }

    /**
     * @return array<string, AiTaskAssignment>
     */
    private function assignmentsFrom(
        SettingsSnapshot $snapshot
    ): array {
        $value = $snapshot->value();
        $rawAssignments = is_array($value)
            && is_array($value['assignments'] ?? null)
                ? $value['assignments']
                : [];
        $assignments = [];
        foreach ($rawAssignments as $raw) {
            if (! is_array($raw)) {
                throw new DomainException('AI 작업 배정 저장 형식이 올바르지 않습니다.');
            }
            $assignment = AiTaskAssignment::fromArray($raw);
            if (! $this->catalog->supportsTask($assignment->taskType())) {
                // A stale row left behind by an addon task that is no longer
                // accepted (deactivated/rejected/renamed). Drop it silently
                // rather than breaking routing for every other task —
                // including core tasks — on every request until an admin
                // edits the raw option by hand. The task simply reports as
                // unassigned, same as if it were never configured.
                continue;
            }
            if (isset($assignments[$assignment->taskType()])) {
                throw new DomainException('AI 작업 배정 키가 중복되었거나 등록되지 않았습니다.');
            }
            $assignments[$assignment->taskType()] = $assignment;
        }

        return $assignments;
    }
    private function assertProfile(
        mixed $profile,
        string $taskType
    ): void {
        if (
            ! $profile instanceof AiProviderProfile
            || ! $profile->allowsTask($taskType)
        ) {
            throw new DomainException(
                '선택한 AI 프로필이 해당 작업의 후보 검사를 통과하지 않았습니다.'
            );
        }
    }
}
