<?php
declare(strict_types=1);
namespace OjPrimm\Application;

/** Optional platform-owned editor prefill. Preparing a draft never creates a submission. */
interface SubmissionDraftAccess
{
    /** @return array{url:string,language:string,expires_at:int} */
    public function prepareSubmissionDraft(int $problemId, string $contextType, string $contextId, string $language, string $source): array;
}
