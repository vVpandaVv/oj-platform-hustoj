<?php
declare(strict_types=1);
namespace OjNetworkLab\Storage;

final class RoutingValidator
{
    public static function validate(array $node, string $profile, string $type): void
    {
        $ports = [];
        foreach ($node['interfaces'] as $port) {
            $ports[$port['name']] = $port['kind'] ?? 'ethernet';
            if (isset($port['mtu'])) { self::integer($port['mtu'], 1280, 9000); }
            $addresses = self::items($port['ipv6'] ?? [], 8);
            if ($addresses !== []) { self::need($profile === 'linux' && in_array($type, ['pc', 'server', 'router'], true) && ($port['kind'] ?? '') === 'ethernet'); }
            foreach ($addresses as $address) { self::prefix($address, true); }
            self::unique($addresses);
        }
        if (isset($node['forwarding6'])) { self::need(is_bool($node['forwarding6']) && (!$node['forwarding6'] || $profile === 'linux')); }
        foreach (self::items($node['routes6'] ?? [], 64) as $route) {
            self::need(is_array($route) && $profile === 'linux' && ($ports[$route['dev'] ?? ''] ?? '') === 'ethernet');
            self::prefix($route['dest'] ?? null, true);
            self::need(is_string($route['via'] ?? null));
            if ($route['via'] !== '') { self::ip($route['via'], true); }
            self::integer($route['metric'] ?? null, 0, 65535);
        }
        foreach (['ospf', 'bgp'] as $kind) {
            $config = $node[$kind] ?? null;
            if ($config === null) { continue; }
            self::need(is_array($config) && $profile === 'ios' && $type === 'router');
            self::ip($config['routerId'] ?? null, false); self::need($config['routerId'] !== '0.0.0.0');
            if ($kind === 'ospf') {
                self::integer($config['process'] ?? null, 1, 65535); $keys = [];
                foreach (self::items($config['networks'] ?? null, 32) as $network) {
                    self::need(is_array($network)); self::prefix($network['prefix'] ?? null, false); self::integer($network['area'] ?? null, 0, 4294967295); $keys[] = $network['prefix'];
                }
                self::unique($keys); $keys = [];
                foreach (self::items($config['ports'] ?? null, 10) as $port) {
                    self::need(is_array($port) && ($ports[$port['dev'] ?? ''] ?? '') === 'ethernet' && is_bool($port['passive'] ?? null));
                    self::integer($port['cost'] ?? null, 1, 65535); $keys[] = $port['dev'];
                }
                self::unique($keys);
            } else {
                self::integer($config['asn'] ?? null, 1, 4294967294);
                $networks = self::items($config['networks'] ?? null, 32); foreach ($networks as $network) { self::prefix($network, false); } self::unique($networks);
                $keys = [];
                foreach (self::items($config['neighbors'] ?? null, 16) as $neighbor) {
                    self::need(is_array($neighbor) && is_bool($neighbor['shutdown'] ?? null)); self::ip($neighbor['ip'] ?? null, false);
                    self::integer($neighbor['asn'] ?? null, 1, 4294967294); self::integer($neighbor['weight'] ?? null, 0, 65535); $keys[] = $neighbor['ip'];
                }
                self::unique($keys);
            }
        }
    }
    private static function need(bool $ok): void { if (!$ok) { throw new \InvalidArgumentException('IPv6·OSPF·BGP 설정 형식과 지원 범위를 확인하세요.'); } }
    private static function integer(mixed $value, int $min, int $max): void { self::need(is_int($value) && $value >= $min && $value <= $max); }
    private static function items(mixed $value, int $max): array { self::need(is_array($value) && array_is_list($value) && count($value) <= $max); return $value; }
    private static function unique(array $items): void { self::need(count(array_unique($items)) === count($items)); }
    private static function ip(mixed $value, bool $six): void { self::need(is_string($value) && filter_var($value, FILTER_VALIDATE_IP, $six ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) !== false); }
    private static function prefix(mixed $value, bool $six): void
    {
        self::need(is_string($value)); $parts = explode('/', $value); self::need(count($parts) === 2 && preg_match('/^(0|[1-9][0-9]{0,2})$/D', $parts[1]) === 1);
        self::ip($parts[0], $six); self::integer((int) $parts[1], 0, $six ? 128 : 32);
    }
}
