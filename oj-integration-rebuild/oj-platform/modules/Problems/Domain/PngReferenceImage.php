<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use DomainException;

/**
 * A reference PNG for image comparison grading. Mirrors the limits of the
 * image special judge (tools/image-spj): static 8-bit PNG, at most 2048 x 2048
 * pixels and 8 MiB. The special judge remains the authority (--check-reference);
 * this check only rejects obviously unusable uploads early.
 */
final class PngReferenceImage
{
    public const MAX_BYTES = 8388608;
    public const MAX_SIDE = 2048;
    public const MAX_PIXELS = 4194304;
    public const WRAPPER_HEADER = 'OJ_IMAGE_PNG_BASE64_V1';

    private const SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const COLOR_TYPES = [0, 2, 3, 4, 6];

    private function __construct(
        private readonly string $bytes,
        private readonly int $width,
        private readonly int $height
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        $length = strlen($bytes);
        if ($length < 57 || $length > self::MAX_BYTES) {
            throw new DomainException('정답 이미지는 8MB 이하의 PNG 파일이어야 합니다.');
        }
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw new DomainException(
                '정답 이미지는 실제 PNG 파일이어야 합니다. 다른 형식의 확장자만 바꾼 파일은 사용할 수 없습니다.'
            );
        }

        $offset = 8;
        $width = 0;
        $height = 0;
        $first = true;
        $hasData = false;
        $ended = false;
        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($chunkLength > $length - $offset - 12) {
                throw new DomainException('PNG 파일이 손상되었습니다.');
            }
            $data = substr($bytes, $offset + 8, $chunkLength);
            $crc = unpack('N', substr($bytes, $offset + 8 + $chunkLength, 4))[1];
            if ($crc !== (crc32($type . $data) & 0xFFFFFFFF)) {
                throw new DomainException('PNG 파일이 손상되었습니다.');
            }
            if ($first) {
                if ($type !== 'IHDR' || $chunkLength !== 13) {
                    throw new DomainException('PNG 파일이 손상되었습니다.');
                }
                [$width, $height] = array_values(unpack('N2', substr($data, 0, 8)));
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
                if (
                    $width < 1
                    || $height < 1
                    || $width > self::MAX_SIDE
                    || $height > self::MAX_SIDE
                    || $width * $height > self::MAX_PIXELS
                ) {
                    throw new DomainException('정답 이미지는 가로·세로 2048픽셀 이하여야 합니다.');
                }
                if ($bitDepth > 8 || ! in_array($colorType, self::COLOR_TYPES, true)) {
                    throw new DomainException('정답 이미지는 8비트 이하의 PNG여야 합니다.');
                }
                if (ord($data[10]) !== 0 || ord($data[11]) !== 0 || ord($data[12]) > 1) {
                    throw new DomainException('지원하지 않는 PNG 형식입니다.');
                }
                $first = false;
            } elseif ($type === 'acTL' || $type === 'fcTL' || $type === 'fdAT') {
                throw new DomainException('움직이는 PNG(APNG)는 사용할 수 없습니다.');
            } elseif ($type === 'IDAT') {
                $hasData = true;
            }
            $offset += 12 + $chunkLength;
            if ($type === 'IEND') {
                $ended = true;
                break;
            }
        }
        if (! $ended || ! $hasData || $offset !== $length) {
            throw new DomainException('PNG 파일이 손상되었거나 끝에 불필요한 데이터가 있습니다.');
        }

        return new self($bytes, $width, $height);
    }

    /**
     * Reads the text form stored in a ".out" file:
     * OJ_IMAGE_PNG_BASE64_V1 on the first line followed by Base64 data.
     */
    public static function fromWrappedText(string $text): ?self
    {
        $normalized = str_replace("\r\n", "\n", $text);
        if (! str_starts_with($normalized, self::WRAPPER_HEADER . "\n")) {
            return null;
        }
        $encoded = preg_replace(
            '/\s+/',
            '',
            substr($normalized, strlen(self::WRAPPER_HEADER) + 1)
        );
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($decoded)) {
            throw new DomainException('정답 이미지 Base64 데이터가 올바르지 않습니다.');
        }

        return self::fromBytes($decoded);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function wrappedText(): string
    {
        return self::WRAPPER_HEADER . "\n"
            . chunk_split(base64_encode($this->bytes), 120, "\n");
    }
}
