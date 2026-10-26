<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\AgreementDocument;
use RuntimeException;

final class WordPressAgreementRepository implements AgreementRepository
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function activeDocuments(): array
    {
        $rows = $this->database()->get_results(
            'SELECT d.id, t.slug, d.version, d.title, d.content, d.required'
            . ' FROM `agreement_documents` d'
            . ' INNER JOIN `agreement_document_types` t ON t.id = d.type_id'
            . ' WHERE d.active = 1 AND t.enabled = 1'
            . ' ORDER BY t.sort_order ASC, d.published_at DESC',
            'ARRAY_A'
        );

        return $this->hydrateRows($rows);
    }

    public function activeDocumentByType(string $typeSlug): ?AgreementDocument
    {
        $database = $this->database();
        $sql = $database->prepare(
            'SELECT d.id, t.slug, d.version, d.title, d.content, d.required'
            . ' FROM `agreement_documents` d'
            . ' INNER JOIN `agreement_document_types` t ON t.id = d.type_id'
            . ' WHERE d.active = 1 AND t.enabled = 1 AND t.slug = %s'
            . ' ORDER BY d.published_at DESC LIMIT 1',
            $typeSlug
        );
        $row = $database->get_row($sql, 'ARRAY_A');

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function recordAgreement(
        int $userId,
        array $documentIds,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $documents = [];
        foreach ($this->activeDocuments() as $document) {
            if (! $document->isForUserConsent()) {
                continue;
            }
            $documents[$document->documentId()] = $document;
        }

        foreach (array_values(array_unique(array_map('intval', $documentIds))) as $documentId) {
            $document = $documents[$documentId] ?? null;
            if (! $document instanceof AgreementDocument) {
                throw new RuntimeException('현재 게시된 동의 문서만 선택할 수 있습니다.');
            }

            $result = $this->database()->query($this->database()->prepare(
                'INSERT INTO `user_agreement_log`'
                . ' (user_id, document_id, document_version, ip_address, user_agent, agreed_at)'
                . ' VALUES (%d, %d, %s, %s, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE id = id',
                $userId,
                $documentId,
                $document->version(),
                $ipAddress,
                $userAgent,
                gmdate('Y-m-d H:i:s')
            ));

            if ($result === false) {
                throw new RuntimeException('사용자 동의 사건을 기록하지 못했습니다.');
            }
        }
    }

    public function agreedDocumentIds(int $userId): array
    {
        $database = $this->database();
        $sql = $database->prepare(
            'SELECT document_id FROM `user_agreement_log` WHERE user_id = %d',
            $userId
        );
        $values = $database->get_col($sql);

        return is_array($values)
            ? array_values(array_unique(array_map('intval', $values)))
            : [];
    }

    public function publish(
        string $typeSlug,
        string $typeLabel,
        string $version,
        string $title,
        string $content,
        bool $required,
        int $createdByUserId
    ): AgreementDocument {
        if (
            preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $typeSlug) !== 1
            || trim($typeLabel) === ''
            || trim($version) === ''
            || trim($title) === ''
            || trim($content) === ''
        ) {
            throw new RuntimeException('동의 문서 입력값이 올바르지 않습니다.');
        }

        $database = $this->database();
        if ($database->query('START TRANSACTION') === false) {
            throw new RuntimeException('동의 문서 저장을 시작하지 못했습니다.');
        }

        try {
            $typeId = (int) $database->get_var($database->prepare(
                'SELECT id FROM `agreement_document_types` WHERE slug = %s',
                $typeSlug
            ));
            $now = gmdate('Y-m-d H:i:s');
            if ($typeId < 1) {
                $result = $database->insert(
                    'agreement_document_types',
                    [
                        'slug' => $typeSlug,
                        'label' => trim($typeLabel),
                        'required' => $required ? 1 : 0,
                        'enabled' => 1,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%s', '%s', '%d', '%d', '%d', '%s', '%s']
                );
                if ($result === false) {
                    throw new RuntimeException('동의 문서 유형을 만들지 못했습니다.');
                }
                $typeId = (int) ($database->insert_id ?? 0);
            } else {
                $database->update(
                    'agreement_document_types',
                    [
                        'label' => trim($typeLabel),
                        'required' => $required ? 1 : 0,
                        'enabled' => 1,
                        'updated_at' => $now,
                    ],
                    ['id' => $typeId],
                    ['%s', '%d', '%d', '%s'],
                    ['%d']
                );
            }

            if ($typeId < 1) {
                throw new RuntimeException('동의 문서 유형 번호를 확인하지 못했습니다.');
            }

            if ($database->update(
                'agreement_documents',
                ['active' => 0, 'updated_at' => $now],
                ['type_id' => $typeId, 'active' => 1],
                ['%d', '%s'],
                ['%d', '%d']
            ) === false) {
                throw new RuntimeException('이전 동의 문서 상태를 변경하지 못했습니다.');
            }

            $result = $database->insert(
                'agreement_documents',
                [
                    'type_id' => $typeId,
                    'version' => trim($version),
                    'title' => trim($title),
                    'content' => $content,
                    'required' => $required ? 1 : 0,
                    'active' => 1,
                    'published_at' => $now,
                    'created_by_user_id' => $createdByUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s']
            );
            if ($result === false) {
                throw new RuntimeException('새 동의 문서를 게시하지 못했습니다.');
            }

            $documentId = (int) ($database->insert_id ?? 0);
            if ($database->query('COMMIT') === false || $documentId < 1) {
                throw new RuntimeException('동의 문서 저장을 완료하지 못했습니다.');
            }

            return new AgreementDocument(
                $documentId,
                $typeSlug,
                trim($version),
                trim($title),
                $content,
                $required
            );
        } catch (\Throwable $error) {
            $database->query('ROLLBACK');

            throw $error;
        }
    }

    private function database(): object
    {
        if (
            ! method_exists($this->database, 'prepare')
            || ! method_exists($this->database, 'get_results')
        ) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }

        return $this->database;
    }

    /**
     * @return list<AgreementDocument>
     */
    private function hydrateRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $documents = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $documents[] = $this->hydrate($row);
            }
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AgreementDocument
    {
        return new AgreementDocument(
            (int) ($row['id'] ?? 0),
            (string) ($row['slug'] ?? ''),
            (string) ($row['version'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['content'] ?? ''),
            (int) ($row['required'] ?? 0) === 1
        );
    }
}
