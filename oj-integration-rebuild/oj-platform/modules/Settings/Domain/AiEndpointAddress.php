<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

final class AiEndpointAddress
{
    public static function canonical(string $address): ?string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // IPv4-mapped IPv6 reaches the same IPv4 destination and must share
        // both its forbidden-network check and Judge address identity.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed) ?: null;
    }

    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    public static function canonicalList(array $addresses): array
    {
        $canonical = [];
        foreach ($addresses as $address) {
            $value = self::canonical($address);
            if ($value === null) {
                return [];
            }
            $canonical[] = $value;
        }
        $canonical = array_values(array_unique($canonical));
        sort($canonical);

        return $canonical;
    }

    public static function isForbidden(string $address): bool
    {
        $address = self::canonical($address);
        if ($address === null) {
            return true;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $unsigned = (int) sprintf('%u', ip2long($address));

            return $unsigned < 0x01000000
                || ($unsigned >= 0x7f000000 && $unsigned <= 0x7fffffff)
                || ($unsigned >= 0xa9fe0000 && $unsigned <= 0xa9feffff)
                || $unsigned >= 0xe0000000;
        }
        $hex = bin2hex((string) inet_pton($address));

        return $address === '::'
            || $address === '::1'
            || str_starts_with($hex, 'fe8')
            || str_starts_with($hex, 'fe9')
            || str_starts_with($hex, 'fea')
            || str_starts_with($hex, 'feb')
            || str_starts_with($hex, 'ff');
    }
}
