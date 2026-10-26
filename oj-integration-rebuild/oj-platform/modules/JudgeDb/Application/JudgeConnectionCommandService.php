<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Application;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeReadinessReport;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeSchemaExtensionInstaller;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProof;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use DomainException;
use JsonException;
use Throwable;

final class JudgeConnectionCommandService
{
    private const PROOF_SCOPE = 'judge_database';

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SecretVault $vault,
        private readonly CandidateProofStore $proofs,
        private readonly JudgeConnectionProbe $probe,
        private readonly JudgeConnectionSettingsProvider $active,
        private readonly JudgeSchemaExtensionInstaller $extensions
    ) {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('judge_database');
    }

    public function probeSnapshot(): SettingsSnapshot
    {
        return $this->settings->read('judge_database_probe', []);
    }

    public function current(): ?JudgeConnectionSettings
    {
        return $this->active->current();
    }

    public function testCandidate(
        JudgeConnectionSettings $candidate,
        int $actorUserId,
        int $expectedProbeRevision
    ): CandidateProof {
        if (! $candidate->utcStorageConfirmed()) {
            throw new DomainException('기존 제출 기록이 UTC로 저장되어 있는지 먼저 확인해 주세요.');
        }
        $report = $this->probe->inspect($candidate);
        if (! $report->isReady() && ! $this->extensions->canProvision($report)) {
            throw new DomainException($this->failureMessage($report));
        }
        $pendingExtensions = $this->extensions->pendingColumns($report);
        $existing = $this->proofs->find(self::PROOF_SCOPE, $actorUserId);
        if ($existing !== null) {
            $this->proofs->forget(self::PROOF_SCOPE, $actorUserId);
        }
        $payload = $this->payload($candidate);
        $fingerprint = $this->fingerprint($payload);
        $reference = $this->vault->store($payload);
        $safeResult = [
            'result_code' => 'judge_probe_succeeded',
            'present_optional_tables' => $report->presentOptionalTables(),
            'present_ignored_tables' => $report->presentIgnoredTables(),
            'table_engines' => $report->tableEngines(),
            'single_account' => $candidate->usesSingleAccount(),
            'tls_enabled' => $candidate->tlsEnabled(),
            'timestamp_contract' => 'utc-v1',
            'timestamp_confirmed_by' => $actorUserId,
            'pending_extension_columns' => $pendingExtensions,
            'tested_at' => gmdate('c'),
        ];
        $proof = new CandidateProof(
            $fingerprint,
            $reference,
            $actorUserId,
            time() + 900,
            $safeResult
        );
        $remembered = false;
        try {
            $this->proofs->remember(self::PROOF_SCOPE, $proof);
            $remembered = true;
            $this->settings->save(
                'judge_database_probe',
                $safeResult + [
                    'candidate_fingerprint' => $fingerprint,
                    'expires_at' => $proof->expiresAt(),
                ],
                $expectedProbeRevision
            );
        } catch (Throwable $error) {
            if ($remembered) {
                $this->proofs->forget(self::PROOF_SCOPE, $actorUserId);
            } else {
                $this->vault->discard($reference);
            }
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
            throw new DomainException('Judge DB 설정이 다른 요청에서 변경되었습니다.');
        }
        $proof = $this->proofs->consume(self::PROOF_SCOPE, $actorUserId);
        if ($proof === null || $proof->isExpired(time())) {
            throw new DomainException('유효한 Judge DB 후보 검사 결과가 없습니다.');
        }
        try {
            $payload = $this->vault->reveal($proof->payloadReference());
            if (
                ! is_array($payload)
                || ! hash_equals($proof->fingerprint(), $this->fingerprint($payload))
            ) {
                throw new DomainException('Judge DB 후보 검사 결과가 현재 후보와 일치하지 않습니다.');
            }
            $candidate = $this->fromPayload($payload);
            if (! $candidate->utcStorageConfirmed()) {
                throw new DomainException('제출 기록의 UTC 저장 확인 후 후보 검사를 다시 실행해 주세요.');
            }
            $report = $this->probe->inspect($candidate);
            if (! $report->isReady()) {
                if (! $this->extensions->canProvision($report)) {
                    throw new DomainException($this->failureMessage($report));
                }
                try {
                    $this->extensions->provision($candidate);
                } catch (Throwable $error) {
                    throw new DomainException(
                        'Judge DB 문제 확장 필드를 생성하지 못했습니다. '
                        . '쓰기 계정의 ALTER 권한을 확인해 주세요.',
                        0,
                        $error
                    );
                }
                $report = $this->probe->inspect($candidate);
                if (! $report->isReady()) {
                    throw new DomainException($this->failureMessage($report));
                }
            }
            $saved = $this->settings->save(
                'judge_database',
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

    /**
     * @return array<string, mixed>
     */
    private function payload(JudgeConnectionSettings $settings): array
    {
        return [
            'host' => $settings->host(),
            'port' => $settings->port(),
            'database' => $settings->database(),
            'timestamp_contract' => $settings->utcStorageConfirmed() ? 'utc-v1' : null,
            'read' => [
                'username' => $settings->readCredentials()->username(),
                'password' => $settings->readCredentials()->password(),
            ],
            'write' => [
                'username' => $settings->writeCredentials()->username(),
                'password' => $settings->writeCredentials()->password(),
            ],
            'tls' => [
                'enabled' => $settings->tlsEnabled(),
                'ca_path' => $settings->tlsCaPath(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fromPayload(array $payload): JudgeConnectionSettings
    {
        $read = is_array($payload['read'] ?? null) ? $payload['read'] : [];
        $write = is_array($payload['write'] ?? null) ? $payload['write'] : [];
        $tls = is_array($payload['tls'] ?? null) ? $payload['tls'] : [];
        $caPath = trim((string) ($tls['ca_path'] ?? ''));

        return new JudgeConnectionSettings(
            trim((string) ($payload['host'] ?? '')),
            (int) ($payload['port'] ?? 3306),
            trim((string) ($payload['database'] ?? 'jol')),
            new JudgeCredentials(
                trim((string) ($read['username'] ?? '')),
                (string) ($read['password'] ?? '')
            ),
            new JudgeCredentials(
                trim((string) ($write['username'] ?? '')),
                (string) ($write['password'] ?? '')
            ),
            filter_var($tls['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            $caPath !== '' ? $caPath : null,
            ($payload['timestamp_contract'] ?? null) === 'utc-v1'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fingerprint(array $payload): string
    {
        $safe = $payload;
        foreach (['read', 'write'] as $key) {
            if (is_array($safe[$key] ?? null)) {
                $safe[$key]['password'] = hash(
                    'sha256',
                    (string) ($safe[$key]['password'] ?? '')
                );
            }
        }
        try {
            return hash('sha256', json_encode(
                $safe,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $error) {
            throw new DomainException(
                'Judge DB 후보 fingerprint를 만들 수 없습니다.',
                0,
                $error
            );
        }
    }

    private function failureMessage(JudgeReadinessReport $report): string
    {
        if ($report->missingRequiredTables() !== []) {
            return 'Judge DB에 필수 채점 테이블이 없습니다: '
                . implode(', ', $report->missingRequiredTables());
        }
        if ($report->missingCompatibilityTables() !== []) {
            return '제출 기능에 필요한 Judge 호환 테이블이 없습니다: '
                . implode(', ', $report->missingCompatibilityTables());
        }
        if ($report->missingRequiredColumns() !== []) {
            $items = [];
            foreach ($report->missingRequiredColumns() as $table => $columns) {
                $items[] = $table . '(' . implode(', ', $columns) . ')';
            }

            return 'Judge DB 필수 열이 없습니다: ' . implode('; ', $items);
        }
        if ($report->invalidColumnTypes() !== []) {
            return 'Judge DB 필수 열 타입이 호환되지 않습니다: '
                . implode(', ', $report->invalidColumnTypes());
        }
        if ($report->errors() !== []) {
            return 'Judge DB 연결 또는 엔진 검사를 통과하지 못했습니다: '
                . implode(', ', $report->errors());
        }

        return 'Judge DB 후보 준비 검사를 통과하지 못했습니다.';
    }
}
