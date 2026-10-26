<?php
declare(strict_types=1);
namespace OjPrimm\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSubmissionDraftRuntimeContextV1;
use DomainException;
use OjPrimm\Application\PlatformAccess;
use OjPrimm\Application\ExerciseAccess;
use OjPrimm\Application\ActorRevalidationAccess;
use OjPrimm\Application\RosterAccess;
use OjPrimm\Application\SubmissionDraftAccess;
use OjPrimm\Application\SubmissionLookupAccess;

final class PlatformGateway implements PlatformAccess, ExerciseAccess, ActorRevalidationAccess, RosterAccess, SubmissionDraftAccess, SubmissionLookupAccess
{
    public function __construct(private readonly \Closure $runtimeProvider) {}

    public function actorId(): int
    {
        $id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($id < 1) { throw new DomainException('로그인이 필요합니다.'); }
        return $id;
    }

    public function assertRole(string $role): void
    {
        $this->actorId();
        $this->runtime();
        $key = $role === 'teach' ? 'oj_primm_teach' : 'oj_primm_learn';
        if (! current_user_can(AddonCapabilityKeyV1::wordpress($key)) && ! current_user_can('manage_options')) {
            throw new DomainException('이 PRIMM 기능을 사용할 권한이 없습니다.');
        }
    }

