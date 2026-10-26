<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Platform;

/** Resolves public notice links without reading another plugin's options or DB. */
final class Privacy_Url
{
    /** @return array{url:string,source:string} */
    public static function resolve(mixed $configured = '', bool $platformReady = false): array
    {
        $fallback = OJ_ML_PLAYGROUNDS_URL . 'resources/playground/legal/privacy.ko.html';
        $resolved = ['url' => $fallback, 'source' => 'bundled'];

        // OJ Platform's public legal.privacy route owns document selection and display.
        if ($platformReady && function_exists('home_url')) {
            $platformUrl = self::validUrl(home_url('/privacy'));
            if ($platformUrl !== '') {
                $resolved = ['url' => $platformUrl, 'source' => 'platform'];
            }
        }
        $customUrl = self::validUrl($configured);
        if ($customUrl !== '') {
            $resolved = ['url' => $customUrl, 'source' => 'custom'];
        }
        if (function_exists('apply_filters')) {
            $filtered = self::validUrl(apply_filters('oj_ml_playgrounds_privacy_url', $resolved['url']));
            if ($filtered !== '' && $filtered !== $resolved['url']) {
                $resolved = ['url' => $filtered, 'source' => 'filter'];
            }
        }
        return $resolved;
    }

    private static function validUrl(mixed $value): string
    {
        if (!is_string($value)) { return ''; }
        $url = trim($value);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) { return ''; }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        return function_exists('esc_url_raw') ? (string) esc_url_raw($url, ['http', 'https']) : $url;
    }
}
