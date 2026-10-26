<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

use CodingPanda\OjPlatform\Modules\Board\Domain\CommunitySettings;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

final class BoardSettingsService
{
    private const DEFAULT_EXTENSIONS = [
        'pdf', 'png', 'jpg', 'jpeg', 'hwp', 'hwpx', 'doc', 'docx',
        'ppt', 'pptx', 'xls', 'xlsx', 'txt',
    ];

    private const DEFAULTS = [
        'global_posting' => true,
        'board_comments' => true,
        'problem_questions' => true,
        'class_questions' => true,
        'contest_questions' => true,
        'group_questions' => true,
        'notice_comments_default' => false,
        'attachments' => true,
        'allowed_extensions' => self::DEFAULT_EXTENSIONS,
        'zip_allowed' => false,
        'max_file_bytes' => 10485760,
        'max_post_files' => 5,
        'max_comment_files' => 3,
        'post_rate_limit' => 5,
        'comment_rate_limit' => 20,
    ];

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('board', self::DEFAULTS);
    }

    public function current(): CommunitySettings
    {
        $value = $this->snapshot()->value();
        $value = is_array($value) ? $value : [];
        $extensions = is_array($value['allowed_extensions'] ?? null)
            ? $value['allowed_extensions']
            : self::DEFAULT_EXTENSIONS;

        return new CommunitySettings(
            (bool) ($value['global_posting'] ?? true),
            (bool) ($value['board_comments'] ?? true),
            (bool) ($value['problem_questions'] ?? true),
            (bool) ($value['class_questions'] ?? true),
            (bool) ($value['contest_questions'] ?? true),
            (bool) ($value['group_questions'] ?? true),
            (bool) ($value['notice_comments_default'] ?? false),
            (bool) ($value['attachments'] ?? true),
            $this->extensions($extensions),
            (bool) ($value['zip_allowed'] ?? false),
            (int) ($value['max_file_bytes'] ?? 10485760),
            (int) ($value['max_post_files'] ?? 5),
            (int) ($value['max_comment_files'] ?? 3),
            (int) ($value['post_rate_limit'] ?? 5),
            (int) ($value['comment_rate_limit'] ?? 20)
        );
    }

    /**
     * @param list<string> $allowedExtensions
     */
    public function save(
        array $allowedExtensions,
        bool $globalPosting,
        bool $boardComments,
        bool $problemQuestions,
        bool $classQuestions,
        bool $contestQuestions,
        bool $groupQuestions,
        bool $noticeCommentsDefault,
        bool $attachments,
        bool $zipAllowed,
        int $maxFileBytes,
        int $maxPostFiles,
        int $maxCommentFiles,
        int $expectedRevision,
        int $postRateLimit = 5,
        int $commentRateLimit = 20
    ): void {
        $validated = new CommunitySettings(
            $globalPosting,
            $boardComments,
            $problemQuestions,
            $classQuestions,
            $contestQuestions,
            $groupQuestions,
            $noticeCommentsDefault,
            $attachments,
            $this->extensions($allowedExtensions),
            $zipAllowed,
            $maxFileBytes,
            $maxPostFiles,
            $maxCommentFiles,
            $postRateLimit,
            $commentRateLimit
        );

        $this->settings->save('board', [
            'global_posting' => $validated->globalPosting(),
            'board_comments' => $validated->boardComments(),
            'problem_questions' => $problemQuestions,
            'class_questions' => $classQuestions,
            'contest_questions' => $contestQuestions,
            'group_questions' => $groupQuestions,
            'notice_comments_default' => $validated->noticeCommentsDefault(),
            'attachments' => $validated->attachments(),
            'allowed_extensions' => $validated->allowedExtensions(),
            'zip_allowed' => $validated->zipAllowed(),
            'max_file_bytes' => $validated->maxFileBytes(),
            'max_post_files' => $validated->maxPostFiles(),
            'max_comment_files' => $validated->maxCommentFiles(),
            'post_rate_limit' => $validated->postRateLimit(),
            'comment_rate_limit' => $validated->commentRateLimit(),
        ], $expectedRevision);
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function extensions(array $values): array
    {
        $allowed = [...self::DEFAULT_EXTENSIONS, 'zip'];
        $normalized = [];
        foreach ($values as $value) {
            $extension = strtolower(ltrim(trim((string) $value), '.'));
            if (in_array($extension, $allowed, true)) {
                $normalized[$extension] = true;
            }
        }

        return $normalized === []
            ? self::DEFAULT_EXTENSIONS
            : array_keys($normalized);
    }
}
