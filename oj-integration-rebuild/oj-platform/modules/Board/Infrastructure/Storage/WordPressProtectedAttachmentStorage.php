<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Infrastructure\Storage;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\UploadedFile;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentRootProvider;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityAttachment;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunitySettings;
use CodingPanda\OjPlatform\Modules\Board\Domain\ProtectedAttachmentIntegrityIssue;
use CodingPanda\OjPlatform\Modules\Board\Domain\ProtectedAttachmentIntegrityReport;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WordPressProtectedAttachmentStorage implements ProtectedAttachmentStorage
{
    private const BANNED_SEGMENTS = [
        'php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js', 'exe',
        'com', 'bat', 'cmd', 'sh', 'ps1',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain', 'application/octet-stream'],
        'hwp' => [
            'application/x-hwp',
            'application/haansofthwp',
            'application/octet-stream',
        ],
        'hwpx' => ['application/zip', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/octet-stream',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(
        private readonly ProtectedAttachmentRootProvider $roots
    ) {
    }

    public function validateMany(
        array $files,
        CommunitySettings $settings,
        int $maximumCount
    ): void {
        if (count($files) > $maximumCount) {
            throw new DomainException('첨부 가능한 파일 개수를 초과했습니다.');
        }
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw new DomainException('첨부파일 요청 형식이 올바르지 않습니다.');
            }
            $this->inspect($file, $settings);
        }
    }

    public function store(
        UploadedFile $file,
        int $postId,
        ?int $commentId,
        int $actorUserId,
        CommunitySettings $settings
    ): CommunityAttachment {
        [$name, $extension, $mime] = $this->inspect($file, $settings);
        $root = $this->ensureRoot();
        $storageName = bin2hex(random_bytes(24)) . '.' . $extension;
        $path = $root . DIRECTORY_SEPARATOR . $storageName;
        if (
            ! is_uploaded_file($file->temporaryPath())
            || ! move_uploaded_file($file->temporaryPath(), $path)
        ) {
            throw new RuntimeException('첨부파일을 보호 저장소로 이동하지 못했습니다.');
        }
        @chmod($path, 0640);
        $actualSize = filesize($path);
        $actualMime = $this->mime($path);
        if (
            $actualSize === false
            || (int) $actualSize !== $file->size()
            || ! hash_equals($mime, $actualMime)
        ) {
            @unlink($path);
            throw new DomainException('이동된 첨부파일의 크기 또는 형식이 변경되었습니다.');
        }

        if (! function_exists('wp_insert_post')) {
            @unlink($path);
            throw new RuntimeException('WordPress 첨부 API를 사용할 수 없습니다.');
        }
        $attachmentId = 0;
        try {
            $inserted = wp_insert_post([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_parent' => $postId,
                'post_author' => $actorUserId,
                'post_title' => pathinfo($name, PATHINFO_FILENAME),
                'post_content' => '',
                'post_excerpt' => '',
                'post_mime_type' => $mime,
                'guid' => '',
            ], true);
            if (
                (function_exists('is_wp_error') && is_wp_error($inserted))
                || (int) $inserted < 1
            ) {
                throw new RuntimeException('첨부파일 레코드를 저장하지 못했습니다.');
            }
            $attachmentId = (int) $inserted;
            $this->meta($attachmentId, '_oj_protected_path', $storageName);
            $this->meta($attachmentId, '_oj_original_name', $name);
            $this->meta($attachmentId, '_oj_mime_type', $mime);
            $this->meta($attachmentId, '_oj_file_size', (string) $actualSize);
            $this->meta(
                $attachmentId,
                '_oj_attachment_comment_id',
                $commentId === null ? '' : (string) $commentId
            );
            $this->meta($attachmentId, '_oj_attachment_detached', '0');
            $this->meta($attachmentId, '_oj_uploaded_by', (string) $actorUserId);

            return new CommunityAttachment(
                $attachmentId,
                $postId,
                $commentId,
                $name,
                $mime,
                (int) $actualSize,
                $storageName
            );
        } catch (Throwable $error) {
            $this->removeIncompleteRecord($attachmentId);
            @unlink($path);
            throw $error;
        }
    }

    public function forPost(int $postId): array
    {
        return array_values(array_filter(
            $this->forParentPost($postId),
            static fn (CommunityAttachment $attachment): bool =>
                $attachment->commentId() === null
        ));
    }

    public function forComment(int $commentId): array
    {
        if (! function_exists('get_posts')) {
            return [];
        }
        $posts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_oj_attachment_comment_id',
                    'value' => $commentId,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => '_oj_attachment_detached',
                    'value' => '0',
                    'compare' => '=',
                ],
            ],
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $items = [];
        foreach (is_array($posts) ? $posts : [] as $post) {
            $item = $this->hydrate($post);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function find(int $attachmentId): ?CommunityAttachment
    {
        if (
            ! function_exists('get_post')
            || $this->postMeta($attachmentId, '_oj_attachment_detached') === '1'
        ) {
            return null;
        }

        return $this->hydrate(get_post($attachmentId));
    }

    public function read(CommunityAttachment $attachment): string
    {
        $root = realpath($this->ensureRoot());
        $path = realpath(
            $this->ensureRoot()
            . DIRECTORY_SEPARATOR
            . $attachment->storagePath()
        );
        if (
            $root === false
            || $path === false
            || ! str_starts_with(
                $path,
                $root . DIRECTORY_SEPARATOR
            )
            || ! is_file($path)
        ) {
            throw new RuntimeException('보호 첨부파일 원본을 찾을 수 없습니다.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('보호 첨부파일을 읽지 못했습니다.');
        }

        return $contents;
    }

    public function detach(int $attachmentId, int $actorUserId): void
    {
        $attachment = $this->find($attachmentId);
        if ($attachment === null) {
            throw new DomainException('제거할 첨부파일을 찾을 수 없습니다.');
        }
        $this->meta($attachmentId, '_oj_attachment_detached', '1');
        $this->meta(
            $attachmentId,
            '_oj_attachment_detached_by',
            (string) $actorUserId
        );
        $this->meta(
            $attachmentId,
            '_oj_attachment_detached_at',
            gmdate('Y-m-d H:i:s')
        );
        if (function_exists('wp_update_post')) {
            wp_update_post(['ID' => $attachmentId, 'post_parent' => 0]);
        }
    }

    public function integrityReport(
        int $limit = 5000
    ): ProtectedAttachmentIntegrityReport {
        if (
            ! function_exists('get_posts')
            || ! function_exists('get_post')
            || ! function_exists('get_post_meta')
            || ! function_exists('get_comment')
        ) {
            throw new RuntimeException('WordPress 첨부 검사 API를 사용할 수 없습니다.');
        }
        $limit = max(1, min(20000, $limit));
        $root = $this->ensureRoot();
        $records = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => $limit + 1,
            'meta_key' => '_oj_protected_path',
            'meta_compare' => 'EXISTS',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $records = is_array($records) ? array_values($records) : [];
        $truncated = count($records) > $limit;
        $records = array_slice($records, 0, $limit);
        $issues = [];
        $references = [];

        foreach ($records as $record) {
            if (! is_object($record)) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'record_invalid',
                    null,
                    '(알 수 없음)',
                    '보호 첨부 레코드 형식이 올바르지 않습니다.'
                );
                continue;
            }
            $attachmentId = (int) ($record->ID ?? 0);
            $storageName = trim((string) $this->postMeta(
                $attachmentId,
                '_oj_protected_path'
            ));
            if (
                $attachmentId < 1
                || $storageName === ''
                || basename($storageName) !== $storageName
            ) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'record_invalid',
                    $attachmentId > 0 ? $attachmentId : null,
                    $storageName === '' ? '(비어 있음)' : $storageName,
                    '보호 첨부 레코드의 저장 참조가 올바르지 않습니다.'
                );
                continue;
            }
            if (isset($references[$storageName])) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'duplicate_reference',
                    $attachmentId,
                    $storageName,
                    '둘 이상의 첨부 레코드가 같은 보호 파일을 참조합니다.'
                );
            }
            $references[$storageName] = true;
            if (! is_file($root . DIRECTORY_SEPARATOR . $storageName)) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'file_missing',
                    $attachmentId,
                    $storageName,
                    '첨부 레코드가 참조하는 보호 파일이 없습니다.'
                );
            }
            if (
                $this->postMeta(
                    $attachmentId,
                    '_oj_attachment_detached'
                ) === '1'
            ) {
                continue;
            }
            $parentId = (int) ($record->post_parent ?? 0);
            $parent = get_post($parentId);
            if (
                ! is_object($parent)
                || ! in_array(
                    (string) ($parent->post_type ?? ''),
                    ['oj_board_post', 'oj_notice'],
                    true
                )
            ) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'parent_missing',
                    $attachmentId,
                    $storageName,
                    '활성 첨부의 게시글 또는 공지 부모를 찾을 수 없습니다.'
                );
                continue;
            }
            $commentId = (int) $this->postMeta(
                $attachmentId,
                '_oj_attachment_comment_id'
            );
            if ($commentId < 1) {
                continue;
            }
            $comment = get_comment($commentId);

            if (! is_object($comment)) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'comment_missing',
                    $attachmentId,
                    $storageName,
                    '첨부가 연결된 댓글을 찾을 수 없습니다.'
                );
                continue;
            }
            if ((int) ($comment->comment_post_ID ?? 0) !== $parentId) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'comment_parent_mismatch',
                    $attachmentId,
                    $storageName,
                    '첨부 댓글과 부모 게시글의 관계가 일치하지 않습니다.'
                );
            }
        }

        $fileCount = 0;
        $entries = scandir($root);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (
                in_array($entry, ['.', '..', 'index.php', '.htaccess', 'web.config'], true)
                || ! is_file($root . DIRECTORY_SEPARATOR . $entry)
            ) {
                continue;
            }
            ++$fileCount;
            if (! $truncated && ! isset($references[$entry])) {
                $issues[] = new ProtectedAttachmentIntegrityIssue(
                    'file_orphaned',
                    null,
                    $entry,
                    '어떤 첨부 레코드에서도 참조하지 않는 보호 파일입니다.'
                );
            }
        }

        return new ProtectedAttachmentIntegrityReport(
            count($records),
            $fileCount,
            $issues,
            $truncated
        );
    }

    /**
     * @return array{string, string, string}
     */
    private function inspect(
        UploadedFile $file,
        CommunitySettings $settings
    ): array {
        if (! $file->isSuccessful()) {
            throw new DomainException('업로드가 완료된 파일만 첨부할 수 있습니다.');
        }
        if ($file->size() > $settings->maxFileBytes()) {
            throw new DomainException('첨부파일 크기 제한을 초과했습니다.');
        }
        $name = basename(str_replace('\\', '/', trim($file->clientName())));
        if (
            $name === ''
            || strlen($name) > 255
            || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new DomainException('첨부파일 이름이 올바르지 않습니다.');
        }
        $parts = array_map('strtolower', explode('.', $name));
        if (count($parts) < 2) {
            throw new DomainException('첨부파일 확장자를 확인할 수 없습니다.');
        }
        $extension = (string) end($parts);
        foreach (array_slice($parts, 0, -1) as $segment) {
            if (in_array($segment, self::BANNED_SEGMENTS, true)) {
                throw new DomainException('이중 확장자 실행 파일은 첨부할 수 없습니다.');
            }
        }
        $allowed = $settings->allowedExtensions();
        if ($extension === 'zip' && ! $settings->zipAllowed()) {
            throw new DomainException('압축 파일 첨부가 허용되지 않았습니다.');
        }
        if (! in_array($extension, $allowed, true)) {
            throw new DomainException('허용되지 않은 첨부파일 확장자입니다.');
        }
        $mime = $this->mime($file->temporaryPath());
        if (! in_array($mime, self::MIME_TYPES[$extension] ?? [], true)) {
            throw new DomainException('첨부파일 확장자와 파일 형식이 일치하지 않습니다.');
        }

        return [$name, $extension, $mime];
    }

    private function mime(string $path): string
    {
        if (! class_exists(\finfo::class)) {
            throw new RuntimeException('첨부파일 MIME 검사를 사용할 수 없습니다.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : '';
    }

    /**
     * @return list<CommunityAttachment>
     */
    private function forParentPost(int $postId): array
    {
        if (! function_exists('get_posts')) {
            return [];
        }
        $posts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $postId,
            'posts_per_page' => -1,
            'meta_key' => '_oj_attachment_detached',
            'meta_value' => '0',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $items = [];
        foreach (is_array($posts) ? $posts : [] as $post) {
            $item = $this->hydrate($post);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function hydrate(mixed $post): ?CommunityAttachment
    {
        if (! is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') {
            return null;
        }
        $attachmentId = (int) ($post->ID ?? 0);
        $commentId = (int) $this->postMeta(
            $attachmentId,
            '_oj_attachment_comment_id'
        );

        try {
            return new CommunityAttachment(
                $attachmentId,
                (int) ($post->post_parent ?? 0),
                $commentId > 0 ? $commentId : null,
                (string) $this->postMeta($attachmentId, '_oj_original_name'),
                (string) $this->postMeta($attachmentId, '_oj_mime_type'),
                (int) $this->postMeta($attachmentId, '_oj_file_size'),
                (string) $this->postMeta($attachmentId, '_oj_protected_path')
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function ensureRoot(): string
    {
        $root = rtrim($this->roots->root(), '\\/');
        if (
            ($root === '' || (! is_dir($root) && ! mkdir($root, 0750, true)))
            || ! is_writable($root)
        ) {
            throw new RuntimeException('보호 첨부 저장소를 준비하지 못했습니다.');
        }
        $this->guardFile($root . DIRECTORY_SEPARATOR . 'index.php', "<?php\nhttp_response_code(404);\n");
        $this->guardFile(
            $root . DIRECTORY_SEPARATOR . '.htaccess',
            "Require all denied\nDeny from all\n"
        );
        $this->guardFile(
            $root . DIRECTORY_SEPARATOR . 'web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration><system.webServer><security><authorization>"
            . "<remove users=\"*\" roles=\"\" verbs=\"\"/>"
            . "<add accessType=\"Deny\" users=\"*\"/>"
            . "</authorization></security></system.webServer></configuration>\n"
        );

        return $root;
    }

    private function guardFile(string $path, string $contents): void
    {
        if (is_file($path)) {
            return;
        }
        $written = file_put_contents($path, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            @unlink($path);
            throw new RuntimeException('보호 첨부 저장소 접근 차단 파일을 만들지 못했습니다.');
        }
        @chmod($path, 0640);
    }

    private function meta(int $attachmentId, string $key, string $value): void
    {
        if (
            ! function_exists('update_post_meta')
            || ! function_exists('get_post_meta')
        ) {
            throw new RuntimeException('WordPress 첨부 메타 API를 사용할 수 없습니다.');
        }
        $updated = update_post_meta($attachmentId, $key, $value);
        if (
            $updated === false
            && (string) get_post_meta($attachmentId, $key, true) !== $value
        ) {
            throw new RuntimeException('첨부파일 메타데이터를 저장하지 못했습니다.');
        }
    }

    private function removeIncompleteRecord(int $attachmentId): void
    {
        if ($attachmentId < 1) {
            return;
        }
        try {
            if (function_exists('wp_delete_attachment')) {
                wp_delete_attachment($attachmentId, true);
            } elseif (function_exists('wp_delete_post')) {
                wp_delete_post($attachmentId, true);
            }
        } catch (Throwable) {
        }
    }

    private function postMeta(int $attachmentId, string $key): mixed
    {
        return function_exists('get_post_meta')
            ? get_post_meta($attachmentId, $key, true)
            : '';
    }
}
