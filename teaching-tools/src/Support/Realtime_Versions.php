<?php
declare(strict_types=1);

namespace TeachingTools\Support;

final class Realtime_Versions {
    public static function bundled(): array {
        $root = TEACHING_TOOLS_PATH;
        $core = $root . 'common-server/package.json';
        if (! is_file($core)) {
            $core = dirname(rtrim($root, '/\\')) . '/_shared/oj-realtime-hub/package.json';
        }

        return array(
            'plugin' => TEACHING_TOOLS_VERSION,
            'module' => self::read_version($root . 'realtime-addon.json'),
            'core' => self::read_version($core),
        );
    }

    public static function report(string $module, string $core): array {
        $expected = self::bundled();
        $module_status = self::compare($module, $expected['module']);
        $core_status = self::compare($core, $expected['core']);

        return array(
            'plugin' => $expected['plugin'],
            'module' => array('installed' => $module, 'bundled' => $expected['module'], 'status' => $module_status),
            'core' => array('installed' => $core, 'bundled' => $expected['core'], 'status' => $core_status),
            'updateRequired' => in_array('outdated', array($module_status, $core_status), true),
            'verified' => ! in_array('unknown', array($module_status, $core_status), true),
        );
    }

    private static function compare(string $installed, string $expected): string {
        $pattern = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D';
        if (! preg_match($pattern, $installed) || ! preg_match($pattern, $expected)) {
            return 'unknown';
        }

        return version_compare($installed, $expected, '<') ? 'outdated'
            : (version_compare($installed, $expected, '>') ? 'newer' : 'current');
    }

    private static function read_version(string $path): string {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        $data = false !== $contents ? json_decode($contents, true) : null;
        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';
    }
}
