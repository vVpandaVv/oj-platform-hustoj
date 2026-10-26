<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemImageStore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage;
use RuntimeException;

/**
 * Writes problem images into the WordPress uploads folder under a random file
 * name, so nothing in the original upload name or type reaches the file system.
 */
final class WordPressProblemImageStore implements ProblemImageStore
{
    private const FOLDER = 'oj-platform/problem-images';

    /**
     * @param callable(): array{basedir: string, baseurl: string} $locator
     */
    public function __construct(private readonly mixed $locator)
    {
    }

    public static function fromGlobals(): self
    {
        return new self(static function (): array {
            $uploads = wp_upload_dir(null, false);
            if (! is_array($uploads) || ($uploads['error'] ?? false)) {
                throw new RuntimeException('업로드 폴더를 사용할 수 없습니다.');
            }

            return ['basedir' => (string) $uploads['basedir'], 'baseurl' => (string) $uploads['baseurl']];
        });
    }

    public function store(ProblemImage $image): string
    {
        $location = ($this->locator)();
        $relative = self::FOLDER . '/' . gmdate('Y') . '/' . gmdate('m');
        $directory = rtrim($location['basedir'], '/\\') . '/' . $relative;
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('이미지 폴더를 만들지 못했습니다.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $image->extension();
        $path = $directory . '/' . $name;
        $temporary = $directory . '/.' . $name . '.tmp';
        if (file_put_contents($temporary, $image->bytes(), LOCK_EX) === false) {
            throw new RuntimeException('이미지를 저장하지 못했습니다.');
        }
        chmod($temporary, 0644);
        if (! rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('이미지를 저장하지 못했습니다.');
        }

        return rtrim($location['baseurl'], '/') . '/' . $relative . '/' . $name;
    }
}
