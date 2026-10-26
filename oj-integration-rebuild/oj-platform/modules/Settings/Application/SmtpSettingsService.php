<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProof;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SmtpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SmtpSettings;
use DomainException;
use Throwable;

final class SmtpSettingsService
{
    private const PROOF_SCOPE = 'smtp';

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SecretVault $vault,
        private readonly CandidateProofStore $proofs,
        private readonly SmtpTransport $transport
    ) {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('smtp');
    }

    public function current(): ?SmtpSettings
    {
        $reference = $this->snapshot()->value();
        if ($reference === null) {
            return null;
        }
        if (! $reference instanceof SecretReference) {
            throw new DomainException('SMTP 설정 비밀값 참조가 올바르지 않습니다.');
        }
        $payload = $this->vault->reveal($reference);

        return is_array($payload) ? SmtpSettings::fromArray($payload) : null;
    }

    public function testCandidate(
        SmtpSettings $candidate,
        string $recipient,
        int $actorUserId
    ): CandidateProof {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('시험 메일 수신 주소가 올바르지 않습니다.');
        }
        $existing = $this->proofs->find(self::PROOF_SCOPE, $actorUserId);
        if ($existing !== null) {
            $this->proofs->forget(self::PROOF_SCOPE, $actorUserId);
        }
        if (! $this->transport->sendTest(
            $candidate,
            $recipient,
            'OJ Platform SMTP 후보 검사',
            'SMTP 후보 연결이 정상적으로 동작합니다.'
        )) {
            throw new DomainException('SMTP 후보 설정으로 시험 메일을 보내지 못했습니다.');
        }
        $reference = $this->vault->store($candidate->toArray());
        $proof = new CandidateProof(
            $candidate->fingerprint(),
            $reference,
            $actorUserId,
            time() + 900,
            [
                'result_code' => 'smtp_test_succeeded',
                'tested_at' => gmdate('c'),
                'recipient_domain' => substr(
                    strrchr(strtolower($recipient), '@') ?: '',
                    1
                ),
            ]
        );
        try {
            $this->proofs->remember(self::PROOF_SCOPE, $proof);
        } catch (Throwable $error) {
            $this->vault->discard($reference);
            throw $error;
        }

        return $proof;
    }

    public function applyTestedCandidate(
        int $actorUserId,
        int $expectedRevision
    ): SettingsSnapshot {
        $current = $this->snapshot();
        if ($current->revision() !== $expectedRevision) {
            throw new DomainException('SMTP 설정이 다른 요청에서 변경되었습니다.');
        }
        $proof = $this->proofs->consume(self::PROOF_SCOPE, $actorUserId);
        if ($proof === null || $proof->isExpired(time())) {
            throw new DomainException('유효한 SMTP 후보 검사 결과가 없습니다.');
        }
        try {
            $payload = $this->vault->reveal($proof->payloadReference());
            if (
                ! is_array($payload)
                || ! hash_equals(
                    $proof->fingerprint(),
                    SmtpSettings::fromArray($payload)->fingerprint()
                )
            ) {
                throw new DomainException('SMTP 후보 검사 결과가 현재 후보와 일치하지 않습니다.');
            }
            $saved = $this->settings->save(
                'smtp',
                $proof->payloadReference(),
                $expectedRevision
            );
        } catch (Throwable $error) {
            $this->vault->discard($proof->payloadReference());
            throw $error;
        }
        $oldReference = $current->value();
        if (
            $oldReference instanceof SecretReference
            && $oldReference->value() !== $proof->payloadReference()->value()
        ) {
            $this->vault->discard($oldReference);
        }

        return $saved;
    }

    public function proofFor(int $actorUserId): ?CandidateProof
    {
        return $this->proofs->find(self::PROOF_SCOPE, $actorUserId);
    }
}
