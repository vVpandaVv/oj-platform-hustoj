<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Network;

use CodingPanda\OjPlatform\Modules\Settings\Contract\HostAddressResolver;

final class DnsHostAddressResolver implements HostAddressResolver
{
    public function resolve(string $host): array
    {
        $host = trim($host, '[] ');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = is_string($record['ip'] ?? null)
                        ? $record['ip']
                        : (is_string($record['ipv6'] ?? null)
                            ? $record['ipv6']
                            : '');
                    if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[] = $address;
                    }
                }
            }
        }
        if ($addresses === [] && function_exists('gethostbynamel')) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                foreach ($ipv4 as $address) {
                    if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[] = $address;
                    }
                }
            }
        }
        $addresses = array_values(array_unique($addresses));
        sort($addresses);

        return $addresses;
    }
}
