<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlDocument;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlIssue;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemXmlItem;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use DomainException;
use InvalidArgumentException;
use LibXMLError;
use XMLWriter;

final class ProblemXmlCodec
{
    private const MAX_XML_BYTES = 20971520;
    private const MAX_ITEMS = 200;
    private const MAX_TEXT_BYTES = 1048576;
    private const MAX_TEST_PAIRS = 20;

    public function decode(string $xml): ProblemXmlDocument
    {
        if (
            $xml === ''
            || strlen($xml) > self::MAX_XML_BYTES
            || str_contains($xml, "\0")
            || preg_match('//u', $xml) !== 1
        ) {
            throw new DomainException('XML 파일은 20MB 이하의 UTF-8 문서여야 합니다.');
        }
        if (
            preg_match('/\A(?:\xEF\xBB\xBF)?<\?xml\b[^?]*\bencoding\s*=\s*(["\'])([^"\']+)\1/i', $xml, $encoding) === 1
            && strcasecmp($encoding[2], 'UTF-8') !== 0
        ) {
            throw new DomainException('XML 파일의 인코딩 선언은 UTF-8만 허용합니다.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new DomainException('DOCTYPE과 외부 엔터티가 포함된 XML은 가져올 수 없습니다.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new DomainException($this->parseError($errors));
        }
        if ($document->doctype !== null) {
            throw new DomainException('DOCTYPE과 외부 엔터티가 포함된 XML은 가져올 수 없습니다.');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('/CSLfps/item | /fps/item | /item');
        if ($nodes === false || $nodes->length < 1) {
            throw new DomainException('XML에서 문제 item 요소를 찾을 수 없습니다.');
        }
        if ($nodes->length > self::MAX_ITEMS) {
            throw new DomainException('한 번에 가져올 수 있는 문제는 최대 200개입니다.');
        }

        $items = [];
        $issues = [];
        foreach ($nodes as $index => $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            try {
                $items[] = $this->decodeItem($node, $index);
            } catch (DomainException | InvalidArgumentException $error) {
                $problemId = $this->rawPositiveInt(
                    $this->childText($node, 'problem_id')
                );
                $issues[] = new ProblemXmlIssue(
                    $index,
                    $problemId,
                    trim($this->childText($node, 'title')),
                    $error->getMessage()
                );
            }
        }

        return new ProblemXmlDocument($items, $issues);
    }

    /**
     * @param list<ProblemXmlItem> $items
     */
    public function encode(
        array $items,
        bool $includeMetadata,
        bool $includeTestData
    ): string {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw new DomainException('내보낼 문제는 1개부터 200개까지 선택해 주세요.');
        }
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('CSLfps');
        $writer->writeAttribute('version', '1.2');
        $writer->startElement('generator');
        $writer->writeAttribute('name', 'OJ Platform');
        $writer->endElement();
        foreach ($items as $item) {
            if (! $item instanceof ProblemXmlItem) {
                throw new DomainException('내보낼 문제 XML 항목이 올바르지 않습니다.');
            }
            $this->encodeItem(
                $writer,
                $item,
                $includeMetadata,
                $includeTestData
            );
        }
        $writer->endElement();
        $writer->endDocument();
        $xml = $writer->outputMemory();
        if (! is_string($xml) || strlen($xml) > self::MAX_XML_BYTES) {
            throw new DomainException('내보내기 XML이 20MB 제한을 초과했습니다.');
        }

        return $xml;
    }

    private function decodeItem(DOMElement $item, int $index): ProblemXmlItem
    {
        $problemIdText = trim($this->childText($item, 'problem_id'));
        $problemId = null;
        if ($problemIdText !== '') {
            $problemIdValue = $this->rawPositiveInt($problemIdText);
            if ($problemIdValue === null) {
                throw new DomainException('문제 번호는 1 이상의 정수여야 합니다.');
            }
            $problemId = new ProblemId($problemIdValue);
        }
        $title = trim($this->boundedText($item, 'title', 191));
        if ($title === '') {
            throw new DomainException('문제 제목이 없습니다.');
        }
        $timeLimit = $this->limitValue(
            $item,
            'time_limit',
            1,
            3600,
            1,
            's'
        );
        $memoryLimit = $this->limitValue(
            $item,
            'memory_limit',
            1,
            1048576,
            128,
            'mb'
        );
        $defunctNode = $this->child($item, 'defunct');
        $visibilitySpecified = $defunctNode !== null;
        $visibility = $visibilitySpecified
            && strtoupper(trim((string) $defunctNode?->textContent)) !== 'Y'
            ? ProblemVisibility::Published
            : ProblemVisibility::Archived;
        $specialJudgeValue = strtoupper(trim($this->childText($item, 'spj')));
        $specialJudge = ! in_array(
            $specialJudgeValue,
            ['', '0', 'N', 'NO', 'FALSE'],
            true
        ) || $this->child($item, 'tpj') !== null;
        $languageMaskText = trim($this->childText($item, 'langmask'));
        $languageMask = $languageMaskText === ''
            ? null
            : $this->nonNegativeInt($languageMaskText, '허용 언어 값');

        $metadata = $this->metadata($item);
        $draft = new ProblemDraft(
            $title,
            $this->boundedText($item, 'description'),
            $this->boundedText($item, 'input'),
            $this->boundedText($item, 'output'),
            $this->boundedText($item, 'sample_input'),
            $this->boundedText($item, 'sample_output'),
            $this->boundedText($item, 'hint'),
            $this->boundedText($item, 'source', 191),
            $timeLimit,
            $memoryLimit,
            $specialJudge,
            $visibility,
            $this->boundedText($item, 'bann'),
            $this->boundedText($item, 'front', ProblemDraft::MAX_WRAPPER_CODE_BYTES),
            $this->boundedText($item, 'body', ProblemDraft::MAX_WRAPPER_CODE_BYTES),
            $this->boundedText($item, 'rear', ProblemDraft::MAX_WRAPPER_CODE_BYTES),
            $languageMask,
            $this->boundedText($item, 'credits')
        );

        return new ProblemXmlItem(
            $index,
            $problemId,
            $draft,
            $visibilitySpecified,
            $metadata['difficulty'],
            $metadata['topics'],
            $metadata['entry_mode'],
            $metadata['completion_schema'],
            $this->testFiles($item),
            $metadata['ordering_schema']
        );
    }

    /**
     * @return array{
     *     difficulty:?DifficultyScore,
     *     topics:list<string>,
     *     entry_mode:CodeEntryMode,
     *     completion_schema:?string,
     *     ordering_schema:?string
     * }
     */
    private function metadata(DOMElement $item): array
    {
        $node = $this->child($item, 'oj_metadata');
        if ($node === null) {
            return [
                'difficulty' => null,
                'topics' => [],
                'entry_mode' => CodeEntryMode::FullCode,
                'completion_schema' => null,
                'ordering_schema' => null,
            ];
        }
        $difficultyText = trim($node->getAttribute('difficulty'));
        $difficulty = $difficultyText === ''
            ? null
            : new DifficultyScore(
                $this->nonNegativeInt($difficultyText, '난이도')
            );
        $entryModeText = trim($node->getAttribute('code_entry_mode'));
        $entryMode = $entryModeText === ''
            ? CodeEntryMode::FullCode
            : CodeEntryMode::tryFrom($entryModeText);
        if (! $entryMode instanceof CodeEntryMode) {
            throw new DomainException('코드 입력 방식이 올바르지 않습니다.');
        }
        $topics = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->tagName !== 'topic') {
                continue;
            }
            $key = trim($child->getAttribute('key'));
            if ($key !== '') {
                $topics[] = $key;
            }
        }
        $topics = array_values(array_unique($topics));
        $completion = $this->boundedText(
            $node,
            'completion_schema',
            262144
        );
        $completion = $completion === '' ? null : $completion;

