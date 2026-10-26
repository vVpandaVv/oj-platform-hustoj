<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Domain\Problem;

final class Options
{
    public const KEY = 'oj_ml_playgrounds_settings';
    public const DEFAULTS = [
        'maxArtifactBytes' => 26214400,
        'siteQuotaBytes' => 10737418240,
        'minimumFreeBytes' => 536870912,
        'inspectionSlots' => 2,
        'retentionDays' => 0,
        'retentionHold' => true,
        'maintenanceMode' => false,
        'allowPersonalErasure' => false,
        'privacyUrl' => '',
        'labFocusOnStart' => false,
        'labGuidanceCollapsed' => true,
    ];

    public static function all(): array
    {
        $stored = function_exists('get_option') ? get_option(self::KEY, []) : [];
        $settings = array_replace(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
        foreach (['labFocusOnStart', 'labGuidanceCollapsed'] as $key) {
            if (!is_bool($settings[$key])) { $settings[$key] = self::DEFAULTS[$key]; }
        }
        return $settings;
    }

    public static function save(array $input): array
    {
        $next = self::all();
        foreach (['labFocusOnStart', 'labGuidanceCollapsed'] as $key) {
            if (!array_key_exists($key, $input)) { continue; }
            if (!is_bool($input[$key])) {
                throw new Problem('OJ_ML_SETTING_INVALID', '실습 화면 설정은 켜기 또는 끄기로 지정하세요: ' . $key, 400);
            }
            $next[$key] = $input[$key];
        }
        foreach (['maxArtifactBytes' => [1048576, 262144000], 'siteQuotaBytes' => [104857600, 1099511627776],
            'minimumFreeBytes' => [104857600, 107374182400], 'inspectionSlots' => [1, 8], 'retentionDays' => [0, 3650]] as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) { continue; }
            $value = filter_var($input[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                throw new Problem('OJ_ML_SETTING_INVALID', '운영 설정의 허용 범위를 확인하세요: ' . $key, 400);
            }
            $next[$key] = $value;
        }
        if ($next['siteQuotaBytes'] < $next['maxArtifactBytes']) {
            throw new Problem('OJ_ML_SETTING_INVALID', '전체 저장 한도는 파일 한도보다 커야 합니다.', 400);
        }
        if (array_key_exists('maintenanceMode', $input)) {
            $next['maintenanceMode'] = $input['maintenanceMode'] === true;
        }
        if (array_key_exists('allowPersonalErasure', $input)) {
            $next['allowPersonalErasure'] = $input['allowPersonalErasure'] === true;
        }
        if (array_key_exists('retentionHold', $input)) {
            $next['retentionHold'] = $input['retentionHold'] === true;
        }
        if (array_key_exists('privacyUrl', $input)) {
            $url = is_string($input['privacyUrl']) ? trim($input['privacyUrl']) : '';
            if ($url !== '' && (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')) {
                throw new Problem('OJ_ML_SETTING_INVALID', '개인정보 안내에는 HTTPS 주소를 입력하세요.', 400);
            }
            $next['privacyUrl'] = esc_url_raw($url, ['https']);
        }
        update_option(self::KEY, $next, false);
        if (self::all() !== $next) {
            throw new Problem('OJ_ML_SETTINGS_SAVE_FAILED', '운영 설정을 저장하지 못했습니다.', 503);
        }
        return $next;
    }
}
