<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\PayloadCipher;
use JsonException;
use RuntimeException;

final class EncryptedFileFormDraftStore implements FormDraftStore
{
    private const MAX_RECORD_BYTES = 67108864;

    public function __construct(
        private readonly PrivateDataRootProvider $roots,
        private readonly PayloadCipher $cipher
    ) {
    }

    public function store(
        string $draftType,
        int $ownerUserId,
        string $targetType,
        string $targetKey,
        array $payload,
        int $ttlSeconds = 86400
    ): FormDraftReference {
        if ($ttlSeconds < 60 || $ttlSeconds > 86400) {
            throw new RuntimeException('Form Draft 보존 시간은 1분부터 24시간까지 허용합니다.');
        }
        $createdAt = time();
        $reference = new FormDraftReference('draft:v1:' . $this->uuidV4());
        $draft = new FormDraft(
            $reference,
            $draftType,
            $ownerUserId,
            $targetType,
            $targetKey,
            $payload,
            $createdAt,
            $createdAt + $ttlSeconds
        );
        $encrypted = $this->cipher->encrypt(
            $this->draftArray($draft),
            $reference->value()
        );
        $record = [
            'format' => 1,
            'expires_at' => $draft->expiresAt(),
            'encrypted' => $encrypted,
        ];
        try {
            $encoded = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Form Draft를 직렬화하지 못했습니다.', 0, $error);
        }
        if (strlen($encoded) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('암호화된 Form Draft가 64MB를 초과했습니다.');
        }
        $path = $this->path($reference);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
            @unlink($temporary);
            throw new RuntimeException('Form Draft 임시 파일을 기록하지 못했습니다.');
        }
        @chmod($temporary, 0640);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Form Draft 파일을 완료하지 못했습니다.');
        }

        return $reference;
    }

    public function find(
        FormDraftReference $reference,
        int $ownerUserId
    ): ?FormDraft {
        if ($ownerUserId < 1) {
            return null;
        }
        $path = $this->path($reference);
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $encoded = file_get_contents($path);
        if (
            ! is_string($encoded)
            || $encoded === ''
            || strlen($encoded) > self::MAX_RECORD_BYTES
        ) {
            throw new RuntimeException('Form Draft 파일을 읽지 못했습니다.');
        }
        try {
            $record = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Form Draft 파일 형식이 올바르지 않습니다.', 0, $error);
        }
        if (
            ! is_array($record)
            || (int) ($record['format'] ?? 0) !== 1
            || ! is_array($record['encrypted'] ?? null)
        ) {
            throw new RuntimeException('Form Draft 파일 형식이 올바르지 않습니다.');
        }
        if ((int) ($record['expires_at'] ?? 0) <= time()) {
            @unlink($path);

            return null;
        }
        $value = $this->cipher->decrypt(
            $record['encrypted'],
            $reference->value()
        );
        if (! is_array($value)) {
            throw new RuntimeException('Form Draft 내용이 올바르지 않습니다.');
        }
        $draft = $this->hydrate($reference, $value);

        return $draft->ownerUserId() === $ownerUserId ? $draft : null;
    }

    public function discard(
        FormDraftReference $reference,
        int $ownerUserId
    ): void {
        if ($this->find($reference, $ownerUserId) === null) {
            return;
        }
        $path = $this->path($reference);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Form Draft를 폐기하지 못했습니다.');
        }
    }

    public function purgeExpired(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Form Draft 정리 수량이 올바르지 않습니다.');
        }
        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.draft');
        if (! is_array($files)) {
            return 0;
        }
        sort($files, SORT_STRING);
        $removed = 0;
        foreach ($files as $path) {
            if ($removed >= $limit) {
                break;
            }
            if (! is_file($path) || is_link($path)) {
                continue;
            }
            $encoded = file_get_contents($path);
            $record = is_string($encoded) ? json_decode($encoded, true) : null;
            if (
                ! is_array($record)
                || (int) ($record['expires_at'] ?? 0) > time()
            ) {
                continue;
            }
            if (unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftArray(FormDraft $draft): array
    {
        return [
            'draft_type' => $draft->draftType(),
            'owner_user_id' => $draft->ownerUserId(),
            'target_type' => $draft->targetType(),
            'target_key' => $draft->targetKey(),
            'payload' => $draft->payload(),
            'created_at' => $draft->createdAt(),
            'expires_at' => $draft->expiresAt(),
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function hydrate(
        FormDraftReference $reference,
        array $value
    ): FormDraft {
        return new FormDraft(
            $reference,
            is_string($value['draft_type'] ?? null) ? $value['draft_type'] : '',
            is_int($value['owner_user_id'] ?? null) ? $value['owner_user_id'] : 0,
            is_string($value['target_type'] ?? null) ? $value['target_type'] : '',
            is_string($value['target_key'] ?? null) ? $value['target_key'] : '',
            is_array($value['payload'] ?? null) ? $value['payload'] : [],
            is_int($value['created_at'] ?? null) ? $value['created_at'] : 0,
            is_int($value['expires_at'] ?? null) ? $value['expires_at'] : 0
        );
    }

    private function path(FormDraftReference $reference): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR
            . $reference->recordId() . '.draft';
    }

    private function directory(): string
    {
        $root = rtrim($this->roots->root(), '/\\');
        if ($root === '' || is_link($root)) {
            throw new RuntimeException('비공개 작업 데이터 경로가 올바르지 않습니다.');
        }
        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            throw new RuntimeException('비공개 작업 데이터 경로를 만들지 못했습니다.');
        }
        $directory = $root . DIRECTORY_SEPARATOR . 'form-drafts';
        if (
            is_link($directory)
            || (
                ! is_dir($directory)
                && ! mkdir($directory, 0750)
                && ! is_dir($directory)
            )
        ) {
            throw new RuntimeException('Form Draft 경로를 만들지 못했습니다.');
        }
        $resolvedRoot = realpath($root);
        $resolvedDirectory = realpath($directory);
        if (
            ! is_string($resolvedRoot)
            || ! is_string($resolvedDirectory)
            || ! str_starts_with(
                strtolower($resolvedDirectory . DIRECTORY_SEPARATOR),
                strtolower(rtrim($resolvedRoot, '/\\') . DIRECTORY_SEPARATOR)
            )
        ) {
            throw new RuntimeException('Form Draft 경로가 비공개 루트를 벗어났습니다.');
        }
        $this->writeProtectionFile(
            $root . DIRECTORY_SEPARATOR . '.htaccess',
            "Deny from all\n"
        );
        $this->writeProtectionFile(
            $root . DIRECTORY_SEPARATOR . 'index.php',
            "<?php\nhttp_response_code(404);\n"
        );

        return $resolvedDirectory;
    }

    private function writeProtectionFile(string $path, string $contents): void
    {
        if (is_file($path)) {
            return;
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('비공개 작업 경로 보호 파일을 만들지 못했습니다.');
        }
        @chmod($path, 0640);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }
}
