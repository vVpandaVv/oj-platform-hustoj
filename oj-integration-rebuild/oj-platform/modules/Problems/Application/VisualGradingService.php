<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\VisualCaptureStore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\PngReferenceImage;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingWrapper;
use DomainException;
use RuntimeException;

/**
 * Prepares a problem's data folder for image comparison grading: installs the
 * single-file image special judge and stores reference PNGs in the text-safe
 * Base64 ".out" form accepted by the existing test data rules.
 */
final class VisualGradingService
{
    public const SPJ_SOURCE = 'spj.cc';
    private const PREVIEW_MAX_OUT_BYTES = 786432;
    private const PREVIEW_MAX_COUNT = 12;
    private const MAX_INPUT_BYTES = 65536;
    public const SETTINGS_FILE = 'image-spj-settings.txt';
    public const DEFAULT_TOLERANCE = 16;
    public const MAX_TOLERANCE = 96;
    public const CAPTURE_MARKER = 'capture-armed.txt';
    public const CAPTURE_PLACEHOLDER = 'capture-tmp';
    public const CAPTURE_WINDOW_SECONDS = 600;
    private const CAPTURE_HEADER = 'OJ_IMAGE_CAPTURE_V1';

    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemTestDataService $testData,
        private readonly ProblemTestDataJobService $jobs,
        private readonly VisualGradingTemplateSource $templates,
        private readonly VisualCaptureStore $captures
    ) {
    }

    public function mode(ProblemId $problemId): VisualGradingMode
    {
        return $this->metadata->find($problemId)?->visualGradingMode()
            ?? VisualGradingMode::None;
    }

    /**
     * Whether an installed spj.cc is byte-identical to the grader bundled with this plugin.
     */
    public function isGraderCurrent(string $installedSha256): bool
    {
        return hash_equals(hash('sha256', $this->templates->spjSource()), $installedSha256);
    }

    public function wrapperFor(VisualGradingMode $mode): VisualGradingWrapper
    {
        return $this->templates->wrapper($mode);
    }

    public function installGrader(
        int $actorUserId,
        ProblemId $problemId,
        bool $replaceExisting
    ): VisualGraderInstallResult {
        $this->assertEnabled($problemId);
        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        foreach (['spj.c', 'spj.cpp'] as $other) {
            if (isset($files[$other])) {
                throw new DomainException(
                    $other . ' 소스가 이미 있어 이미지 채점기와 섞일 수 있습니다. 해당 파일을 정리한 뒤 다시 시도해 주세요.'
                );
            }
        }

        $source = $this->templates->spjSource();
        $existing = $files[self::SPJ_SOURCE] ?? null;
        $written = false;
        if (! $existing instanceof ProblemDataFile) {
            $this->testData->save(
                $actorUserId,
                $problemId,
                self::SPJ_SOURCE,
                $source,
                null
            );
            $written = true;
        } elseif (hash('sha256', $source) !== $existing->sha256()) {
            if (! $replaceExisting) {
                throw new DomainException(
                    '다른 내용의 spj.cc가 이미 있습니다. 덮어써도 되면 "기존 spj.cc 덮어쓰기"를 선택해 주세요.'
                );
            }
            $this->testData->save(
                $actorUserId,
                $problemId,
                self::SPJ_SOURCE,
                $source,
                $existing->sha256()
            );
            $written = true;
        }

        try {
            $job = $this->jobs->enqueueSpjCompile(
                $actorUserId,
                $problemId,
                self::SPJ_SOURCE
            );

            return new VisualGraderInstallResult($written, $job->jobId(), null);
        } catch (DomainException $error) {
            return new VisualGraderInstallResult($written, null, $error->getMessage());
        }
    }

    public function saveReferenceImage(
        int $actorUserId,
        ProblemId $problemId,
        string $caseName,
        string $inputText,
        string $pngBytes,
        bool $replaceExisting
    ): string {
        $this->assertEnabled($problemId);
        $caseName = trim($caseName);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $caseName) !== 1) {
            throw new DomainException(
                '테스트 이름은 영문자·숫자·밑줄·하이픈으로 64자 이내로 입력해 주세요.'
            );
        }
        if ($caseName === self::CAPTURE_PLACEHOLDER) {
            throw new DomainException(
                $caseName . '은(는) 정답 캡처용 임시 이름이라 사용할 수 없습니다.'
            );
        }
        $inputText = str_replace(["\r\n", "\r"], "\n", $inputText);
        if (
            preg_match('//u', $inputText) !== 1
            || strlen($inputText) > self::MAX_INPUT_BYTES
        ) {
            throw new DomainException('입력 내용은 64KB 이하의 UTF-8 텍스트여야 합니다.');
        }
        if (trim($inputText) === '') {
            $inputText = "\n";
        } elseif (! str_ends_with($inputText, "\n")) {
            $inputText .= "\n";
        }
        $image = PngReferenceImage::fromBytes($pngBytes);

        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        $inputName = $caseName . '.in';
        $outputName = $caseName . '.out';
        $existingInput = $files[$inputName] ?? null;
        $existingOutput = $files[$outputName] ?? null;
        if (
            ($existingInput !== null || $existingOutput !== null)
            && ! $replaceExisting
        ) {
            throw new DomainException(
                $caseName . ' 테스트가 이미 있습니다. 덮어써도 되면 "같은 이름 덮어쓰기"를 선택해 주세요.'
            );
        }
        if (isset($files[$caseName . '.ans'])) {
            throw new DomainException(
                $caseName . '.ans 파일이 있어 같은 이름의 정답 이미지를 만들 수 없습니다.'
            );
        }

        $this->testData->save(
            $actorUserId,
            $problemId,
            $outputName,
            $image->wrappedText(),
            $existingOutput?->sha256()
        );
        $this->testData->save(
            $actorUserId,
            $problemId,
            $inputName,
            $inputText,
            $existingInput?->sha256()
        );

        return $caseName;
    }

    /**
     * Arms the one-shot capture: the next submission that reaches the image
     * grader stores its output PNG as a candidate instead of comparing it.
     */
    public function armCapture(int $actorUserId, ProblemId $problemId): void
    {
        $this->assertEnabled($problemId);
        if (! $this->captures->directoryExists()) {
            throw new DomainException(
                '캡처 폴더가 없습니다. 서버에 ' . $this->captures->directoryPath()
                . ' 폴더를 만들고 채점기 계정이 쓸 수 있게 한 뒤 다시 시도해 주세요.'
            );
        }
        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        $this->captures->delete($problemId);
        if (isset($files[self::CAPTURE_MARKER])) {
            $this->testData->delete(
                $actorUserId,
                $problemId,
                self::CAPTURE_MARKER,
                $files[self::CAPTURE_MARKER]->sha256()
            );
        }
        if (! $this->hasRealCase($files)) {
            $placeholderIn = self::CAPTURE_PLACEHOLDER . '.in';
            $placeholderOut = self::CAPTURE_PLACEHOLDER . '.out';
            $this->testData->save(
                $actorUserId,
                $problemId,
                $placeholderOut,
                "OJ_CAPTURE_PLACEHOLDER\n",
                isset($files[$placeholderOut]) ? $files[$placeholderOut]->sha256() : null
            );
            $this->testData->save(
                $actorUserId,
                $problemId,
                $placeholderIn,
                "\n",
                isset($files[$placeholderIn]) ? $files[$placeholderIn]->sha256() : null
            );
        }
        $this->testData->save(
            $actorUserId,
            $problemId,
            self::CAPTURE_MARKER,
            "armed\n",
            null
        );
    }

    public function cancelCapture(int $actorUserId, ProblemId $problemId): void
    {
        $this->assertEnabled($problemId);
        $this->clearCaptureFiles($actorUserId, $problemId);
    }

    public function captureState(int $actorUserId, ProblemId $problemId): VisualCaptureState
    {
        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        $armed = false;
        $expired = false;
        $remaining = 0;
        $marker = $files[self::CAPTURE_MARKER] ?? null;
        if ($marker instanceof ProblemDataFile) {
            $age = time() - (int) strtotime($marker->modifiedAt());
            $remaining = self::CAPTURE_WINDOW_SECONDS - max(0, $age);
            $armed = $remaining > 0;
            $expired = ! $armed;
            $remaining = max(0, $remaining);
        }
        $candidate = null;
        $problem = null;
        $text = null;
        try {
            $text = $this->captures->read($problemId);
        } catch (DomainException | RuntimeException $error) {
            $problem = $error->getMessage();
        }
        if ($text !== null) {
            $armed = false;
            try {
                $parsed = $this->parseCandidate($text);
                $candidate = [
                    'case' => $parsed['case'],
                    'width' => $parsed['image']->width(),
                    'height' => $parsed['image']->height(),
                    'source' => 'data:image/png;base64,' . base64_encode($parsed['image']->bytes()),
                ];
            } catch (DomainException $error) {
                $problem = $error->getMessage();
            }
        }

        return new VisualCaptureState(
            $armed,
            $expired,
            $remaining,
            $candidate,
            $problem,
            $this->captures->directoryExists(),
            $this->captures->directoryPath(),
            $this->captures->lastVerdict($problemId),
            $this->rejectedPreview($problemId)
        );
    }

    /**
     * @return array{width: int, height: int, source: string}|null
     */
    private function rejectedPreview(ProblemId $problemId): ?array
    {
        $bytes = $this->captures->lastRejectedImage($problemId);
        if ($bytes === null) {
            return null;
        }
        try {
            $image = PngReferenceImage::fromBytes($bytes);
        } catch (DomainException) {
            return null;
        }

        return [
            'width' => $image->width(),
            'height' => $image->height(),
            'source' => 'data:image/png;base64,' . base64_encode($image->bytes()),
        ];
    }

    /**
     * Stores the captured candidate as a reference case and clears the capture
     * workflow files. Returns the saved case name.
     */
    public function applyCapture(
        int $actorUserId,
        ProblemId $problemId,
        string $caseName,
        string $inputText,
        bool $replaceExisting
    ): string {
        $this->assertEnabled($problemId);
        $text = $this->captures->read($problemId);
        if ($text === null) {
            throw new DomainException(
                '적용할 캡처 결과가 없습니다. 정답 캡처를 시작한 뒤 모범 답안을 제출해 주세요.'
            );
        }
        $parsed = $this->parseCandidate($text);
        $caseName = trim($caseName) === '' ? $parsed['case'] : $caseName;
        $saved = $this->saveReferenceImage(
            $actorUserId,
            $problemId,
            $caseName,
            $inputText,
            $parsed['image']->bytes(),
            $replaceExisting
        );
        $this->clearCaptureFiles($actorUserId, $problemId);

        return $saved;
    }

    /**
     * @return array{case: string, image: PngReferenceImage}
     */
    private function parseCandidate(string $text): array
    {
        $normalized = str_replace("\r\n", "\n", $text);
        $newline = strpos($normalized, "\n");
        $header = $newline === false ? $normalized : substr($normalized, 0, $newline);
        if (
            $newline === false
            || preg_match(
                '/^' . self::CAPTURE_HEADER . ' ([A-Za-z0-9][A-Za-z0-9_.-]{0,127})$/',
                $header,
                $matches
            ) !== 1
        ) {
            throw new DomainException('캡처 결과 파일 형식이 올바르지 않습니다.');
        }
        $image = PngReferenceImage::fromWrappedText(substr($normalized, $newline + 1));
        if ($image === null) {
            throw new DomainException('캡처 결과에 이미지 데이터가 없습니다.');
        }
        $case = $matches[1];
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $case) !== 1
            || $case === self::CAPTURE_PLACEHOLDER
        ) {
            $case = '01';
        }

        return ['case' => $case, 'image' => $image];
    }

    private function clearCaptureFiles(int $actorUserId, ProblemId $problemId): void
    {
        $this->captures->delete($problemId);
        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        foreach ([
            self::CAPTURE_MARKER,
            self::CAPTURE_PLACEHOLDER . '.in',
            self::CAPTURE_PLACEHOLDER . '.out',
        ] as $name) {
            if (isset($files[$name])) {
                $this->testData->delete(
                    $actorUserId,
                    $problemId,
                    $name,
                    $files[$name]->sha256()
                );
            }
        }
    }

    /**
     * @param array<string, ProblemDataFile> $files
     */
    private function hasRealCase(array $files): bool
    {
        foreach ($files as $name => $file) {
            if (
                $file->extension() === 'in'
                && $name !== self::CAPTURE_PLACEHOLDER . '.in'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current per-problem color tolerance as the grader will read it.
     *
     * @return array{value: int, custom: bool, problem: string|null}
     */
    public function toleranceState(int $actorUserId, ProblemId $problemId): array
    {
        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        if (! isset($files[self::SETTINGS_FILE])) {
            return ['value' => self::DEFAULT_TOLERANCE, 'custom' => false, 'problem' => null];
        }
        $text = $this->testData->read($actorUserId, $problemId, self::SETTINGS_FILE);
        $value = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (
                preg_match('/^color_tolerance=(\d{1,3})$/', $line, $matches) !== 1
                || (int) $matches[1] > self::MAX_TOLERANCE
            ) {
                return [
                    'value' => self::DEFAULT_TOLERANCE,
                    'custom' => false,
                    'problem' => self::SETTINGS_FILE . ' 내용이 올바르지 않아 채점기가 오류를 냅니다. 비교 기준을 다시 저장해 주세요.',
                ];
            }
            $value = (int) $matches[1];
        }

        return [
            'value' => $value ?? self::DEFAULT_TOLERANCE,
            'custom' => $value !== null && $value !== self::DEFAULT_TOLERANCE,
            'problem' => null,
        ];
    }

    /**
     * Stores the color tolerance the grader applies to this problem. The default
     * value removes the settings file so the grader's built-in strict value applies.
     */
    public function saveTolerance(int $actorUserId, ProblemId $problemId, int $tolerance): void
    {
        $this->assertEnabled($problemId);
        if ($tolerance < 0 || $tolerance > self::MAX_TOLERANCE) {
            throw new DomainException('색 허용치는 0 이상 ' . self::MAX_TOLERANCE . ' 이하로 입력해 주세요.');
        }
        $files = $this->indexed($this->testData->files($actorUserId, $problemId));
        $existing = $files[self::SETTINGS_FILE] ?? null;
        if ($tolerance === self::DEFAULT_TOLERANCE) {
            if ($existing instanceof ProblemDataFile) {
                $this->testData->delete($actorUserId, $problemId, self::SETTINGS_FILE, $existing->sha256());
            }

            return;
        }
        $this->testData->save(
            $actorUserId,
            $problemId,
            self::SETTINGS_FILE,
            "color_tolerance=" . $tolerance . "\n",
            $existing?->sha256()
        );
    }

    /**
     * Smallest tolerance on an 8 step grid that lets a rejected submission pass
     * with some margin, or null when the difference is beyond the allowed cap.
     */
    public static function suggestedTolerance(int $maxDelta): ?int
    {
        if ($maxDelta < 1) {
            return null;
        }
        $suggested = (int) (ceil(($maxDelta + 8) / 8) * 8);

        return $suggested <= self::MAX_TOLERANCE ? $suggested : null;
    }

    /**
     * @return list<array{name: string, width: int, height: int, source: string}>
     */
    public function referencePreviews(int $actorUserId, ProblemId $problemId): array
    {
        $previews = [];
        foreach ($this->testData->files($actorUserId, $problemId) as $file) {
            if (
                $file->extension() !== 'out'
                || $file->size() > self::PREVIEW_MAX_OUT_BYTES
            ) {
                continue;
            }
            try {
                $image = PngReferenceImage::fromWrappedText(
                    $this->testData->read($actorUserId, $problemId, $file->name())
                );
            } catch (DomainException) {
                continue;
            }
            if ($image === null) {
                continue;
            }
            $previews[] = [
                'name' => $file->name(),
                'width' => $image->width(),
                'height' => $image->height(),
                'source' => 'data:image/png;base64,' . base64_encode($image->bytes()),
            ];
            if (count($previews) >= self::PREVIEW_MAX_COUNT) {
                break;
            }
        }

        return $previews;
    }

    private function assertEnabled(ProblemId $problemId): void
    {
        $problem = $this->problems->find($problemId);
        if (
            $problem === null
            || ! $problem->specialJudge()
            || ! $this->mode($problemId)->isEnabled()
        ) {
            throw new DomainException(
                '문제 편집에서 이미지 채점을 선택하고 저장한 문제만 사용할 수 있습니다.'
            );
        }
    }

    /**
     * @param list<ProblemDataFile> $files
     * @return array<string, ProblemDataFile>
     */
    private function indexed(array $files): array
    {
        $indexed = [];
        foreach ($files as $file) {
            $indexed[$file->name()] = $file;
        }

        return $indexed;
    }
}
