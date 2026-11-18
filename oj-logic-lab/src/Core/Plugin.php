<?php
declare(strict_types=1);
namespace OjLogicLab\Core;
use OjLogicLab\Addon\Registration;
final class Plugin
{
    public static function boot(): void { Registration::register(); }
    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide || PHP_VERSION_ID < 80300 || !defined('OJ_PLATFORM_VERSION')
            || version_compare(OJ_PLATFORM_VERSION, '2.5.9', '<')
            || !defined('OJ_PLATFORM_ADDON_API_VERSION') || version_compare(OJ_PLATFORM_ADDON_API_VERSION, '1.9.0', '<')) {
            wp_die('OJ Logic Lab은 PHP 8.3, OJ Platform 2.5.9, Addon API 1.9 이상에서 사이트별로 활성화해 주세요.');
        }
    }
}
