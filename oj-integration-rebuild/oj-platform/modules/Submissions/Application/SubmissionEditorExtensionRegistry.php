<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEditorExtension;
use InvalidArgumentException;

final class SubmissionEditorExtensionRegistry
{
    /**
     * @var array<string, SubmissionEditorExtension>
     */
    private array $extensions = [];

    public function register(SubmissionEditorExtension $extension): void
    {
        $key = $extension->key();
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException(
                '제출 편집기 확장 키가 올바르지 않습니다.'
            );
        }
        if (isset($this->extensions[$key])) {
            throw new InvalidArgumentException(
                '제출 편집기 확장이 중복되었습니다: ' . $key
            );
        }
        $this->extensions[$key] = $extension;
    }

    public function find(string $key): ?SubmissionEditorExtension
    {
        $extension = $this->extensions[$key] ?? null;

        return $extension instanceof SubmissionEditorExtension
            && $extension->isAvailable()
                ? $extension
                : null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->extensions as $key => $extension) {
            if ($extension->isAvailable()) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }
}
