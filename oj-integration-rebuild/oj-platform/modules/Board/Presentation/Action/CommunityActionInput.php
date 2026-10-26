<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use DomainException;

final class CommunityActionInput
{
    /**
     * @return list<UploadedFile>
     */
    public static function files(HttpRequest $request, int $maximum = 5): array
    {
        $files = [];
        for ($index = 1; $index <= $maximum; $index++) {
            $file = $request->file('attachment_' . $index);
            if (
                $file instanceof UploadedFile
                && ($file->clientName() !== '' || $file->size() > 0)
            ) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return array{string, int}
     */
    public static function context(string $key): array
    {
        if (preg_match(
            '/^(global|problem|class|contest|group):(\d+)$/',
            trim($key),
            $match
        ) !== 1) {
            throw new DomainException('연결 공간을 다시 선택해 주세요.');
        }
        $type = $match[1];
        $targetId = (int) $match[2];
        if (
            ($type === 'global' && $targetId !== 0)
            || ($type !== 'global' && $targetId < 1)
        ) {
            throw new DomainException('연결 공간을 다시 선택해 주세요.');
        }

        return [$type, $targetId];
    }

    /**
     * @return list<string>
     */
    public static function extensions(string $value): array
    {
        $items = preg_split('/[\s,]+/', strtolower(trim($value))) ?: [];
        $extensions = [];
        foreach ($items as $item) {
            $item = ltrim(trim($item), '.');
            if ($item !== '') {
                $extensions[$item] = true;
            }
        }

        return array_keys($extensions);
    }
}
