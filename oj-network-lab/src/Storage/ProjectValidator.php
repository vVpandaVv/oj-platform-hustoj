<?php
declare(strict_types=1);
namespace OjNetworkLab\Storage;
final class ProjectValidator
{
    public static function validate(mixed $value): array
    {
        if (!is_array($value) || !in_array($value['schema'] ?? null, [1, 2], true) || ($value['engine'] ?? null) !== OJ_NETWORK_LAB_VERSION
            || !is_array($value['nodes'] ?? null) || !array_is_list($value['nodes']) || count($value['nodes']) > 20
            || !is_array($value['links'] ?? null) || !array_is_list($value['links']) || count($value['links']) > 40
            || !is_string($value['title'] ?? null) || preg_match('/\A.{0,80}\z/us', $value['title']) !== 1
            || !is_int($value['seed'] ?? null) || $value['seed'] < 1 || $value['seed'] > 2147483647) { throw new \InvalidArgumentException('프로젝트 형식·버전·장비 한도를 확인하세요.'); }
        self::tree($value);
        $ids = []; $ports = [];
        foreach ($value['nodes'] as $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,23}$/D', $node['id'])
                || isset($ids[$node['id']]) || !in_array($node['type'] ?? null, ['pc', 'server', 'hub', 'switch', 'router', 'firewall'], true)
                || !is_array($node['interfaces'] ?? null) || !array_is_list($node['interfaces']) || count($node['interfaces']) > 10
                || !is_array($node['routes'] ?? null) || count($node['routes']) > 64 || !is_array($node['rules'] ?? null) || count($node['rules']) > 64) { throw new \InvalidArgumentException('장비 형식·중복·개수를 확인하세요.'); }
            $ids[$node['id']] = true;
            $profile = $node['profile'] ?? 'linux';
            if (!in_array($profile, ['linux', 'windows', 'ios', 'fortios'], true)
                || ($profile === 'windows' && !in_array($node['type'], ['pc', 'server'], true))
                || ($profile === 'ios' && !in_array($node['type'], ['router', 'switch'], true))
                || ($profile === 'fortios' && $node['type'] !== 'firewall')) { throw new \InvalidArgumentException('장비 CLI 프로필을 확인하세요.'); }
            if ($profile === 'windows') { WindowsValidator::validate($node); }
            elseif (isset($node['windows'])) { throw new \InvalidArgumentException('Windows 설정은 Windows 프로필에서만 사용할 수 있습니다.'); }
            if (in_array($profile, ['ios', 'fortios'], true)) {
                self::device($node['device'] ?? null);
                $startup = $node['startup'] ?? null;
                if (!is_array($startup) || isset($startup['startup']) || isset($startup['profile']) || isset($startup['id'])
                    || !is_array($startup['interfaces'] ?? null) || count($startup['interfaces']) > 10
                    || !is_array($startup['routes'] ?? null) || count($startup['routes']) > 64) { throw new \InvalidArgumentException('장비 시작 설정을 확인하세요.'); }
                self::device($startup['device'] ?? null);
            }
            foreach ($node['interfaces'] as $port) {
                if (!is_array($port) || !is_string($port['name'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,23}$/D', $port['name'])) { throw new \InvalidArgumentException('인터페이스 형식을 확인하세요.'); }
                $key = $node['id'] . '/' . $port['name']; if (isset($ports[$key])) { throw new \InvalidArgumentException('중복 인터페이스입니다.'); } $ports[$key] = true;
            }
            RoutingValidator::validate($node, $profile, $node['type']);
            if (isset($node['startup'])) { RoutingValidator::validate($node['startup'], $profile, $node['type']); }
        }
        $occupied = []; $links = [];
        foreach ($value['links'] as $link) {
            if (!is_array($link) || !is_string($link['id'] ?? null) || isset($links[$link['id']])) { throw new \InvalidArgumentException('링크 형식을 확인하세요.'); } $links[$link['id']] = true;
            foreach (['a', 'b'] as $side) { $end = $link[$side] ?? null; if (!is_array($end) || !is_string($end['node'] ?? null) || !is_string($end['port'] ?? null)) { throw new \InvalidArgumentException('링크 끝을 확인하세요.'); } $key = $end['node'] . '/' . $end['port']; if (!isset($ports[$key]) || isset($occupied[$key])) { throw new \InvalidArgumentException('연결 포트가 없거나 중복입니다.'); } $occupied[$key] = true; }
        }
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 250000) { throw new \InvalidArgumentException('프로젝트가 저장 크기를 초과했습니다.'); }
        return $value;
    }
    private static function tree(mixed $value, int $depth = 0): void
    {
        if ($depth > 16) { throw new \InvalidArgumentException('프로젝트 중첩 한도를 초과했습니다.'); }
        if (is_array($value)) { if (count($value) > 200) { throw new \InvalidArgumentException('설정 목록이 너무 큽니다.'); } foreach ($value as $key => $item) { if (in_array($key, ['__proto__', 'prototype', 'constructor'], true)) { throw new \InvalidArgumentException('허용하지 않는 설정 키입니다.'); } self::tree($item, $depth + 1); } }
        elseif (is_string($value)) { if (strlen($value) > 8000 || preg_match('//u', $value) !== 1) { throw new \InvalidArgumentException('문자열 길이·인코딩을 확인하세요.'); } }
        elseif (!is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) { throw new \InvalidArgumentException('JSON 데이터만 저장할 수 있습니다.'); }
    }
    private static function device(mixed $device): void
    {
        if (!is_array($device)) { throw new \InvalidArgumentException('장비 설정이 필요합니다.'); }
        foreach (['vlans' => 64, 'acls' => 16, 'bindings' => 32, 'inside' => 10, 'outside' => 10, 'staticNat' => 16, 'addresses' => 32, 'services' => 32, 'policies' => 64, 'phase1' => 4, 'phase2' => 8] as $key => $limit) {
            if (!is_array($device[$key] ?? null) || !array_is_list($device[$key]) || count($device[$key]) > $limit) { throw new \InvalidArgumentException('장비 설정 개수 한도를 확인하세요.'); }
        }
        foreach ($device['acls'] as $acl) { if (!is_array($acl) || !is_array($acl['entries'] ?? null) || count($acl['entries']) > 64) { throw new \InvalidArgumentException('ACL 규칙 한도를 확인하세요.'); } }
        if (!in_array($device['saveMode'] ?? null, ['automatic', 'manual'], true)) { throw new \InvalidArgumentException('장비 저장 방식을 확인하세요.'); }
    }
}
