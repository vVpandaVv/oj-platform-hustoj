<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionMode;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;
use DomainException;

/** Short-lived encrypted form preparation. It never creates or publishes a submission. */
final class SubmissionDraftService
{
    public const TTL_SECONDS = 300;
    public function __construct(private readonly FormDraftStore $drafts, private readonly CurrentAccountProvider $accounts,
        private readonly SubmissionProblemProvider $problems, private readonly SubmissionContextProviderRegistry $contexts,
        private readonly SubmissionSettingsProvider $settings, private readonly SubmissionLanguageCatalog $languages,
        private readonly ActionAccessGuard $guard) {}

    public function prepare(int $actor, int $problem, string $type, int $context, string $language, string $source): array
    {
        [$policy, $languageId] = $this->validate($actor, $problem, $type, $context, $language, $source);
        $this->guard->throttle('submission_draft_prepare', (string) $actor, 12, 60);
        $reference = $this->drafts->store('submission_source_handoff', $actor, 'submission_problem', (string) $problem,
            ['problem_id' => $problem, 'context_type' => $type, 'context_id' => $context, 'language' => $language,
                'language_id' => $languageId, 'source' => $source, 'policy_version' => $policy->policyVersion()], self::TTL_SECONDS);
        try {
            $this->validate($actor, $problem, $type, $context, $language, $source);
            $draft = $this->drafts->find($reference, $actor);
            if ($draft === null) { throw new DomainException('제출 초안 준비 시간이 지났습니다. 다시 열어 주세요.'); }
            return ['url' => '/submit/' . $problem . '?' . http_build_query([$type . '_id' => $context, 'draft' => $reference->value()], '', '&', PHP_QUERY_RFC3986),
                'language' => $language, 'expires_at' => $draft->expiresAt()];
        } catch (\Throwable $error) { $this->drafts->discard($reference, $actor); throw $error; }
    }

    /** Re-read within the TTL so a refresh does not consume or lose the prepared form. */
    public function load(string $reference, int $actor, int $problem, array $formContext): array
    {
        $this->identity($actor);
        try { $ref = new FormDraftReference($reference); }
        catch (\InvalidArgumentException) { throw new DomainException('제출 초안 연결이 올바르지 않습니다. 활동 화면에서 다시 열어 주세요.'); }
        $draft = $this->drafts->find($ref, $actor);
        if ($draft === null || $draft->draftType() !== 'submission_source_handoff' || $draft->targetType() !== 'submission_problem'
            || $draft->targetKey() !== (string) $problem) { throw new DomainException('취소되었거나 만료된 제출 초안입니다. 본인 활동에서 다시 열어 주세요.'); }
        $data = $draft->payload();
        $type = $data['context_type'] ?? ''; $context = $data['context_id'] ?? 0;
        if (($formContext['contest_id'] ?? 0) !== 0 || ! in_array($type, ['class', 'group'], true)
            || ($formContext[$type . '_id'] ?? null) !== $context
            || ($formContext[($type === 'class' ? 'group' : 'class') . '_id'] ?? null) !== null || ($data['problem_id'] ?? 0) !== $problem) {
            throw new DomainException('제출 초안의 문제·수업·모둠이 현재 화면과 다릅니다.');
        }
        [$policy, $languageId] = $this->validate($actor, $problem, $type, $context, (string) ($data['language'] ?? ''), (string) ($data['source'] ?? ''));
        if ($draft->expiresAt() <= time()) { throw new DomainException('제출 초안 준비 시간이 지났습니다. 활동에서 다시 열어 주세요.'); }
        if (($data['policy_version'] ?? null) !== $policy->policyVersion() || ($data['language_id'] ?? null) !== $languageId) {
            throw new DomainException('제출 문제의 코드 정책이 변경되었습니다. 활동에서 다시 열어 주세요.');
        }
        $data['storage_key'] = 'oj_submission_handoff_' . hash('sha256', json_encode([$actor, $problem, $type, $context, $languageId, $reference], JSON_THROW_ON_ERROR));
        return $data;
    }

    private function identity(int $actor): UserIdentity
    {
        $account = $this->accounts->current(); $identity = $account->identity();
        if ($actor < 1 || $identity === null || ! $account->isActive() || $identity->userId() !== $actor) { throw new DomainException('본인 활성 계정의 제출 초안만 열 수 있습니다.'); }
        return $identity;
    }
    private function validate(int $actor, int $problem, string $type, int $context, string $language, string $source): array
    {
        $identity = $this->identity($actor);
        if ($problem < 1 || $context < 1 || ! in_array($type, ['class', 'group'], true) || ! in_array($language, ['python', 'c', 'cpp', 'java'], true)
            || trim($source) === '' || str_contains($source, "\0") || preg_match('//u', $source) !== 1 || strlen($source) > min(65536, $this->settings->current()->maxSourceBytes())) {
            throw new DomainException('제출 초안의 코드·언어·크기·수업 정보를 확인해 주세요.');
        }
        $languageId = $this->languages->idForKey($language);
        $this->contexts->validate(new SubmitSubmissionCommand($actor, $identity->userLogin(), $identity->displayName(), $problem,
            SubmissionMode::Code, $languageId, $source, '', [], '', 'standard', bin2hex(random_bytes(32)), '', 0, -1,
            $type === 'class' ? $context : null, $type === 'group' ? $context : null));
        $access = $type === 'class' ? ProblemAccessContext::class($context) : ProblemAccessContext::general();
        if ($this->problems->findVisible($actor, $problem, $access) === null) { throw new DomainException('이 문제의 제출 초안에 접근할 수 없습니다.'); }
        $policy = $this->problems->codePolicy($actor, $problem, $access);
        if ($policy->entryMode() !== 'full_code' || ! $this->languages->isAllowed($languageId, $this->settings->current()->disabledLanguageMask(), $policy->disabledLanguageMask())) {
            throw new DomainException('이 문제의 작성 방식이나 허용 언어로는 코드 초안을 자동으로 채울 수 없습니다.');
        }
        $this->identity($actor);
        return [$policy, $languageId];
    }
}
