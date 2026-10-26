<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourcePayload;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;
use DomainException;

/** Restores only the registered fragments; client-provided source is ignored. */
final class CodeOrderingSourceMode implements SubmissionSourceMode
{
    public function __construct(private readonly SubmissionLanguageCatalog $languages)
    {
    }

    public function key(): string
    {
        return 'code_ordering';
    }

    public function build(SubmissionSourceRequest $request): SubmissionSourcePayload
    {
        $policy = $request->policy();
        $schema = $policy->orderingSchema();
        $expectedHash = $policy->orderingHash();
        if (
            $policy->entryMode() !== $this->key()
            || $schema === null
            || $expectedHash === null
            || ! hash_equals($expectedHash, $request->templateHash())
            || ! hash_equals($expectedHash, CodeOrderingPuzzle::hash($schema))
        ) {
            throw new DomainException('코드 순서 배열 문제가 변경되었습니다. 화면을 새로 고쳐 주세요.');
        }

        $source = CodeOrderingPuzzle::assemble($schema, $request->orderingBlockIds());
        $languageId = $this->languages->idForKey(CodeOrderingPuzzle::languageKey($schema));

        return new SubmissionSourcePayload(
            $this->key(),
            $languageId,
            $source,
            $source,
            [],
            false,
            [
                'template_hash' => $expectedHash,
                'ordering_block_count' => count($request->orderingBlockIds()),
                'ordering_order' => json_encode($request->orderingBlockIds(), JSON_THROW_ON_ERROR),
            ]
        );
    }
}
