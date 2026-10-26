<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretRecordStore;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SecretKeyRing;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SecretRotationSummary;
use Throwable;

/**
 * Re-encrypts every stored secret to the key ring's current primary key,
 * keeping the previous key material in the ring so records still on an
 * older key can be decrypted during the transition. A record that cannot be
 * re-encrypted (e.g. its key_id was already removed from the ring) is left
 * untouched with its existing ciphertext rather than discarded.
 */
final class SecretKeyRotationService
{
    private readonly AesGcmPayloadCipher $cipher;

    public function __construct(
        private readonly SecretRecordStore $records,
        private readonly SecretKeyRing $keys
    ) {
        $this->cipher = new AesGcmPayloadCipher($this->keys);
    }

    public function rotate(): SecretRotationSummary
    {
        $primaryKeyId = $this->keys->primaryKeyId();
        $rotated = 0;
        $alreadyCurrent = 0;
        $failed = [];

        foreach ($this->records->recordIds() as $recordId) {
            $record = $this->records->find($recordId);
            if ($record === null) {
                continue;
            }
            if ((string) ($record['key_id'] ?? '') === $primaryKeyId) {
                ++$alreadyCurrent;
                continue;
            }

            $reference = 'secret:v2:' . $recordId;
            try {
                $plaintext = $this->cipher->decrypt($record, $reference);
                $reEncrypted = $this->cipher->encrypt($plaintext, $reference);
                $reEncrypted['created_at'] = $record['created_at'] ?? gmdate('c');
                $this->records->replace($recordId, $reEncrypted);
                ++$rotated;
            } catch (Throwable) {
                $failed[] = $recordId;
            }
        }

        return new SecretRotationSummary($rotated, $alreadyCurrent, $failed);
    }
}