        return [
            'difficulty' => $difficulty,
            'topics' => $topics,
            'entry_mode' => $entryMode,
            'completion_schema' => $completion,
            'ordering_schema' => ($ordering = $this->boundedText($node, 'ordering_schema', 196608)) === '' ? null : $ordering,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function testFiles(DOMElement $item): array
    {
        $inputs = $this->children($item, 'test_input');
        $outputs = $this->children($item, 'test_output');
        if (count($inputs) !== count($outputs)) {
            throw new DomainException('테스트 입력과 출력 개수가 일치하지 않습니다.');
        }
        if (count($inputs) > self::MAX_TEST_PAIRS) {
            throw new DomainException('문제당 테스트데이터는 최대 20쌍까지 가져올 수 있습니다.');
        }
        $files = [];
        foreach ($inputs as $index => $input) {
            $output = $outputs[$index];
            $inputName = trim($input->getAttribute('name'));
            $outputName = trim($output->getAttribute('name'));
            $base = $this->testBaseName(
                $inputName !== '' ? $inputName : $outputName,
                $index
            );
            if (
                $inputName !== ''
                && $outputName !== ''
                && $this->testBaseName($inputName, $index)
                    !== $this->testBaseName($outputName, $index)
            ) {
                throw new DomainException('같은 테스트 쌍의 입력·출력 이름이 다릅니다.');
            }
            if (isset($files[$base . '.in']) || isset($files[$base . '.out'])) {
                throw new DomainException('테스트데이터 이름이 중복되었습니다.');
            }
            $files[$base . '.in'] = (string) $input->textContent;
            $files[$base . '.out'] = (string) $output->textContent;
        }

        return $files;
    }

    private function encodeItem(
        XMLWriter $writer,
        ProblemXmlItem $item,
        bool $includeMetadata,
        bool $includeTestData
    ): void {
        $draft = $item->draft();
        if ($item->codeEntryMode() === CodeEntryMode::CodeOrdering && ! $includeMetadata) {
            throw new DomainException('코드 배열 문제는 메타데이터를 포함해 내보내야 합니다.');
        }
        $writer->startElement('item');
        if ($item->requestedProblemId() !== null) {
            $this->writeText(
                $writer,
                'problem_id',
                (string) $item->requestedProblemId()?->value()
            );
        }
        $this->writeText($writer, 'title', $draft->title());
        $this->writeText(
            $writer,
            'time_limit',
            (string) $draft->timeLimitSeconds(),
            ['unit' => 's']
        );
        $this->writeText(
            $writer,
            'memory_limit',
            (string) $draft->memoryLimitMegabytes(),
            ['unit' => 'mb']
        );
        foreach (
            [
                'description' => $draft->description(),
                'input' => $draft->input(),
                'output' => $draft->output(),
                'sample_input' => $draft->sampleInput(),
                'sample_output' => $draft->sampleOutput(),
                'hint' => $draft->hint(),
                'source' => $draft->source(),
                'spj' => $draft->specialJudge() ? '1' : '0',
                'defunct' => $draft->visibility()->judgeDefunct(),
                'bann' => $draft->forbiddenCode(),
                'front' => $draft->prefixCode(),
                'body' => $draft->starterCode(),
                'rear' => $draft->suffixCode(),
                'langmask' => $draft->languageMask() === null
                    ? ''
                    : (string) $draft->languageMask(),
                'credits' => $draft->credits(),
            ] as $name => $value
        ) {
            $this->writeText($writer, $name, $value);
        }
        if ($includeMetadata) {
            $writer->startElement('oj_metadata');
            if ($item->difficulty() !== null) {
                $writer->writeAttribute(
                    'difficulty',
                    (string) $item->difficulty()?->value()
                );
            }
            $writer->writeAttribute(
                'code_entry_mode',
                $item->codeEntryMode()->value
            );
            foreach ($item->topicKeys() as $topicKey) {
                $writer->startElement('topic');
                $writer->writeAttribute('key', $topicKey);
                $writer->endElement();
            }
            if ($item->completionSchema() !== null) {
                $this->writeText(
                    $writer,
                    'completion_schema',
                    $item->completionSchema()
                );
            }
            if ($item->orderingSchema() !== null) {
                $this->writeText($writer, 'ordering_schema', $item->orderingSchema());
            }
            $writer->endElement();
        }
        if ($includeTestData) {
            $this->writeTestFiles($writer, $item->testFiles());
        }
        $writer->endElement();
    }

    /**
     * @param array<string, string> $files
     */
    private function writeTestFiles(XMLWriter $writer, array $files): void
    {
        $bases = [];
        foreach ($files as $fileName => $contents) {
            unset($contents);
            if (str_ends_with($fileName, '.in')) {
                $bases[] = substr($fileName, 0, -3);
            }
        }
        sort($bases, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($bases as $base) {
            $outputName = isset($files[$base . '.out'])
                ? $base . '.out'
                : $base . '.ans';
            if (! isset($files[$base . '.in'], $files[$outputName])) {
                throw new DomainException('내보낼 테스트데이터 쌍이 완전하지 않습니다.');
            }
            $this->writeText(
                $writer,
                'test_input',
                $files[$base . '.in'],
                ['name' => $base]
            );
            $this->writeText(
                $writer,
                'test_output',
                $files[$outputName],
                ['name' => $base]
            );
        }
    }

    /**
     * @param array<string, string> $attributes
     */
    private function writeText(
        XMLWriter $writer,
        string $name,
        string $value,
        array $attributes = []
    ): void {
        if (
            preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1
        ) {
            throw new DomainException(
                $name . ' 필드에 XML로 내보낼 수 없는 문자가 있습니다.'
            );
        }
        $writer->startElement($name);
        foreach ($attributes as $attribute => $attributeValue) {
            $writer->writeAttribute($attribute, $attributeValue);
        }
        $writer->text($value);
        $writer->endElement();
    }

    private function boundedText(
        DOMElement $parent,
        string $name,
        int $maxBytes = self::MAX_TEXT_BYTES
    ): string {
        $value = $this->childText($parent, $name);
        if (strlen($value) > $maxBytes || preg_match('//u', $value) !== 1) {
            throw new DomainException($name . ' 필드가 UTF-8 또는 길이 제한을 벗어났습니다.');
        }

        return $value;
    }

    private function childText(DOMElement $parent, string $name): string
    {
        return (string) ($this->child($parent, $name)?->textContent ?? '');
    }

    private function child(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    private function children(DOMElement $parent, string $name): array
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === $name) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function limitValue(
        DOMElement $item,
        string $name,
        int $minimum,
        int $maximum,
        int $defaultValue,
        string $defaultUnit
    ): int {
        $node = $this->child($item, $name);
        if ($node === null || trim((string) $node->textContent) === '') {
            return $defaultValue;
        }
        $value = filter_var(
            trim((string) $node->textContent),
            FILTER_VALIDATE_FLOAT
        );
        if ($value === false || $value <= 0) {
            throw new DomainException($name . ' 값이 올바르지 않습니다.');
        }
        $unit = strtolower(trim($node->getAttribute('unit')));
        $unit = $unit === '' ? $defaultUnit : $unit;
        if ($name === 'time_limit') {
            $value = match ($unit) {
                'ms' => $value / 1000,
                's', 'sec', 'second', 'seconds' => $value,
                default => throw new DomainException('지원하지 않는 시간 제한 단위입니다.'),
            };
        } else {
            $value = match ($unit) {
                'kb' => $value / 1024,
                'mb' => $value,
                'gb' => $value * 1024,
                default => throw new DomainException('지원하지 않는 메모리 제한 단위입니다.'),
            };
        }
        $integer = (int) ceil($value);
        if ($integer < $minimum || $integer > $maximum) {
            throw new DomainException($name . ' 값이 허용 범위를 벗어났습니다.');
        }

        return $integer;
    }

    private function testBaseName(string $name, int $index): string
    {
        if ($name === '') {
            return 'case-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        }
        $name = str_replace('\\', '/', $name);
        if (str_contains($name, '/') || str_contains($name, "\0")) {
            throw new DomainException('테스트데이터 이름에는 경로를 넣을 수 없습니다.');
        }
        $base = preg_replace('/\.(?:in|out|ans)$/i', '', $name) ?? '';
        if (
            $base === ''
            || strlen($base) > 120
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $base) !== 1
        ) {
            throw new DomainException('테스트데이터 이름 형식이 올바르지 않습니다.');
        }

        return $base;
    }

    private function nonNegativeInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new DomainException($label . '은 정수여야 합니다.');
        }

        return (int) $value;
    }

    private function rawPositiveInt(string $value): ?int
    {
        $value = trim($value);
        if (
            preg_match('/^[1-9]\d*$/', $value) !== 1
            || strlen($value) > 10
            || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)
        ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param list<LibXMLError> $errors
     */
    private function parseError(array $errors): string
    {
        $first = $errors[0] ?? null;
        if (! $first instanceof LibXMLError) {
            return 'XML 문법을 분석하지 못했습니다.';
        }

        return 'XML 문법 오류: ' . trim($first->message)
            . ' (줄 ' . $first->line . ')';
    }
}
