<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use DomainException;
use JsonException;
use RuntimeException;

final class PrivateFeedbackGuideRepository implements FeedbackGuideRepository
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const BUILT_INS = [
        'foundation-learning' => [
            'name' => '기본 학습 안내',
            'level' => 'insufficient',
            'purpose' => '자료가 부족할 때 수준을 단정하지 않고 결과를 이해하도록 돕습니다.',
            'instructions' => '결과의 의미와 확인할 개념 하나를 설명하고 자기 점검 질문을 제시합니다.',
        ],
        'foundation-concept' => [
            'name' => '기본 개념 확인',
            'level' => 'insufficient',
            'purpose' => '결과와 직접 연결된 기본 개념부터 확인합니다.',
            'instructions' => '수준을 호명하지 말고 문제 조건과 코드에서 확인할 핵심 개념 하나를 질문합니다.',
        ],
        'beginner-steps' => [
            'name' => '초급 단계 안내',
            'level' => 'beginner',
            'purpose' => '확인할 위치와 다음 행동을 구체적으로 좁힙니다.',
            'instructions' => '오류 가능 위치를 좁히고 한 번에 수행할 확인 행동을 순서대로 최대 세 단계 제시합니다.',
        ],
        'beginner-narrow' => [
            'name' => '초급 오류 좁히기',
            'level' => 'beginner',
            'purpose' => '오류 가능성이 높은 코드 범위를 순서대로 확인합니다.',
            'instructions' => '변수와 조건을 쉬운 말로 설명하고 작은 예제에서 확인할 값을 구체적으로 안내합니다.',
        ],
        'intermediate-trace' => [
            'name' => '중급 실행 추적',
            'level' => 'intermediate',
            'purpose' => '변수 값과 실행 흐름을 스스로 추적하도록 돕습니다.',
            'instructions' => '특정 입력의 변수 값과 분기 흐름을 추적하는 질문을 중심으로 안내합니다.',
        ],
        'intermediate-conditions' => [
            'name' => '중급 조건 비교',
            'level' => 'intermediate',
            'purpose' => '문제 조건과 코드의 분기·종료 조건을 비교합니다.',
            'instructions' => '문제 조건과 코드 조건의 차이를 직접 발견할 수 있도록 비교 질문을 제시합니다.',
        ],
        'advanced-review' => [
            'name' => '고급 자기 점검',
            'level' => 'advanced',
            'purpose' => '경계값과 예외 입력을 스스로 검토합니다.',
            'instructions' => '경계값, 예외 입력, 자료형 범위와 복잡도를 질문 중심으로 점검하게 합니다.',
        ],
        'advanced-complexity' => [
            'name' => '고급 복잡도 검토',
            'level' => 'advanced',
            'purpose' => '입력 크기와 시간·메모리 복잡도를 검토합니다.',
            'instructions' => '정답 구현을 제시하지 않고 병목과 복잡도 가정을 반례 질문으로 점검하게 합니다.',
        ],
    ];

    public function __construct(private readonly PrivateDataRootProvider $roots)
    {
    }

    public function all(bool $includeArchived = false): array
    {
        $guides = [];
        foreach (self::BUILT_INS as $key => $value) {
            $guides[] = $this->builtIn($key, $value);
        }
        foreach ($this->manifestFiles() as $file) {
            $guide = $this->readManifest($file);
            if ($guide !== null && ($includeArchived || $guide->isActive())) {
                $guides[] = $guide;
            }
        }
        usort(
            $guides,
            static fn (FeedbackGuide $left, FeedbackGuide $right): int =>
                [$left->level(), $left->name()]
                <=> [$right->level(), $right->name()]
        );

        return $guides;
    }

    public function find(string $key): ?FeedbackGuide
    {
        if (isset(self::BUILT_INS[$key])) {
            return $this->builtIn($key, self::BUILT_INS[$key]);
        }
        $path = $this->manifestPath($key);

        return is_file($path) ? $this->readManifest($path) : null;
    }

    public function save(
        ?string $key,
        string $name,
        string $level,
        string $purpose,
        string $instructions,
        int $actorUserId
    ): FeedbackGuide {
        $this->assertContent($name, $level, $purpose, $instructions, $actorUserId);
        if ($key === null || trim($key) === '') {
            $key = $this->newKey($name);
        }
        if (isset(self::BUILT_INS[$key])) {
            throw new DomainException('기본 지침은 복제한 뒤 수정할 수 있습니다.');
        }
        $existing = $this->find($key);
        $revision = ($existing?->revision() ?? 0) + 1;
        $updatedAt = gmdate('Y-m-d H:i:s');
        $revisionData = [
            'key' => $key,
            'name' => trim($name),
            'level' => $level,
            'purpose' => trim($purpose),
            'instructions' => trim($instructions),
            'revision' => $revision,
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $updatedAt,
        ];
        $this->writeJson($this->revisionPath($key, $revision), $revisionData);
        $this->writeJson($this->manifestPath($key), [
            'key' => $key,
            'name' => trim($name),
            'level' => $level,
            'purpose' => trim($purpose),
            'revision' => $revision,
            'active' => $existing?->isActive() ?? false,
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $updatedAt,
        ]);

        return new FeedbackGuide(
            $key,
            trim($name),
            $level,
            trim($purpose),
            trim($instructions),
            $revision,
            $existing?->isActive() ?? false,
            false,
            $updatedAt
        );
    }

    public function duplicate(
        string $key,
        int $actorUserId
    ): FeedbackGuide {
        $guide = $this->find($key);
        if ($guide === null) {
            throw new DomainException('복제할 AI 답변 지침을 찾을 수 없습니다.');
        }

        return $this->save(
            null,
            $guide->name() . ' 복사본',
            $guide->level(),
            $guide->purpose(),
            $guide->instructions(),
            $actorUserId
        );
    }

    public function setArchived(
        string $key,
        bool $archived,
        int $actorUserId
    ): void {
        $guide = $this->find($key);
        if ($guide === null || $guide->isBuiltIn()) {
            throw new DomainException('기본 지침은 보관 상태를 변경할 수 없습니다.');
        }
        $this->writeJson($this->manifestPath($key), [
            'key' => $guide->key(),
            'name' => $guide->name(),
            'level' => $guide->level(),
            'purpose' => $guide->purpose(),
            'revision' => $guide->revision(),
            'active' => ! $archived,
            'updated_by_user_id' => $actorUserId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function revisions(string $key): array
    {
        if (isset(self::BUILT_INS[$key])) {
            return [[
                'revision' => 1,
                'updated_at' => '배포 기본값',
                'updated_by_user_id' => 0,
            ]];
        }
        $directory = $this->guideDirectory($key) . DIRECTORY_SEPARATOR
            . 'revisions';
        if (! is_dir($directory)) {
            return [];
        }
        $rows = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $value = $this->readJson($file);
            if ($value !== null) {
                $rows[] = $value;
            }
        }
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                (int) ($right['revision'] ?? 0)
                <=> (int) ($left['revision'] ?? 0)
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function builtIn(string $key, array $value): FeedbackGuide
    {
        return new FeedbackGuide(
            $key,
            (string) $value['name'],
            (string) $value['level'],
            (string) $value['purpose'],
            (string) $value['instructions'],
            1,
            true,
            true,
            '배포 기본값'
        );
    }

    private function assertContent(
        string $name,
        string $level,
        string $purpose,
        string $instructions,
        int $actorUserId
    ): void {
        if (
            $actorUserId < 1
            || trim($name) === ''
            || $this->length($name) > 100
            || $this->length($purpose) > 500
            || trim($instructions) === ''
            || $this->length($instructions) > 10000
            || ! in_array(
                $level,
                ['insufficient', 'beginner', 'intermediate', 'advanced'],
                true
            )
            || preg_match('/<\\/?(?:script|iframe|object)\\b/i', $instructions)
        ) {
            throw new DomainException('AI 답변 지침 입력이 올바르지 않습니다.');
        }
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private function newKey(string $name): string
    {
        $base = strtolower(trim((string) preg_replace(
            '/[^a-z0-9]+/i',
            '-',
            $name
        ), '-'));
        $base = $base === '' ? 'custom-guide' : substr($base, 0, 70);

        return $base . '-' . substr(hash('sha256', $name . microtime(true)), 0, 10);
    }

    /**
     * @return list<string>
     */
    private function manifestFiles(): array
    {
        $directory = $this->root();

        return array_values(array_filter(
            glob($directory . DIRECTORY_SEPARATOR . '*'
                . DIRECTORY_SEPARATOR . 'manifest.json') ?: [],
            'is_file'
        ));
    }

    private function readManifest(string $path): ?FeedbackGuide
    {
        $value = $this->readJson($path);
        if ($value === null) {
            return null;
        }
        $key = (string) ($value['key'] ?? '');
        $revision = (int) ($value['revision'] ?? 0);
        $revisionValue = $this->readJson(
            $this->revisionPath($key, $revision)
        );
        if ($revisionValue === null) {
            return null;
        }

        return new FeedbackGuide(
            $key,
            (string) ($value['name'] ?? ''),
            (string) ($value['level'] ?? ''),
            (string) ($value['purpose'] ?? ''),
            (string) ($revisionValue['instructions'] ?? ''),
            $revision,
            (bool) ($value['active'] ?? false),
            false,
            (string) ($value['updated_at'] ?? '')
        );
    }

    private function root(): string
    {
        return rtrim($this->roots->root(), '\\/')
            . DIRECTORY_SEPARATOR . 'ai-feedback-guides';
    }

    private function guideDirectory(string $key): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $key) !== 1) {
            throw new DomainException('AI 답변 지침 키가 올바르지 않습니다.');
        }

        return $this->root() . DIRECTORY_SEPARATOR . $key;
    }

    private function manifestPath(string $key): string
    {
        return $this->guideDirectory($key) . DIRECTORY_SEPARATOR
            . 'manifest.json';
    }

    private function revisionPath(string $key, int $revision): string
    {
        return $this->guideDirectory($key) . DIRECTORY_SEPARATOR
            . 'revisions' . DIRECTORY_SEPARATOR
            . sprintf('%06d.json', $revision);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function writeJson(string $path, array $value): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('AI 답변 지침 저장 폴더를 만들지 못했습니다.');
        }
        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new RuntimeException('AI 답변 지침을 직렬화하지 못했습니다.', 0, $error);
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('AI 답변 지침을 원자적으로 저장하지 못했습니다.');
        }
        if (! is_file($path)) {
            if (! rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('AI 답변 지침을 원자적으로 저장하지 못했습니다.');
            }

            return;
        }
        $backup = $path . '.bak-' . bin2hex(random_bytes(6));
        if (! rename($path, $backup)) {
            @unlink($temporary);
            throw new RuntimeException('기존 AI 답변 지침을 잠그지 못했습니다.');
        }
        if (! rename($temporary, $path)) {
            @rename($backup, $path);
            @unlink($temporary);
            throw new RuntimeException('AI 답변 지침을 교체하지 못했습니다.');
        }
        @unlink($backup);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if (! is_string($json)) {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
