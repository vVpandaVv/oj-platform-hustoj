<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\PreparedSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionCreationResult;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DomainException;
use Throwable;

final class SubmitSubmissionService
{
    private ?\CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionResearchRecorder $research = null;

    public function attachResearch(\CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionResearchRecorder $research): void
    {
        if ($this->research !== null) { throw new \LogicException('Research recorder already attached.'); }
        $this->research = $research;
    }
    public function __construct(
        private readonly SubmissionProblemProvider $problems,
        private readonly SubmissionGateway $submissions,
        private readonly SubmissionContextStore $contextStore,
        private readonly SubmissionEventStore $events,
        private readonly SubmissionSettingsProvider $settings,
        private readonly SubmissionSourceModeRegistry $sourceModes,
        private readonly SubmissionSourcePolicyValidator $sourcePolicy,
        private readonly SubmissionContextProviderRegistry $contexts,
        private readonly Clock $clock,
        private readonly ErrorReporter $errors
    ) {
    }

    public function submit(
        SubmitSubmissionCommand $command,
        ?\Closure $consumeRequest = null
    ): SubmissionCreationResult {
        $reservation = $this->events->reserve(
            $command->requestKey(),
            $command->actorUserId(),
            [
                'problem_id' => $command->problemId(),
                'submission_mode' => $command->mode()->value,
            ]
        );
        if (! $reservation->isNew()) {
            if ($reservation->submissionId() !== null) {
                return new SubmissionCreationResult(
                    $reservation->submissionId(),
                    true
                );
            }
            throw new DomainException(
                $reservation->hasFailed()
                    ? '같은 제출 요청이 이미 실패했습니다. 제출 화면을 새로 고쳐 주세요.'
                    : '같은 제출 요청을 처리하고 있습니다.'
            );
        }

        $preparedId = null;
        $published = false;
        $recoveryRequired = false;
        $contextWritten = false;
        $metadata = [
            'problem_id' => $command->problemId(),
            'submission_mode' => $command->mode()->value,
        ];
        try {
            $context = $this->contexts->validate($command);
            $policy = $this->problems->codePolicy(
                $command->actorUserId(),
                $command->problemId(),
                $this->problemAccessContext($context)
            );
            $payload = $this->sourceModes->build(
                $command->mode()->value,
                new SubmissionSourceRequest(
                    $policy,
                    $command->languageId(),
                    $command->source(),
                    $command->templateHash(),
                    $command->completionAnswers(),
                    $command->blocklyWorkspace(),
                    $context->blocklyProfile()
                        ?? $command->blocklyProfile(),
                    $command->orderingBlockIds()
                )
            );
            $settings = $this->settings->current();
            $source = $this->sourcePolicy->validate(
                $payload,
                $policy,
                $settings
            );
            $this->assertSubmissionInterval(
                $command->userLogin(),
                $settings->cooldownSeconds()
            );
            if (
                ! $settings->allowResubmitAfterAccept()
                && $this->hasAccepted(
                    $command->userLogin(),
                    $command->problemId(),
                    $context
                )
            ) {
                throw new DomainException(
                    '이미 맞힌 문제의 재제출이 현재 설정에서 허용되지 않습니다.'
                );
            }

            $requestLimit = $this->settings->requestLimit();
            if ($requestLimit['enabled'] && $consumeRequest !== null) {
                $consumeRequest($requestLimit['count'], $requestLimit['seconds']);
            }

            $metadata += $source->metadata() + [
                'language_id' => $source->languageId(),
                'contest_id' => $context->contestId(),
                'class_id' => $context->classId(),
                'group_id' => $context->groupId(),
            ];
            $preparedId = $this->submissions->createPrepared(
                new PreparedSubmission(
                    $command->problemId(),
                    $command->userLogin(),
                    $this->nickname(
                        $command->displayName(),
                        $command->userLogin()
                    ),
                    $source->languageId(),
                    $this->ipAddress($command->ipAddress()),
                    strlen($source->judgeSource()),
                    $context->contestId(),
                    $context->contestProblemNumber()
                )
            );
            $this->contextStore->save($preparedId, $context);
            $contextWritten = $context->classId() !== null
                || $context->groupId() !== null;
            $this->events->record(
                'submission_prepared',
                $command->actorUserId(),
                $preparedId,
                null,
                '채점 전에 제출 정보를 준비했습니다.',
                $metadata
            );
            $this->submissions->storeSources(
                $preparedId,
                $source->storedUserSource(),
                $source->judgeSource()
            );
            if (! $this->submissions->sourcesMatch(
                $preparedId,
                hash('sha256', $source->storedUserSource()),
                hash('sha256', $source->judgeSource()),
                strlen($source->storedUserSource()),
                strlen($source->judgeSource())
            )) {
                throw new DomainException(
                    '저장된 제출 코드를 확인하지 못했습니다.'
                );
            }
            $this->events->record(
                'submission_source_verified',
                $command->actorUserId(),
                $preparedId,
                null,
                '사용자가 작성한 코드와 실제 채점할 코드의 저장 결과를 확인했습니다.',
                $metadata
            );

            $this->research?->bindSubmission($command->actorUserId(), $command->problemId(), $preparedId->value());
            try {
                $this->submissions->publish($preparedId);
                $published = true;
            } catch (Throwable $publishError) {
                try {
                    $this->submissions->markRecoveryRequired($preparedId);
                    $recoveryRequired = true;
                } catch (Throwable $recoveryError) {
                    $this->errors->report($recoveryError);
                }
                throw $publishError;
            }

            try {
                $this->events->complete(
                    $reservation->eventId(),
                    $preparedId,
                    $metadata
                );
            } catch (Throwable $auditError) {
                $this->errors->report($auditError);

                return new SubmissionCreationResult(
                    $preparedId,
                    false,
                    true
                );
            }

            return new SubmissionCreationResult($preparedId, false);
        } catch (Throwable $error) {
            if (
                $preparedId instanceof SubmissionId
                && ! $published
                && ! $recoveryRequired
            ) {
                try {
                    $this->submissions->discardPrepared($preparedId);
                    if ($contextWritten) {
                        try {
                            $this->contextStore->remove($preparedId);
                        } catch (Throwable $contextCleanupError) {
                            $this->errors->report($contextCleanupError);
                        }
                    }
                } catch (Throwable $cleanupError) {
                    $this->errors->report($cleanupError);
                    try {
                        $this->submissions->markRecoveryRequired($preparedId);
                        $recoveryRequired = true;
                    } catch (Throwable $recoveryError) {
                        $this->errors->report($recoveryError);
                    }
                }
            }
            try {
                $this->events->fail(
                    $reservation->eventId(),
                    $recoveryRequired
                        ? 'submission_recovery_required'
                        : 'submission_rejected',
                    $recoveryRequired
                        ? '제출 공개 상태를 운영자가 확인해야 합니다.'
                        : '제출 요청이 완료되지 않았습니다.',
                    $metadata + [
                        'failure_kind' => $error instanceof DomainException
                            ? 'policy'
                            : 'system',
                    ]
                );
            } catch (Throwable $eventError) {
                $this->errors->report($eventError);
            }

            throw $error;
        }
    }

