<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use DomainException;

/**
 * A validated raster image that may be embedded in problem text. The type is
 * decided from the bytes, never from a client supplied name or media type.
 */
final class ProblemImage
{
    public const MAX_BYTES = 5242880;
    public const MAX_SIDE = 6000;
    private const MAX_PIXELS = 24000000;

    private function __construct(
        private readonly string $bytes,
        private readonly string $extension,
        private readonly int $width,
        private readonly int $height
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        $length = strlen($bytes);
        if ($length < 1) {
            throw new DomainException('이미지 파일이 비어 있습니다.');
        }
        if ($length > self::MAX_BYTES) {
            throw new DomainException('이미지는 5MB 이하만 올릴 수 있습니다.');
        }
        $extension = self::signatureExtension($bytes);
        if ($extension === null) {
            throw new DomainException('PNG, JPEG, GIF, WebP 이미지만 올릴 수 있습니다.');
        }
        $info = getimagesizefromstring($bytes);
        if (! is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new DomainException('이미지 내용을 읽을 수 없습니다.');
        }
        $detected = match ($info[2] ?? 0) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
        if ($detected !== $extension) {
            throw new DomainException('이미지 형식을 확인할 수 없습니다.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if (
            $width > self::MAX_SIDE
            || $height > self::MAX_SIDE
            || $width * $height > self::MAX_PIXELS
        ) {
            throw new DomainException('이미지 크기는 6000×6000 이하로 줄여 주세요.');
        }

        return new self($bytes, $extension, $width, $height);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    private static function signatureExtension(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'gif';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }
}
