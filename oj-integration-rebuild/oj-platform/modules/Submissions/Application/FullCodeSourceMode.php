<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourcePayload;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;

final class FullCodeSourceMode implements SubmissionSourceMode
{
    public function key(): string
    {
        return 'code';
    }

    public function build(
        SubmissionSourceRequest $request
    ): SubmissionSourcePayload {
        $source = $this->normalize($request->source());

        return new SubmissionSourcePayload(
            $this->key(),
            $request->requestedLanguageId(),
            $source,
            $source,
            [['start' => 0, 'length' => strlen($source)]],
            false
        );
    }

    private function normalize(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
