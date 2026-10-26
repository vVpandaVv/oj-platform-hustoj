<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserImportCandidate;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use DomainException;

final class UserImportDraftService
{
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly UserImportCsvParser $parser,
        private readonly FormDraftStore $drafts
    ) {
    }

    public function create(
        UploadedFile $file,
        int $actorUserId,
        bool $canAssignOperationalRoles
    ): UserImportDraft {
        $preview = $this->parser->parse($file, $canAssignOperationalRoles);
        $valid = $preview->validCandidates();
        $reference = null;
        if ($valid !== []) {
            $reference = $this->drafts->store(
                'user_csv_import_preview',
                $actorUserId,
                'user_import',
                hash('sha256', random_bytes(32)),
                ['candidates' => array_map(
                    static fn (UserImportCandidate $candidate): array => $candidate->payload(),
                    $valid
                )],
                self::TTL_SECONDS
            );
        }

        return new UserImportDraft($preview, $reference);
    }

    /**
     * @return list<UserImportCandidate>
     */
    public function load(string $reference, int $actorUserId): array
    {
        try {
            $draftReference = new FormDraftReference($reference);
        } catch (\InvalidArgumentException $error) {
            throw new DomainException('일괄 등록 미리보기 정보가 올바르지 않습니다.', 0, $error);
        }

        $draft = $this->drafts->find($draftReference, $actorUserId);
        if (
            $draft === null
            || $draft->draftType() !== 'user_csv_import_preview'
            || $draft->targetType() !== 'user_import'
        ) {
            throw new DomainException('이 일괄 등록 미리보기를 실행할 수 없습니다.');
        }
        $payload = $draft->payload();

        $rows = is_array($payload['candidates'] ?? null)
            ? $payload['candidates']
            : [];
        $candidates = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $candidates[] = UserImportCandidate::fromPayload($row);
            }
        }
        if ($candidates === []) {
            throw new DomainException('실행할 사용자 후보가 없습니다.');
        }

        return $candidates;
    }

    public function discard(string $reference, int $ownerUserId): void
    {
        try {
            $draftReference = new FormDraftReference($reference);
        } catch (\InvalidArgumentException $error) {
            throw new DomainException(
                '일괄 등록 미리보기 정보가 올바르지 않습니다.',
                0,
                $error
            );
        }
        $this->drafts->discard($draftReference, $ownerUserId);
    }
}
