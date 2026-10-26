<?php
declare(strict_types=1);
namespace OjPrimmTests;

use DomainException;
use OjPrimm\Application\PlatformAccess;
use OjPrimm\Application\RosterAccess;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Application\LessonVerification;

final class Platform implements PlatformAccess, RosterAccess
{
    public int $actor = 10; public bool $allowed = true; public string $hash = 'problem-one'; public array $learners = []; public array $problemHashes = [];
    public function actorId(): int { return $this->actor; }
    public function assertRole(string $role): void { if (! $this->allowed || ($role === 'teach' ? $this->actor !== 10 : $this->actor < 20)) { throw new DomainException('역할 권한이 없습니다.'); } }
    public function contexts(bool $teaching): array { return [['type' => 'class', 'id' => 'class:7', 'revision' => 'rev1', 'label' => '수업']]; }
    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array
    {
        if (! $this->allowed || $type !== 'class' || ! in_array($id, ['class:7', 'class:8'], true)
            || ($operation === 'participate' ? $this->actor < 20 || $learnerId !== $this->actor : $this->actor !== 10)) { throw new DomainException('현재 수업 권한이 없습니다.'); }
        return ['type' => $type, 'id' => $id, 'revision' => 'rev1'];
    }
    public function searchProblems(string $search): array { return []; }
    public function assertSupport(int $problemId, string $kind, string $contextType, string $contextId): void {}
    public function problem(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array { return ['problem' => ['id' => $problemId, 'title' => '덧셈', 'content_hash' => $this->problemHashes[$problemId] ?? $this->hash], 'submission' => null]; }
    public function problemUrl(int $problemId, string $contextType, string $contextId): string { return '/problems/' . $problemId; }
    public function roster(string $type, string $id): array { return $this->learners; }
}
function lesson(): array
{
    $stages = [];
    foreach (['predict', 'run', 'investigate', 'modify', 'make'] as $key) { $stages[] = ['key' => $key, 'label' => $key, 'prompt' => $key . ' 안내', 'questions' => [$key . '에서 관찰한 값은 무엇인가요?']]; }
    $stages[3] += ['task' => '두 정수의 합을 출력하세요.', 'starter_code' => "a, b = map(int, input().split())\nprint(a + b)\n",
        'tests' => [['input' => "2 3\n", 'expected_output' => "5\n"], ['input' => "0 1\n", 'expected_output' => "1\n"]]];
    $lesson = LessonSchema::normalize(['schema_version' => 1, 'language' => 'python', 'title' => '합계', 'objectives' => ['합을 계산한다.'],
        'demo' => ['code' => "print(3)\n", 'input' => '', 'expected_output' => "3\n", 'explanation' => '세 개를 센다.'],
        'stages' => $stages, 'support' => ['kind' => 'none'], 'verification' => ['status' => 'not_executed', 'note' => 'draft']], 'python', 'none');
    $results = [];
    foreach (LessonVerification::cases($lesson) as $case) { $results[$case['case_id']] = LessonVerification::compare(['status' => 'completed', 'verdict' => 'executed', 'actual_output' => $case['expected_output'], 'diagnostics' => ''], $case); }
    $lesson['verification'] = LessonVerification::metadata($lesson, $results, 0); return $lesson;
}
function activity(array $lesson): array
{
    return ['creator_user_id' => 10, 'title' => '합계 수업', 'context_type' => 'class', 'context_id' => 'class:7', 'context_revision' => 'rev1',
        'target_problem_id' => 100, 'target_title' => '덧셈', 'target_hash' => 'problem-one', 'support_kind' => 'none', 'support_problem_id' => null,
        'support_title' => '', 'support_hash' => '', 'support_is_target' => false, 'demo_code' => 'ai_generated', 'lesson' => $lesson,
        'status' => 'active', 'practice_required' => true, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
}
