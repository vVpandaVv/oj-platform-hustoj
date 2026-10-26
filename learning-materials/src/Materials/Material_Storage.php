<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

use DomainException;
use RuntimeException;

/**
 * Stores uploaded material files outside wp-content/uploads, under a
 * directory guarded against direct web access (index.php 404 + .htaccess
 * deny-all + web.config deny-all), with random on-disk filenames so the
 * original name never leaks the download path. Every read/write is gated
 * by the caller (Materials_Action_Controller) checking Material_Access
 * first — this class itself has no notion of who is allowed to see what.
 */
final class Material_Storage {
    public const MAX_FILE_BYTES = 50 * 1024 * 1024;

    private const BANNED_SEGMENTS = array(
        'php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js', 'exe',
        'com', 'bat', 'cmd', 'sh', 'ps1',
    );

    /** @var array<string, list<string>> */
    private const MIME_TYPES = array(
        'doc' => array('application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'),
        'xls' => array('application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'),
        'txt' => array('text/plain'),
        'csv' => array('text/plain', 'text/csv', 'application/csv'),
        'jpg' => array('image/jpeg'), 'jpeg' => array('image/jpeg'),
        'png' => array('image/png'), 'gif' => array('image/gif'), 'webp' => array('image/webp'),
        'pdf' => array('application/pdf'),
        'ppt' => array('application/vnd.ms-powerpoint', 'application/octet-stream'),
        'pptx' => array(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/octet-stream',
        ),
    );

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file one entry of $_FILES
     * @return array{storage_filename:string,original_filename:string,extension:string,mime_type:string,file_size:int}
     */
    public function store(array $file): array {
        list($name, $extension, $mime) = $this->inspect($file);
        $root = $this->ensure_root();
        $storage_filename = bin2hex(random_bytes(24)) . '.' . $extension;
        $path = $root . DIRECTORY_SEPARATOR . $storage_filename;

        if (! is_uploaded_file($file['tmp_name']) || ! move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException(__('학습자료 파일을 보호 저장소로 이동하지 못했습니다.', 'learning-materials'));
        }
        @chmod($path, 0640);

        $actual_size = filesize($path);
        $actual_mime = $this->mime($path);
        if (
            false === $actual_size
            || (int) $actual_size !== (int) $file['size']
            || ! hash_equals($mime, $actual_mime)
        ) {
            @unlink($path);
            throw new DomainException(__('이동된 학습자료 파일의 크기 또는 형식이 변경되었습니다.', 'learning-materials'));
        }

        return array(
            'storage_filename' => $storage_filename,
            'original_filename' => $name,
            'extension' => $extension,
            'mime_type' => $mime,
            'file_size' => (int) $actual_size,
        );
    }

    public function read(string $storage_filename): string {
        $path = $this->resolve_path($storage_filename);
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException(__('보호 학습자료 원본을 읽지 못했습니다.', 'learning-materials'));
        }

        return $contents;
    }

    /** @return resource */
    public function open(string $storage_filename) {
        $handle = @fopen($this->resolve_path($storage_filename), 'rb');
        if ($handle === false) throw new RuntimeException(__('학습자료 원본을 읽지 못했습니다.', 'learning-materials'));
        return $handle;
    }

    public function info(string $storage_filename): array {
        $path = $this->resolve_path($storage_filename);
        $handle = $this->open($storage_filename);
        $stat = fstat($handle);
        fclose($handle);
        return array('size' => (int) ($stat['size'] ?? 0), 'mime' => $this->mime($path));
    }

    public function delete(string $storage_filename): void {
        try {
            $path = $this->resolve_path($storage_filename);
        } catch (RuntimeException $error) {
            return;
        }
        @unlink($path);
    }

    private function resolve_path(string $storage_filename): string {
        $root = realpath($this->ensure_root());
        $path = realpath($this->ensure_root() . DIRECTORY_SEPARATOR . $storage_filename);

        if (
            false === $root
            || false === $path
            || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            || ! is_file($path)
        ) {
            throw new RuntimeException(__('보호 학습자료 원본을 찾을 수 없습니다.', 'learning-materials'));
        }

        return $path;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{0:string,1:string,2:string}
     */
    private function inspect(array $file): array {
        if (UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            throw new DomainException(__('업로드가 완료된 파일만 등록할 수 있습니다.', 'learning-materials'));
        }
        if ((int) $file['size'] > self::MAX_FILE_BYTES) {
            throw new DomainException(__('학습자료 파일 크기 제한(50MB)을 초과했습니다.', 'learning-materials'));
        }

        $name = basename(str_replace('\\', '/', trim((string) $file['name'])));
        if (
            '' === $name
            || strlen($name) > 255
            || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new DomainException(__('학습자료 파일 이름이 올바르지 않습니다.', 'learning-materials'));
        }

        $parts = array_map('strtolower', explode('.', $name));
        if (count($parts) < 2) {
            throw new DomainException(__('학습자료 파일 확장자를 확인할 수 없습니다.', 'learning-materials'));
        }
        $extension = (string) end($parts);
        foreach (array_slice($parts, 0, -1) as $segment) {
            if (in_array($segment, self::BANNED_SEGMENTS, true)) {
                throw new DomainException(__('이중 확장자 실행 파일은 등록할 수 없습니다.', 'learning-materials'));
            }
        }
        if (! isset(self::MIME_TYPES[$extension])) {
            throw new DomainException(__('PDF·PPT·Word·Excel·TXT·CSV·이미지 파일만 등록할 수 있습니다.', 'learning-materials'));
        }

        $mime = $this->mime($file['tmp_name']);
        if (! in_array($mime, self::MIME_TYPES[$extension], true)) {
            throw new DomainException(__('파일 확장자와 실제 형식이 일치하지 않습니다.', 'learning-materials'));
        }

        return array($name, $extension, $mime);
    }

    private function mime(string $path): string {
        if (! class_exists(\finfo::class)) {
            throw new RuntimeException(__('학습자료 MIME 검사를 사용할 수 없습니다.', 'learning-materials'));
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : '';
    }

    private function ensure_root(): string {
        $root = rtrim($this->root(), '\\/');
        if (
            ('' === $root || (! is_dir($root) && ! mkdir($root, 0750, true)))
            || ! is_writable($root)
        ) {
            throw new RuntimeException(__('보호 학습자료 저장소를 준비하지 못했습니다.', 'learning-materials'));
        }
        $this->guard_file($root . DIRECTORY_SEPARATOR . 'index.php', "<?php\nhttp_response_code(404);\n");
        $this->guard_file(
            $root . DIRECTORY_SEPARATOR . '.htaccess',
            "Require all denied\nDeny from all\n"
        );
        $this->guard_file(
            $root . DIRECTORY_SEPARATOR . 'web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration><system.webServer><security><authorization>"
            . "<remove users=\"*\" roles=\"\" verbs=\"\"/>"
            . "<add accessType=\"Deny\" users=\"*\"/>"
            . "</authorization></security></system.webServer></configuration>\n"
        );

        return $root;
    }

    private function root(): string {
        return rtrim((string) WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . 'learning-materials-private';
    }

    private function guard_file(string $path, string $contents): void {
        if (is_file($path)) {
            return;
        }
        $written = file_put_contents($path, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            @unlink($path);
            throw new RuntimeException(__('보호 학습자료 저장소 접근 차단 파일을 만들지 못했습니다.', 'learning-materials'));
        }
        @chmod($path, 0640);
    }
}
