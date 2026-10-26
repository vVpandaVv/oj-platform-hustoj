<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Whiteboard;

/**
 * Stores short-lived whiteboard images outside the media library. Files use
 * random names, are re-encoded by a WordPress image editor, and are served
 * only through the session-authorized download action.
 */
final class Whiteboard_Image_Repository {
    private const SUBDIR = 'teaching-tools-whiteboard';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_FILES_PER_SESSION = 30;
    private const MAX_BACKGROUNDS_PER_SESSION = 500;
    private const MAX_DIMENSION = 8192;
    private const MAX_PIXELS = 24_000_000;

    private const ALLOWED_TYPES = array(
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    );

    /**
     * @param array{tmp_name?:string,type?:string,size?:int,error?:int} $file one entry from
     *   WP_REST_Request::get_file_params()
     * @return array{path:string,filename:string,mime:string,width:int,height:int}|null
     */
    public static function save_upload(int $session_id, array $file, bool $background = false): ?array {
        $tmp_name = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ('' === $tmp_name || ! is_file($tmp_name) || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }

        $image = @getimagesize($tmp_name);
        $type = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        $width = is_array($image) ? (int) ($image[0] ?? 0) : 0;
        $height = is_array($image) ? (int) ($image[1] ?? 0) : 0;
        if (
            ! isset(self::ALLOWED_TYPES[$type])
            || $width < 1
            || $height < 1
            || $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS
        ) {
            return null;
        }

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected_type = (string) $finfo->file($tmp_name);
            if ($detected_type !== $type) {
                return null;
            }
        }

        $dir = self::session_dir($session_id);
        if (! wp_mkdir_p($dir)) {
            return null;
        }
        $files = array_values(array_filter(
            glob(trailingslashit($dir) . '*') ?: array(),
            static fn (string $path): bool => is_file($path)
                && (bool) preg_match('/\.(?:png|jpg|webp)(?:\.bin)?\z/i', $path)
        ));
        $files = array_filter($files, static fn (string $path): bool => str_starts_with(basename($path), 'bg-') === $background);
        if (count($files) >= ($background ? self::MAX_BACKGROUNDS_PER_SESSION : self::MAX_FILES_PER_SESSION)) {
            return null;
        }
        self::protect_directory($dir);

        $target_type = 'image/jpeg' === $type ? 'image/jpeg' : 'image/png';
        if ('image/webp' === $type && function_exists('imagewebp')) {
            $target_type = 'image/webp';
        }
        $extension = self::ALLOWED_TYPES[$target_type];
        $filename = ($background ? 'bg-' : '') . strtolower(wp_generate_password(32, false, false)) . '.' . $extension;
        $destination = trailingslashit($dir) . $filename;
        $editor = wp_get_image_editor($tmp_name);
        if (is_wp_error($editor)) {
            return null;
        }
        $editor->set_quality(88);
        // Backgrounds remain unreadable through static nginx URLs, independently of .htaccess support.
        $plain = $background ? trailingslashit(get_temp_dir()) . 'tt-bg-' . bin2hex(random_bytes(16)) . '.' . $extension : $destination;
        try {
            $saved = $editor->save($plain, $target_type);
            if (is_wp_error($saved) || ! is_file($plain)) { return null; }
            if ($background && ! self::seal_background($plain, $destination . '.bin', $session_id . ':' . $filename)) { return null; }
        } finally {
            if ($background && is_file($plain)) { unlink($plain); }
        }

        return array(
            'path' => $destination . ($background ? '.bin' : ''),
            'filename' => $filename,
            'mime' => (string) ($saved['mime-type'] ?? $target_type),
            'width' => (int) ($saved['width'] ?? $width),
            'height' => (int) ($saved['height'] ?? $height),
        );
    }

    /** @return array{path:string,mime:string,body?:string}|null */
    public static function find(int $session_id, string $filename): ?array {
        if (! preg_match('/\A(?:bg-)?[a-z0-9]{32}\.(?:png|jpg|webp)\z/', $filename)) {
            return null;
        }
        $background = str_starts_with($filename, 'bg-');
        $path = trailingslashit(self::session_dir($session_id)) . $filename . ($background ? '.bin' : '');
        if (! is_file($path)) {
            return null;
        }
        $type = wp_check_filetype($filename, array(
            'png' => 'image/png',
            'jpg|jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ));
        $mime = (string) ($type['type'] ?? '');

        if ('' === $mime) { return null; }
        if ($background) {
            $body = self::read_background($path, $session_id . ':' . $filename);
            return null === $body ? null : array('path' => $path, 'mime' => $mime, 'body' => $body);
        }
        return array('path' => $path, 'mime' => $mime);
    }

    private static function seal_background(string $source, string $destination, string $context): bool {
        if (! function_exists('openssl_encrypt') || filesize($source) > self::MAX_BYTES) { return false; }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string) file_get_contents($source), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if (false === $cipher) { return false; }
        $sealed = 'TTBG1' . $nonce . $tag . $cipher;
        $written = file_put_contents($destination, $sealed, LOCK_EX);
        if ($written !== strlen($sealed)) { if (is_file($destination)) { unlink($destination); } return false; }
        @chmod($destination, 0600);
        return true;
    }

    private static function read_background(string $path, string $context): ?string {
        if (! function_exists('openssl_decrypt') || filesize($path) > self::MAX_BYTES + 33) { return null; }
        $data = (string) file_get_contents($path);
        if (strlen($data) < 33 || substr($data, 0, 5) !== 'TTBG1') { return null; }
        $body = openssl_decrypt(substr($data, 33), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($data, 5, 12), substr($data, 17, 16), $context);
        return false === $body ? null : $body;
    }

    public static function delete_for_session(int $session_id): void {
        $dir = self::session_dir($session_id);
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_merge(glob(trailingslashit($dir) . '*') ?: array(), array(trailingslashit($dir) . '.htaccess')) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    private static function session_dir(int $session_id): string {
        $upload_dir = wp_upload_dir();

        return trailingslashit($upload_dir['basedir']) . self::SUBDIR . '/' . $session_id;
    }

    private static function protect_directory(string $dir): void {
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (! is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $web_config = trailingslashit($dir) . 'web.config';
        if (! is_file($web_config)) {
            @file_put_contents(
                $web_config,
                '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer>'
                    . '<authorization><deny users="*" /></authorization></system.webServer></configuration>'
            );
        }
        $index = trailingslashit($dir) . 'index.php';
        if (! is_file($index)) {
            @file_put_contents($index, "<?php\nhttp_response_code(404);\n");
        }
    }
}
