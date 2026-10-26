<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceViewProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStoredSource;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStoredSourceDecoder;
use InvalidArgumentException;

final class SubmissionStoredSourceDecoderRegistry implements
    SubmissionSourceViewProvider
{
    /**
     * @var array<string, SubmissionStoredSourceDecoder>
     */
    private array $decoders = [];

    public function register(SubmissionStoredSourceDecoder $decoder): void
    {
        $key = $decoder->key();
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException(
                '저장 소스 Decoder 키가 올바르지 않습니다.'
            );
        }
        if (isset($this->decoders[$key])) {
            throw new InvalidArgumentException(
                '저장 소스 Decoder가 중복되었습니다: ' . $key
            );
        }
        $this->decoders[$key] = $decoder;
    }

    public function view(string $storedSource): SubmissionStoredSource
    {
        foreach ($this->decoders as $decoder) {
            $decoded = $decoder->decode($storedSource);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return new SubmissionStoredSource($storedSource, 'code');
    }
}
