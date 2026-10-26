<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

final class LegacyJudgePasswordVerifier
{
    public function verify(string $plainPassword, string $storedPassword): bool
    {
        $storedPassword = trim($storedPassword);
        if ($plainPassword === '' || $storedPassword === '') {
            return false;
        }

        if ($this->isMd5Hash($storedPassword)) {
            $candidate = $this->isMd5Hash($plainPassword)
                ? strtolower($plainPassword)
                : md5($plainPassword);

            return hash_equals(strtolower($storedPassword), $candidate);
        }

        $decoded = base64_decode($storedPassword, true);
        if (! is_string($decoded) || strlen($decoded) < 21) {
            return false;
        }

        $salt = substr($decoded, 20);
        $md5Password = $this->isMd5Hash($plainPassword)
            ? strtolower($plainPassword)
            : md5($plainPassword);
        $candidate = base64_encode(
            sha1($md5Password . $salt, true) . $salt
        );

        return hash_equals($storedPassword, $candidate);
    }

    private function isMd5Hash(string $value): bool
    {
        return strlen($value) === 32 && ctype_xdigit($value);
    }
}