    private function problemAccessContext(
        SubmissionContext $context
    ): ProblemAccessContext {
        if ($context->classId() !== null) {
            return ProblemAccessContext::class($context->classId());
        }
        if ($context->contestId() > 0) {
            return ProblemAccessContext::contest(
                $context->contestId(),
                $context->contestProblemNumber() >= 0
                    ? $context->contestProblemNumber()
                    : null
            );
        }

        return ProblemAccessContext::general();
    }

    private function hasAccepted(
        string $userLogin,
        int $problemId,
        SubmissionContext $context
    ): bool {
        if ($context->classId() === null && $context->groupId() === null) {
            return $this->submissions->hasAccepted(
                $userLogin,
                $problemId,
                $context
            );
        }

        $offset = 0;
        do {
            $ids = $this->contextStore->submissionIds(
                $context->classId(),
                $context->groupId(),
                500,
                $offset
            );
            if ($ids === []) {
                return false;
            }
            $summaries = $this->submissions->findSummaries(array_map(
                static fn (int $id): SubmissionId => new SubmissionId($id),
                $ids
            ));
            foreach ($summaries as $summary) {
                if (
                    $summary->userLogin() === $userLogin
                    && $summary->problemId() === $problemId
                    && $summary->contestId() === $context->contestId()
                    && $summary->resultCode() === 4
                    && $summary->isValid()
                ) {
                    return true;
                }
            }
            $offset += count($ids);
        } while (count($ids) === 500);

        return false;
    }

    private function assertSubmissionInterval(
        string $userLogin,
        int $cooldownSeconds
    ): void {
        if ($cooldownSeconds === 0) {
            return;
        }
        $latest = $this->submissions->latestSubmissionTimestamp($userLogin);
        if ($latest === null) {
            return;
        }
        $remaining = $cooldownSeconds
            - ($this->clock->now()->getTimestamp() - $latest);
        if ($remaining > 0) {
            throw new DomainException(
                $remaining . '초 뒤에 다시 제출할 수 있습니다.'
            );
        }
    }

    private function nickname(string $displayName, string $userLogin): string
    {
        $nickname = trim($displayName) === '' ? $userLogin : trim($displayName);
        if (function_exists('mb_substr')) {
            return mb_substr($nickname, 0, 20, 'UTF-8');
        }

        return substr($nickname, 0, 20);
    }

    private function ipAddress(string $value): string
    {
        $ip = trim($value);

        return filter_var($ip, FILTER_VALIDATE_IP) === false
            ? '0.0.0.0'
            : $ip;
    }
}
