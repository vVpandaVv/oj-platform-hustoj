<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingTemplateSource;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingWrapper;
use DomainException;

final class PluginVisualGradingTemplates implements VisualGradingTemplateSource
{
    private const MAX_SPJ_BYTES = 2097152;

    /** @var array<string, VisualGradingWrapper> */
    private array $wrappers = [];

    private ?string $spj = null;

    public function __construct(private readonly string $resourceDirectory)
    {
    }

    public function wrapper(VisualGradingMode $mode): VisualGradingWrapper
    {
        if (! $mode->isEnabled()) {
            throw new DomainException('이미지 채점 방식이 선택되지 않았습니다.');
        }

        return $this->wrappers[$mode->value] ??= new VisualGradingWrapper(
            $this->read('visual-grading/' . $mode->value . '.prefix.py', 65536),
            $this->read('visual-grading/' . $mode->value . '.suffix.py', 65536)
        );
    }

    public function spjSource(): string
    {
        return $this->spj ??= $this->read('image-spj/spj.cpp', self::MAX_SPJ_BYTES);
    }

    private function read(string $relativePath, int $maxBytes): string
    {
        $path = rtrim($this->resourceDirectory, '/\\') . '/' . $relativePath;
        $contents = is_file($path) && ! is_link($path)
            ? file_get_contents($path, false, null, 0, $maxBytes + 1)
            : false;
        if (
            ! is_string($contents)
            || $contents === ''
            || strlen($contents) > $maxBytes
            || preg_match('//u', $contents) !== 1
        ) {
            throw new DomainException('이미지 채점 구성 파일을 읽을 수 없습니다. 플러그인 설치를 확인해 주세요.');
        }

        return str_replace(["\r\n", "\r"], "\n", $contents);
    }
}
