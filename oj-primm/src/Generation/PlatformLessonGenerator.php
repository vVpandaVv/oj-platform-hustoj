<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use DomainException;
use Throwable;

final class PlatformLessonGenerator implements CurriculumLessonGenerator, ValidationRepairGenerator
{
    public const TASK_KEY = 'addon.oj_primm.generate_lesson';
    public const CONTRACT = 'primm_lesson.v1';

    public function __construct(private readonly \Closure $runtimeProvider) {}

    public static function instruction(): string
    {
        $path = dirname(__DIR__, 2) . '/resources/prompts/primm-lesson-v1.txt';
        $text = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($text) || str_starts_with($text, "\xEF\xBB\xBF")) { throw new DomainException('PRIMM 생성 지시문을 읽을 수 없습니다.'); }
        return LessonSchema::text($text, 16384, 'PRIMM 생성 지시문');
    }

    public function availability(): array
    {
        try {
            $snapshot = $this->runtime()->aiTasks()->snapshot(self::TASK_KEY);
            return ['available' => $snapshot->isAvailable(), 'message' => $snapshot->isAvailable()
                ? 'AI 생성 준비가 되었습니다. 전체 수업 자료를 받으려면 작업 배정의 최대 출력 토큰을 8192 이상으로 설정하는 것을 권장합니다.'
                : 'OJ AI 작업 배정에서 PRIMM 수업 생성의 모델 연결을 설정해 주세요.'];
        } catch (Throwable) { return ['available' => false, 'message' => 'OJ Platform의 PRIMM AI 연동을 사용할 수 없습니다.']; }
    }

    public function generate(int $actor, array $publicProblem, string $language, string $supportKind, string $idempotencyKey): array
    {
        if ($actor < 1 || ! in_array($language, LessonSchema::LANGUAGES, true) || ! in_array($supportKind, LessonSchema::KINDS, true)) { throw new DomainException('수업 생성 요청의 사용자·언어·도움 방식을 확인해 주세요.'); }
        return $this->execute($actor, $language, $supportKind, $idempotencyKey, [
            'operation' => 'generate', 'problem' => self::publicProblem($publicProblem), 'language' => $language, 'support_kind' => $supportKind,
            'constraints' => self::constraints(),
        ]);
    }

    public function repair(int $actor, array $publicProblem, array $lesson, array $verificationReport, string $idempotencyKey): array
    {
        if ($actor < 1) { throw new DomainException('수업을 수정할 교사 계정을 확인해 주세요.'); }
        $language = $lesson['language'] ?? '';
        $supportKind = $lesson['support']['kind'] ?? '';
        if (! is_string($language) || ! is_string($supportKind)) { throw new DomainException('수정할 수업의 언어와 도움 방식을 확인해 주세요.'); }
        $draft = LessonSchema::validateNormalized($lesson, $language, $supportKind);
        // Opaque IDs and system verdicts never become AI-authored output fields.
        if ($supportKind === 'code_ordering') {
            $draft['support'] = ['kind' => $supportKind, 'ordering_blocks' => array_column($draft['support']['blocks'], 'code')];
        }
        return $this->execute($actor, $language, $supportKind, $idempotencyKey, [
            'operation' => 'repair', 'problem' => self::publicProblem($publicProblem), 'language' => $language, 'support_kind' => $supportKind,
            'previous_lesson' => $draft, 'verification_feedback' => self::feedback($verificationReport),
            'constraints' => self::constraints(),
        ]);
    }

    public function generateForProfile(int $actor, array $publicProblem, string $language, string $kind, array $profile, string $key): array
    {
        if ($actor < 1 || ! in_array($language, LessonSchema::LANGUAGES, true) || ! in_array($kind, LessonSchema::KINDS, true)) { throw new DomainException('수업 생성 요청을 확인해 주세요.'); }
        return $this->execute($actor, $language, $kind, $key, ['operation' => 'generate', 'problem' => self::publicProblem($publicProblem),
            'language' => $language, 'support_kind' => $kind, 'class_profile' => self::profile($profile), 'constraints' => self::constraints()]);
    }
    public function repairForProfile(int $actor, array $publicProblem, array $lesson, array $report, array $profile, string $key): array
    {
        $draft = self::providerDraft($lesson);
        if ($actor < 1) { throw new DomainException('수업 수정 계정을 확인해 주세요.'); }
        return $this->execute($actor, $draft['language'], $draft['support']['kind'], $key, ['operation' => 'repair', 'problem' => self::publicProblem($publicProblem),
            'language' => $draft['language'], 'support_kind' => $draft['support']['kind'], 'class_profile' => self::profile($profile),
            'previous_lesson' => $draft, 'verification_feedback' => self::feedback($report), 'constraints' => self::constraints()]);
    }
    public function repairValidation(int $actor, array $publicProblem, string $language, string $kind,
        array $candidate, string $message, array $profile, string $key): array
    {
        if ($actor < 1 || ! in_array($language, LessonSchema::LANGUAGES, true) || ! in_array($kind, LessonSchema::KINDS, true)) { throw new DomainException('수업 형식을 복구할 요청을 확인해 주세요.'); }
        return $this->execute($actor, $language, $kind, $key, ['operation' => 'repair_validation',
            'problem' => self::publicProblem($publicProblem), 'language' => $language, 'support_kind' => $kind,
            'class_profile' => self::profile($profile), 'previous_draft' => LessonValidationFailure::safeCandidate($candidate),
            'validation_feedback' => ['code' => 'lesson_contract_invalid', 'message' => LessonSchema::text($message, 500, '형식 검사 진단'), 'execution_status' => 'not_executed'],
            'constraints' => self::constraints()]);
    }
    public function regenerate(int $actor, array $publicProblem, array $lesson, string $scope, array $profile, string $key): array
    {
        if ($actor < 1 || ! in_array($scope, ['all', 'predict', 'run', 'investigate', 'modify', 'make'], true)) { throw new DomainException('다시 생성할 단계를 확인해 주세요.'); }
        $draft = self::providerDraft($lesson);
        $result = $this->execute($actor, $draft['language'], $draft['support']['kind'], $key, ['operation' => 'regenerate', 'scope' => $scope,
            'problem' => self::publicProblem($publicProblem), 'language' => $draft['language'], 'support_kind' => $draft['support']['kind'],
            'class_profile' => self::profile($profile), 'previous_lesson' => $draft, 'constraints' => self::constraints()]);
        if ($scope === 'all') { return $result; }
        // Selective regeneration cannot silently change code, outputs, questions or help from another stage.
        $merged = LessonSchema::validateNormalized($lesson, $lesson['language'], $lesson['support']['kind']);
        foreach ($merged['stages'] as $index => $stage) { if ($stage['key'] === $scope) { $merged['stages'][$index] = $result['stages'][$index]; } }
        if ($scope === 'modify') { $merged['support'] = $result['support']; }
        return LessonSchema::validateNormalized($merged, $merged['language'], $merged['support']['kind']);
    }
    private static function providerDraft(array $lesson): array
    {
        $language = $lesson['language'] ?? ''; $kind = $lesson['support']['kind'] ?? '';
        if (! is_string($language) || ! is_string($kind)) { throw new DomainException('수정할 수업 자료를 확인해 주세요.'); }
        $draft = LessonSchema::validateNormalized($lesson, $language, $kind);
        if ($kind === 'code_ordering') { $draft['support'] = ['kind' => $kind, 'ordering_blocks' => array_column($draft['support']['blocks'], 'code')]; }
        return $draft;
    }
    private static function profile(array $profile): array
    {
        $safe = \OjPrimm\Application\ClassSettingsService::normalize(array_intersect_key($profile, array_flip(['default_language', 'language', 'level', 'prerequisites', 'minutes', 'support_kind', 'auto_prepare', 'auto_publish'])));
        return array_intersect_key($safe, array_flip(['level', 'prerequisites', 'minutes']));
    }

    private function execute(int $actor, string $language, string $supportKind, string $idempotencyKey, array $input): array
    {
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 1048576) { throw new DomainException('수업 생성·수정 요청이 AI 입력 크기를 초과합니다. 문제 설명을 줄여 주세요.'); }
        $gateway = $this->runtime()->aiTasks();
        $before = $gateway->snapshot(self::TASK_KEY);
        if (! $before->isAvailable()) { throw new DomainException('OJ AI 작업 배정에서 PRIMM 수업 생성의 모델 연결을 설정해 주세요.'); }
        $result = $gateway->execute(new AddonAiTaskRequestV1(self::TASK_KEY, self::CONTRACT, $actor, $idempotencyKey, $input));
        if (! $result->isSuccessful()) {
            if ($result->problem()?->retryable()) { throw new TransientGenerationException($result->problem()->message(), 0, $result->problem()->code()); }
            throw new GenerationFailure($result->problem()?->message() ?? 'AI가 PRIMM 수업 초안을 생성하지 못했습니다.', $result->problem()?->code() ?? 'ai_failure', false);
        }
        $used = $result->routingSnapshot();
        $after = $gateway->snapshot(self::TASK_KEY);
        if (! $used->isAvailable() || ! $after->isAvailable() || $used->taskKey() !== self::TASK_KEY || $after->taskKey() !== self::TASK_KEY
            || ! hash_equals($before->routingRevision(), $used->routingRevision()) || ! hash_equals($before->routingRevision(), $after->routingRevision())
            || ! hash_equals($before->profileFingerprint(), $used->profileFingerprint()) || ! hash_equals($before->profileFingerprint(), $after->profileFingerprint())) {
            throw new DomainException('생성 중 AI 작업 배정이 변경되었습니다. 새 설정으로 다시 생성해 주세요.');
        }
        try { return LessonSchema::normalize($result->output(), $language, $supportKind); }
        catch (DomainException $error) { throw new LessonValidationFailure($error->getMessage(), $result->output()); }
    }

    private static function constraints(): array
    {
        return ['target_role' => 'make_only', 'verification' => 'not_executed', 'support_source' => 'generated_modify_activity',
            'verification_authority' => 'server_judge_only', 'repair_scope' => 'complete_lesson_and_support'];
    }

    /** Only diagnostic evidence for generated examples may be sent back to AI. */
    private static function feedback(array $report): array
    {
        $attempt = $report['attempt'] ?? null;
        $failures = $report['failures'] ?? null;
        if (! is_int($attempt) || $attempt < 1 || $attempt > 10 || ! is_array($failures) || ! array_is_list($failures)
            || count($failures) < 1 || count($failures) > 9) { throw new DomainException('수업 수정에 필요한 채점 실패 기록을 확인해 주세요.'); }
        $safe = [];
        foreach ($failures as $failure) {
            if (! is_array($failure) || ! in_array($failure['phase'] ?? '', ['demo', 'modify'], true)
                || ! is_string($failure['case_id'] ?? null) || preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $failure['case_id']) !== 1) { throw new DomainException('채점 실패 사례의 종류와 번호를 확인해 주세요.'); }
            $item = ['case_id' => $failure['case_id'], 'phase' => $failure['phase'],
                'input' => LessonSchema::text($failure['input'] ?? '', 4096, '실패 사례 입력', true),
                'expected_output' => LessonSchema::text($failure['expected_output'] ?? '', 4096, '실패 사례 예상 출력', true),
                'actual_output' => LessonSchema::text($failure['actual_output'] ?? '', 16384, '실제 실행 출력', true),
                'verdict' => LessonSchema::text($failure['verdict'] ?? null, 80, '실패 판정')];
            foreach (['compiler_output', 'runtime_error'] as $key) {
                if (array_key_exists($key, $failure)) { $item[$key] = LessonSchema::text($failure[$key], 16384, '실패 진단', true); }
            }
            $safe[] = $item;
        }
        return ['attempt' => $attempt, 'failures' => $safe];
    }

    /** Never transmit a submitted solution, wrapper, test file, learner or arbitrary input field. */
    private static function publicProblem(array $problem): array
    {
        if (! is_int($problem['id'] ?? null) || $problem['id'] < 1) { throw new DomainException('생성할 목표 문제 번호를 확인해 주세요.'); }
        $safe = ['id' => $problem['id']];
        foreach (['title', 'description', 'input', 'output', 'sample_input', 'sample_output'] as $key) {
            $safe[$key] = LessonSchema::text($problem[$key] ?? '', $key === 'title' ? 1000 : 200000, '공개 문제 ' . $key, $key !== 'title');
        }
        if (strlen(json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 900000) { throw new DomainException('목표 문제의 공개 자료가 생성 입력 크기를 초과합니다.'); }
        return $safe;
    }

    private function runtime(): AddonRuntimeContextV1
    {
        $runtime = ($this->runtimeProvider)();
        if (! $runtime instanceof AddonRuntimeContextV1 || $runtime->snapshot()->status() !== 'accepted') { throw new DomainException('PRIMM의 승인된 플랫폼 연동이 준비되지 않았습니다.'); }
        return $runtime;
    }
}