    public function refreshActor(): void
    {
        if (! function_exists('clean_user_cache') || ! function_exists('wp_roles') || ! function_exists('wp_set_current_user')) { return; }
        $actor = $this->actorId();
        clean_user_cache($actor);
        wp_cache_delete($actor, 'user_meta');
        $roles = wp_roles();
        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')
            && isset($wpdb->options) && function_exists('add_filter') && function_exists('remove_filter')) {
            // Reload revocations directly without evicting every site's autoloaded options.
            $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $roles->role_key));
            if ((string) ($wpdb->last_error ?? '') !== '' || ! is_string($stored)) { throw new DomainException('현재 역할 권한을 다시 확인하지 못했습니다.'); }
            $fresh = maybe_unserialize($stored);
            if (! is_array($fresh)) { throw new DomainException('현재 역할 권한을 다시 확인하지 못했습니다.'); }
            $filter = static fn () => $fresh;
            add_filter('pre_option_' . $roles->role_key, $filter, PHP_INT_MAX);
            try { $roles->for_site(); }
            finally { remove_filter('pre_option_' . $roles->role_key, $filter, PHP_INT_MAX); }
        } else {
            wp_cache_delete($roles->role_key, 'options');
            $roles->for_site();
        }
        // Setting the same ID would reuse the old WP_User instance.
        wp_set_current_user(0);
        wp_set_current_user($actor);
    }

    public function contexts(bool $teaching): array
    {
        $actor = $this->actorId();
        $gateway = $this->runtime()->contexts();
        $items = [];
        $cursor = '';
        for ($page = 0; $page < 20; ++$page) {
            if ($teaching) {
                $result = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1($actor, ['class', 'group'], '', $cursor, 100));
            } elseif ($gateway instanceof AddonLearningContextGatewayV1) {
                $result = $gateway->listLearningContexts(new AddonLearningContextQueryV1($actor, ['class', 'group'], '', $cursor, 100));
            } else { throw new DomainException('플랫폼의 학습 공간 조회를 사용할 수 없습니다.'); }
            if (! $result->isSuccessful()) { throw new DomainException('학습 공간을 확인하지 못했습니다.'); }
            foreach ($result->items() as $item) {
                $items[] = ['type' => $item->type(), 'id' => $item->id(), 'label' => $item->label(), 'revision' => $item->revision()];
            }
            $cursor = $result->nextCursor();
            if ($cursor === '') { return $items; }
        }
        throw new DomainException('학습 공간이 너무 많습니다. 관리자가 범위를 확인해 주세요.');
    }

    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array
    {
        $decision = $this->runtime()->contexts()->authorize(new AddonContextAccessRequestV1(
            $this->actorId(), $type, $id, $operation, $learnerId
        ));
        $snapshot = $decision->snapshot();
        if (! $decision->isAllowed() || $snapshot === null || $snapshot->lifecycleState() !== 'active') {
            throw new DomainException('현재 수업·그룹의 권한과 소속을 확인해 주세요.');
        }
        return ['type' => $snapshot->type(), 'id' => $snapshot->id(), 'label' => $snapshot->label(), 'revision' => $snapshot->revision()];
    }

    public function searchProblems(string $search): array
    {
        $runtime = $this->runtime();
        if (! $runtime instanceof AddonIntegrationRuntimeContextV1) { throw new DomainException('문제 검색을 사용할 수 없습니다.'); }
        $result = $runtime->integrations()->searchProblems(new AddonProblemQueryV1($this->actorId(), $search, 50));
        if (! $result->isSuccessful()) { throw new DomainException('문제를 조회하지 못했습니다.'); }
        return array_map(static fn ($item): array => ['id' => $item->problemId(), 'title' => $item->title()], $result->items());
    }
    public function roster(string $type, string $id): array
    {
        $this->assertRole('teach'); $context = $this->authorize($type, $id, 'report');
        $gateway = $this->runtime()->contexts();
        if (! $gateway instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1) { throw new DomainException('플랫폼의 학급 명단 조회를 사용할 수 없습니다.'); }
        $cursor = ''; $revision = null; $items = [];
        do {
            $page = $gateway->listAssignmentLearners(new \CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1(
                $this->actorId(), $type, $id, $context['revision'], $revision, $cursor, 100));
            if (! $page->isSuccessful()) { throw new DomainException('명단이 변경되었거나 조회 권한이 없습니다. 보고서를 다시 열어 주세요.'); }
            foreach ($page->items() as $learner) { $items[] = ['user_id' => $learner->learnerUserId(),
                'display_name' => $learner->displayName(), 'management_name' => $learner->managementName(),
                'membership_revision' => $learner->membershipRevision()]; }
            $revision = $page->rosterRevision(); $cursor = $page->nextCursor();
        } while ($cursor !== '');
        $this->authorize($type, $id, 'report'); return $items;
    }

    public function problem(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array
    {
        return $this->learningCheck($problemId, $contextType, $contextId, $submissionId, $submissionId === null ? 'problem' : 'latest');
    }

    public function submission(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array
    {
        return $this->learningCheck($problemId, $contextType, $contextId, $submissionId, 'latest');
    }

    private function learningCheck(int $problemId, string $contextType, string $contextId, ?int $submissionId, string $projection): array
    {
        $runtime = $this->runtime();
        if (! $runtime instanceof AddonLearningCheckRuntimeContextV1) { throw new DomainException('문제 학습 자료를 사용할 수 없습니다.'); }
        $arguments = [$this->actorId(), $problemId, $submissionId, $contextType, (int) substr($contextId, strlen($contextType) + 1)];
        // Older supported platform releases keep their original full projection.
        if (method_exists(AddonLearningCheckContextQueryV1::class, 'projection')) { $arguments[] = $projection; }
        $result = $runtime->learningChecks()->context(new AddonLearningCheckContextQueryV1(...$arguments));
        if (! $result->isSuccessful()) { throw new DomainException('이 문제 또는 본인 제출을 확인할 수 없습니다.'); }
        return $result->payload();
    }

    public function assertSupport(int $problemId, string $kind, string $contextType, string $contextId): void
    {
        $runtime = $this->runtime();
        $id = (int) substr($contextId, strlen($contextType) + 1);
        if ($kind === 'code_ordering' && $runtime instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1) {
            $result = $runtime->codeOrdering()->puzzle(new \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1($this->actorId(), $problemId, $contextType, $id));
        } elseif ($kind === 'code_completion' && $runtime instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1) {
            $result = $runtime->codeCompletion()->template(new \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1($this->actorId(), $problemId, $contextType, $id));
        } else { throw new DomainException('선택한 지원 활동의 플랫폼 연동을 사용할 수 없습니다.'); }
        if (! $result->isSuccessful()) { throw new DomainException('지원 문제가 선택한 배열·빈칸 방식인지, 수업에서 접근 가능한지 확인해 주세요.'); }
    }

    public function problemUrl(int $problemId, string $contextType, string $contextId): string
    {
        $numericId = (int) substr($contextId, strlen($contextType) + 1);
        return '/problems/' . $problemId . '?' . http_build_query([$contextType . '_id' => $numericId]);
    }

    public function prepareSubmissionDraft(int $problemId, string $contextType, string $contextId, string $language, string $source): array
    {
        $runtime = $this->runtime();
        if (! $runtime instanceof AddonSubmissionDraftRuntimeContextV1) { throw new DomainException('제출 편집기 연동을 사용할 수 없습니다. 플랫폼 설치 상태를 확인해 주세요.'); }
        $result = $runtime->submissionDrafts()->prepare(new AddonSubmissionDraftRequestV1(
            $this->actorId(), $problemId, $contextType, (int) substr($contextId, strlen($contextType) + 1), $language, $source
        ));
        if (! $result->isSuccessful()) { throw new DomainException('코드 초안을 제출 편집기로 전달하지 못했습니다. 현재 수업과 제출 언어를 확인한 뒤 다시 시도해 주세요.'); }
        return $result->payload();
    }

    public function support(int $problemId, string $kind, string $contextType, string $contextId): array
    {
        $runtime = $this->runtime();
        $id = (int) substr($contextId, strlen($contextType) + 1);
        if ($kind === 'code_ordering' && $runtime instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1) {
            $result = $runtime->codeOrdering()->puzzle(new \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingQueryV1($this->actorId(), $problemId, $contextType, $id));
        } elseif ($kind === 'code_completion' && $runtime instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1) {
            $result = $runtime->codeCompletion()->template(new \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionQueryV1($this->actorId(), $problemId, $contextType, $id));
        } else { throw new DomainException('플랫폼의 도움 활동 연동을 사용할 수 없습니다.'); }
        if (! $result->isSuccessful()) { throw new DomainException($result->problem()?->message() ?? '도움 활동을 조회하지 못했습니다.'); }
        return $result->payload();
    }
    public function assembleSupport(int $problemId, string $kind, string $contextType, string $contextId, array $answer): array
    {
        $runtime = $this->runtime();
        $id = (int) substr($contextId, strlen($contextType) + 1);
        $hash = is_string($answer['template_hash'] ?? null) ? $answer['template_hash'] : '';
        if ($kind === 'code_ordering' && $runtime instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingRuntimeContextV1) {
            $ids = $answer['block_ids'] ?? [];
            if (! is_array($ids)) { throw new DomainException('모든 코드 조각을 한 번씩 배열해 주세요.'); }
            $result = $runtime->codeOrdering()->assemble(new \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingAnswerV1($this->actorId(), $problemId, $hash, $ids, $contextType, $id));
        } elseif ($kind === 'code_completion' && $runtime instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionRuntimeContextV1) {
            $answers = $answer['answers'] ?? [];
            if (! is_array($answers)) { throw new DomainException('빈칸 응답을 확인해 주세요.'); }
            $result = $runtime->codeCompletion()->assemble(new \CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionAnswerV1($this->actorId(), $problemId, $hash, $answers, $contextType, $id));
        } else { throw new DomainException('플랫폼의 도움 활동 연동을 사용할 수 없습니다.'); }
        if (! $result->isSuccessful()) { throw new DomainException($result->problem()?->message() ?? '도움 답안을 복원하지 못했습니다.'); }
        return $result->payload();
    }

    private function runtime(): AddonRuntimeContextV1
    {
        $runtime = ($this->runtimeProvider)();
        if (! $runtime instanceof AddonRuntimeContextV1 || $runtime->snapshot()->status() !== 'accepted') {
            throw new DomainException('OJ Platform 연동이 준비되지 않았습니다. 관리자가 플러그인 상태를 확인해 주세요.');
        }
        return $runtime;
    }
}
