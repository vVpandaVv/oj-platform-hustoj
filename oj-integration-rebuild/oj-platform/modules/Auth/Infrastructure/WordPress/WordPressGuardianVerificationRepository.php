<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianVerificationChallenge;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;
use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianConsentReview;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship;
use RuntimeException;

final class WordPressGuardianVerificationRepository implements GuardianVerificationRepository
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function issue(
        string $childEmail,
        string $guardianName,
        string $guardianEmail,
        int $expiresHours,
        AgreementDocument $document
    ): GuardianVerificationChallenge {
        $childEmail = strtolower(trim($childEmail));
        $guardianEmail = strtolower(trim($guardianEmail));
        $guardianName = trim($guardianName);
        if (
            filter_var($childEmail, FILTER_VALIDATE_EMAIL) === false
            || filter_var($guardianEmail, FILTER_VALIDATE_EMAIL) === false
            || $guardianName === ''
            || $childEmail === $guardianEmail
            || $document->typeSlug() !== AgreementDocumentType::GuardianConsent->value
            || $expiresHours < 1
            || $expiresHours > 168
        ) {
            throw new RuntimeException('법정대리인 동의 요청 정보가 올바르지 않습니다.');
        }

        $token = bin2hex(random_bytes(32));
        $code = (string) random_int(10000000, 99999999);
        $expiresAt = new \DateTimeImmutable(
            sprintf('+%d hours', $expiresHours),
            new \DateTimeZone('UTC')
        );
        $result = $this->database()->insert(
            'guardian_verifications',
            [
                'user_id' => null,
                'child_email' => $childEmail,
                'guardian_name' => $guardianName,
                'guardian_email' => $guardianEmail,
                'document_id' => $document->documentId(),
                'document_version' => $document->version(),
                'document_sha256' => hash('sha256', $document->content()),
                'guardian_relationship' => null,
                'verification_method' => null,
                'token_hash' => hash('sha256', $token),
                'code_hash' => $this->codeHash($code),
                'status' => 'pending',
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'verified_at' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $verificationId = (int) ($this->database()->insert_id ?? 0);
        if ($result === false || $verificationId < 1) {
            throw new RuntimeException('법정대리인 동의 요청을 저장하지 못했습니다.');
        }

        return new GuardianVerificationChallenge(
            $verificationId,
            $token,
            $code,
            $expiresAt
        );
    }

    public function cancel(int $verificationId): void
    {
        if ($verificationId < 1) {
            return;
        }

        $this->database()->update(
            'guardian_verifications',
            ['status' => 'cancelled'],
            ['id' => $verificationId, 'status' => 'pending'],
            ['%s'],
            ['%d', '%s']
        );
    }

    public function pendingReview(string $token): ?GuardianConsentReview
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $database = $this->database();
        $row = $database->get_row($database->prepare(
            'SELECT g.child_email, g.guardian_name, g.guardian_email,'
            . ' d.id, d.version, d.title, d.content, d.required'
            . ' FROM `guardian_verifications` g'
            . ' INNER JOIN `agreement_documents` d ON d.id = g.document_id'
            . ' WHERE g.token_hash = %s AND g.status = %s AND g.expires_at >= %s'
            . ' AND ' . $this->documentCondition(true, 'g'),
            hash('sha256', $token),
            'pending',
            gmdate('Y-m-d H:i:s')
        ), 'ARRAY_A');

        return is_array($row) ? new GuardianConsentReview(
            (string) $row['child_email'],
            (string) $row['guardian_name'],
            (string) $row['guardian_email'],
            new AgreementDocument(
                (int) $row['id'], AgreementDocumentType::GuardianConsent->value,
                (string) $row['version'], (string) $row['title'],
                (string) $row['content'], (bool) $row['required']
            )
        ) : null;
    }

    public function confirm(string $token, int $documentId, GuardianRelationship $relationship): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1 || $documentId < 1) {
            return false;
        }

        $database = $this->database();
        $result = $database->query($database->prepare(
            'UPDATE `guardian_verifications`'
            . ' SET status = %s, verified_at = %s, guardian_relationship = %s, verification_method = %s'
            . ' WHERE token_hash = %s AND status = %s AND expires_at >= %s AND document_id = %d'
            . ' AND ' . $this->documentCondition(true),
            'verified',
            gmdate('Y-m-d H:i:s'),
            $relationship->value,
            'email_link',
            hash('sha256', $token),
            'pending',
            gmdate('Y-m-d H:i:s'),
            $documentId
        ));

        return $result === 1;
    }

    public function isVerified(string $childEmail, string $code): bool
    {
        if (preg_match('/^\d{8}$/', $code) !== 1) {
            return false;
        }

        $database = $this->database();
        $count = $database->get_var($database->prepare(
            'SELECT COUNT(*) FROM `guardian_verifications`'
            . ' WHERE child_email = %s AND code_hash = %s'
            . ' AND status = %s AND expires_at >= %s'
            . ' AND ' . $this->verifiedEvidenceCondition()
            . ' AND ' . $this->documentCondition(true),
            strtolower(trim($childEmail)),
            $this->codeHash($code),
            'verified',
            gmdate('Y-m-d H:i:s')
        ));

        return (int) $count === 1;
    }

    public function claimVerified(string $childEmail, string $code): ?int
    {
        if (preg_match('/^\d{8}$/', $code) !== 1) {
            return null;
        }

        $database = $this->database();
        $childEmail = strtolower(trim($childEmail));
        $codeHash = $this->codeHash($code);
        $result = $database->query($database->prepare(
            'UPDATE `guardian_verifications` SET status = %s'
            . ' WHERE child_email = %s AND code_hash = %s'
            . ' AND status = %s AND expires_at >= %s'
            . ' AND ' . $this->verifiedEvidenceCondition()
            . ' AND ' . $this->documentCondition(true),
            'claiming',
            $childEmail,
            $codeHash,
            'verified',
            gmdate('Y-m-d H:i:s')
        ));
        if ($result !== 1) {
            return null;
        }

        $verificationId = (int) $database->get_var($database->prepare(
            'SELECT id FROM `guardian_verifications`'
            . ' WHERE child_email = %s AND code_hash = %s AND status = %s LIMIT 1',
            $childEmail,
            $codeHash,
            'claiming'
        ));

        return $verificationId > 0 ? $verificationId : null;
    }

    public function bindClaim(int $verificationId, int $userId): void
    {
        if ($userId < 1 || $verificationId < 1) {
            throw new RuntimeException('법정대리인 확인 연결 정보가 올바르지 않습니다.');
        }
        $database = $this->database();
        $result = $database->query($database->prepare(
            'UPDATE `guardian_verifications` SET user_id = %d, status = %s'
            . ' WHERE id = %d AND status = %s AND user_id IS NULL AND expires_at >= %s'
            . ' AND ' . $this->verifiedEvidenceCondition()
            . ' AND ' . $this->documentCondition(true),
            $userId, 'consumed', $verificationId, 'claiming', gmdate('Y-m-d H:i:s')
        ));
        if ($result !== 1) {
            throw new RuntimeException('법정대리인 확인을 사용자 계정에 연결하지 못했습니다.');
        }
    }

    public function releaseClaim(int $verificationId): void
    {
        if ($verificationId < 1) {
            return;
        }

        $this->database()->update(
            'guardian_verifications',
            ['status' => 'verified'],
            ['id' => $verificationId, 'status' => 'claiming'],
            ['%s'],
            ['%d', '%s']
        );
    }

    public function hasConsumedVerification(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $database = $this->database();
        $count = $database->get_var($database->prepare(
            'SELECT COUNT(*) FROM `guardian_verifications`'
            . ' WHERE user_id = %d AND status = %s'
            . ' AND ' . $this->verifiedEvidenceCondition()
            . ' AND ' . $this->documentCondition(false),
            $userId,
            'consumed'
        ));

        return (int) $count > 0;
    }

    private function documentCondition(bool $current, string $alias = 'guardian_verifications'): string
    {
        // Keep version checks inside the state-changing SQL to close publication races.
        return 'EXISTS (SELECT 1 FROM `agreement_documents` proof'
            . ' INNER JOIN `agreement_document_types` kind ON kind.id = proof.type_id'
            . ' WHERE proof.id = ' . $alias . '.document_id'
            . ' AND proof.version = ' . $alias . '.document_version'
            . ' AND SHA2(proof.content, 256) = ' . $alias . '.document_sha256'
            . " AND kind.slug = '" . AgreementDocumentType::GuardianConsent->value . "'"
            . ($current ? ' AND proof.active = 1 AND kind.enabled = 1' : '') . ')';
    }

    private function verifiedEvidenceCondition(): string
    {
        return "verified_at IS NOT NULL AND verification_method = 'email_link'"
            . " AND guardian_relationship IN ('parent', 'legal_guardian')";
    }

    private function database(): object
    {
        if (
            ! method_exists($this->database, 'prepare')
            || ! method_exists($this->database, 'get_row')
            || ! method_exists($this->database, 'get_var')
            || ! method_exists($this->database, 'query')
            || ! method_exists($this->database, 'insert')
            || ! method_exists($this->database, 'update')
        ) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        return $this->database;
    }

    private function codeHash(string $code): string
    {
        $key = function_exists('wp_salt')
            ? wp_salt('auth')
            : 'oj-platform-guardian-code';

        return hash_hmac('sha256', $code, $key);
    }
}
