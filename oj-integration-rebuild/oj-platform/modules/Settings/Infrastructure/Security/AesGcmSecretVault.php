<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretRecordStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SecretKeyRing;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use RuntimeException;

final class AesGcmSecretVault implements SecretVault
{
    private readonly AesGcmPayloadCipher $cipher;

    public function __construct(
        private readonly SecretRecordStore $records,
        private readonly SecretKeyRing $keys
    ) {
        $this->cipher = new AesGcmPayloadCipher($this->keys);
    }

    public function store(mixed $value): SecretReference
    {
        $recordId = $this->uuidV4();
        $reference = new SecretReference('secret:v2:' . $recordId);
        $record = $this->cipher->encrypt($value, $reference->value());
        $record['created_at'] = gmdate('c');
        $this->records->put($recordId, $record);

        return $reference;
    }

    public function reveal(SecretReference $reference): mixed
    {
        $record = $this->records->find($this->recordId($reference));
        if ($record === null) {
            throw new RuntimeException('비밀값 참조가 없거나 사용할 수 없습니다.');
        }

        return $this->cipher->decrypt($record, $reference->value());
    }

    public function discard(SecretReference $reference): void
    {
        $this->records->remove($this->recordId($reference));
    }

    private function recordId(SecretReference $reference): string
    {
        return substr($reference->value(), strlen('secret:v2:'));
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
