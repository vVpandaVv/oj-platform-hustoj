<?php
/**
 * Lets an editor deliberately re-pull ONE lesson's content from its
 * data/lessons/{topicId}.json fixture into the live WordPress post, after
 * the file has been hand-edited (e.g. during a content revision pass).
 *
 * This is distinct from LegacyContentMigrator, which only ever runs once
 * per site (guarded by an activation-time option) and only ever writes a
 * lesson's content if it's still at its very first revision -- by design,
 * it never touches a lesson someone has already edited through the admin
 * UI. That guard is correct for "don't clobber live edits during automatic
 * setup," but it also means there is currently no way to push a later,
 * intentional JSON revision into a lesson that has been opened/saved even
 * once. This service is that explicit, on-demand path: the caller is
 * consciously asking to overwrite the current document body with whatever
 * is in the JSON file right now.
 *
 * @package ReadContentsLibrary\Editor\Migration
 */

declare(strict_types=1);

namespace ReadContents\Library\Editor\Migration;

use InvalidArgumentException;
use ReadContents\Library\Editor\Content\ContentNode;
use ReadContents\Library\Editor\Content\ContentNodeType;
use ReadContents\Library\Editor\Storage\ContentRepository;

final class ContentReimportService
{
    public function __construct(private readonly ContentRepository $repository)
    {
    }

    public static function hasLegacySources(): bool
    {
        return (bool) glob(READCONTENTS_LIBRARY_PATH . 'data/lessons/*.json');
    }

    private function assertLegacySources(): void
    {
        if (!self::hasLegacySources()) {
            throw new InvalidArgumentException(
                __('읽기자료는 플러그인과 분리되었습니다. 패키지 가져오기/내보내기에서 콘텐츠 ZIP을 선택해 주세요. 현재 저장된 본문은 변경하지 않았습니다.', 'readcontents-library')
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function reimportLesson(string $lessonId): array
    {
        $this->assertLegacySources();
        $lesson = $this->requireLesson($lessonId);
        $topicId = $this->requireLegacyTopicId($lesson);
        $blocks = $this->readLegacyBlocks($topicId);

        $current = $this->repository->getLessonDocument($lesson->id);
        $payload = LegacyBlockConverter::convert($blocks, $topicId);
        $payload['revision'] = $current->revision;

        $document = $this->repository->saveLessonDocument($lesson->id, $payload);

        return $document->toArray();
    }

    /**
     * @return array{succeeded: list<string>, skipped: list<string>, failed: array<string, string>}
     */
    public function reimportAllLessons(): array
    {
        $this->assertLegacySources();
        $result = [
            'succeeded' => [],
            'skipped' => [],
            'failed' => [],
        ];

        foreach ($this->repository->listNodes(['type' => ContentNodeType::LESSON->value]) as $lesson) {
            $topicId = (string) ($lesson->metadata['legacyTopicId'] ?? '');

            if ('' === $topicId) {
                $result['skipped'][] = $lesson->id;
                continue;
            }

            try {
                $this->reimportLesson($lesson->id);
                $result['succeeded'][] = $lesson->id;
            } catch (\Throwable $error) {
                $result['failed'][$lesson->id] = $error->getMessage();
            }
        }

        return $result;
    }

    private function requireLesson(string $lessonId): ContentNode
    {
        $lesson = $this->repository->getNode($lessonId);

        if (! $lesson || ContentNodeType::LESSON !== $lesson->type) {
            throw new InvalidArgumentException(__('레슨을 찾을 수 없습니다.', 'readcontents-library'));
        }

        return $lesson;
    }

    private function requireLegacyTopicId(ContentNode $lesson): string
    {
        $topicId = (string) ($lesson->metadata['legacyTopicId'] ?? '');

        if ('' === $topicId) {
            throw new InvalidArgumentException(
                __('이 레슨은 data/lessons JSON에서 만들어진 것이 아니라 다시 가져올 원본 파일이 없습니다.', 'readcontents-library')
            );
        }

        return $topicId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readLegacyBlocks(string $topicId): array
    {
        $relativePath = 'data/lessons/' . $topicId . '.json';
        $path = READCONTENTS_LIBRARY_PATH . $relativePath;

        if (! is_readable($path)) {
            throw new InvalidArgumentException(
                __('읽기자료는 플러그인과 분리되었습니다. 콘텐츠 가져오기에서 해당 자료의 ZIP을 선택하고 중복 레슨 처리 방식을 확인해 주세요.', 'readcontents-library')
            );
        }

        $decoded = function_exists('wp_json_file_decode')
            ? wp_json_file_decode($path, ['associative' => true])
            : json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(
                sprintf(__('%s 파일이 올바른 JSON 형식이 아닙니다.', 'readcontents-library'), $relativePath)
            );
        }

        $blocks = $decoded['blocks'] ?? null;

        if (! is_array($blocks)) {
            throw new InvalidArgumentException(
                sprintf(__('%s 파일에 blocks 배열이 없습니다.', 'readcontents-library'), $relativePath)
            );
        }

        return $blocks;
    }
}
