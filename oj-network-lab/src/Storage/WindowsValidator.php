<?php
declare(strict_types=1);
namespace OjNetworkLab\Storage;
final class WindowsValidator
{
    private static function need(bool $ok): void { if (!$ok) { throw new \InvalidArgumentException('Windows 호스트·방화벽 설정 형식과 한도를 확인하세요.'); } }
    private static function items(mixed $value, int $max, int $min = 0): array { self::need(is_array($value) && array_is_list($value) && count($value) >= $min && count($value) <= $max); return $value; }
    private static function name(mixed $value, int $max): string { self::need(is_string($value) && trim($value) !== '' && preg_match('/\A[^\r\n]{1,' . $max . '}\z/u', $value) === 1); return $value; }
    private static function ip(mixed $value): void { self::need(is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false); }
    private static function list(mixed $value, int $max, callable $check): array
    {
        $list = self::items($value, $max, 1); self::need(count(array_unique($list, SORT_REGULAR)) === count($list) && (count($list) === 1 || !in_array('Any', $list, true)));
        foreach ($list as $item) { $check($item); } return $list;
    }
    public static function validate(array $node): void
    {
        self::need(in_array($node['type'], ['pc', 'server'], true) && empty($node['forwarding']) && empty($node['tables']) && empty($node['chains']) && empty($node['rules']) && !isset($node['startup']) && !isset($node['device']));
        $ports = [];
        foreach ($node['interfaces'] as $port) { self::need(is_array($port) && ($port['kind'] ?? null) === 'ethernet' && is_string($port['name'] ?? null)); $ports[] = $port['name']; }
        $windows = $node['windows'] ?? null; self::need(is_array($windows));
        $adapters = self::items($windows['adapters'] ?? null, 10); self::need(count($adapters) === count($ports)); $seen = [];
        foreach ($adapters as $adapter) {
            self::need(is_array($adapter) && in_array($adapter['name'] ?? null, $ports, true) && !isset($seen[$adapter['name']]) && is_bool($adapter['dhcp'] ?? null) && is_bool($adapter['dnsAutomatic'] ?? null)
                && in_array($adapter['category'] ?? null, ['Public', 'Private'], true) && is_int($adapter['metric'] ?? null) && $adapter['metric'] >= 0 && $adapter['metric'] <= 65535);
            foreach (['dns', 'leaseDns'] as $key) { self::need(is_string($adapter[$key] ?? null)); if ($adapter[$key] !== '') { self::ip($adapter[$key]); } } $seen[$adapter['name']] = true;
        }
        $profiles = self::items($windows['profiles'] ?? null, 3, 3); $seen = [];
        foreach ($profiles as $profile) { self::need(is_array($profile) && in_array($profile['name'] ?? null, ['Domain', 'Private', 'Public'], true) && !isset($seen[$profile['name']]) && is_bool($profile['enabled'] ?? null) && in_array($profile['inbound'] ?? null, ['Allow', 'Block'], true) && in_array($profile['outbound'] ?? null, ['Allow', 'Block'], true)); $seen[$profile['name']] = true; }
        $seen = [];
        foreach (self::items($windows['rules'] ?? null, 64) as $rule) {
            self::need(is_array($rule)); $name = self::name($rule['name'] ?? null, 64); self::name($rule['displayName'] ?? null, 80);
            self::need(!isset($seen[strtolower($name)]) && is_bool($rule['enabled'] ?? null) && in_array($rule['direction'] ?? null, ['Inbound', 'Outbound'], true) && in_array($rule['action'] ?? null, ['Allow', 'Block'], true) && in_array($rule['protocol'] ?? null, ['Any', 'TCP', 'UDP', 'ICMPv4'], true)); $seen[strtolower($name)] = true;
            self::list($rule['profiles'] ?? null, 3, static function (mixed $s): void { self::need(in_array($s, ['Any', 'Domain', 'Private', 'Public'], true)); });
            foreach (['localAddress', 'remoteAddress'] as $key) { self::list($rule[$key] ?? null, 16, static function (mixed $s): void { self::need(is_string($s)); if (in_array($s, ['Any', 'LocalSubnet'], true)) { return; } $parts = explode('/', $s); self::need(count($parts) <= 2); self::ip($parts[0]); if (isset($parts[1])) { self::need(preg_match('/\A(?:[0-9]|[12][0-9]|3[0-2])\z/', $parts[1]) === 1); } }); }
            foreach (['localPort', 'remotePort'] as $key) { self::list($rule[$key] ?? null, 16, static function (mixed $s): void { self::need(is_string($s)); if ($s === 'Any') { return; } self::need(preg_match('/\A[0-9]+(?:-[0-9]+)?\z/', $s) === 1); $parts = array_map('intval', explode('-', $s)); self::need($parts[0] >= 1 && $parts[0] <= 65535 && ($parts[1] ?? $parts[0]) >= $parts[0] && ($parts[1] ?? $parts[0]) <= 65535); }); }
            self::list($rule['icmpType'] ?? null, 16, static function (mixed $s): void { self::need(is_string($s) && ($s === 'Any' || (ctype_digit($s) && (int) $s <= 255))); });
            self::list($rule['interfaces'] ?? null, 10, static function (mixed $s) use ($ports): void { self::need($s === 'Any' || in_array($s, $ports, true)); });
            if (!in_array($rule['protocol'], ['TCP', 'UDP'], true)) { self::need($rule['localPort'] === ['Any'] && $rule['remotePort'] === ['Any']); }
            if ($rule['protocol'] !== 'ICMPv4') { self::need($rule['icmpType'] === ['Any']); }
        }
    }
}
