<?php
declare(strict_types=1);
namespace OjPrimm\Application;

use DomainException;
use OjPrimm\Generation\LessonSchema;

final class ClassSettingsService
{
    public const OPTION = 'oj_primm_class_settings';
    public function __construct(private readonly PlatformAccess $platform, private readonly ?\Closure $reader = null, private readonly ?\Closure $writer = null) {}
    public static function defaults(): array
    {
        return ['default_language' => 'python', 'level' => 'beginner', 'prerequisites' => [], 'minutes' => 45,
            'support_kind' => 'none', 'auto_prepare' => false, 'auto_publish' => true];
    }
    public static function normalize(array $input): array
    {
        if (array_key_exists('language', $input)) { $input['default_language'] = $input['language']; unset($input['language']); }
        if (array_diff(array_keys($input), array_keys(self::defaults())) !== []) { throw new DomainException('허용되지 않은 학급 기본 설정입니다.'); }
        $value = array_replace(self::defaults(), $input);
        if (! in_array($value['default_language'], LessonSchema::LANGUAGES, true) || ! in_array($value['level'], ['beginner', 'intermediate', 'advanced'], true)
            || ! in_array($value['support_kind'], LessonSchema::KINDS, true) || ! is_int($value['minutes']) || $value['minutes'] < 15 || $value['minutes'] > 180
            || ! is_array($value['prerequisites']) || ! array_is_list($value['prerequisites']) || count($value['prerequisites']) > 20) { throw new DomainException('학급의 언어·수준·시간·선수 개념을 확인해 주세요.'); }
        foreach ($value['prerequisites'] as &$concept) { $concept = LessonSchema::text($concept, 200, '선수 개념'); } unset($concept);
        $value['prerequisites'] = array_values(array_unique($value['prerequisites']));
        foreach (['auto_prepare', 'auto_publish'] as $key) { if (! is_bool($value[$key])) { throw new DomainException('자동 준비·게시 여부는 참/거짓이어야 합니다.'); } }
        return $value;
    }
    public function read(string $type, string $id): array
    {
        $this->platform->assertRole('teach'); $this->platform->authorize($type, $id, 'assign');
        $all = $this->all();
        $value = self::normalize(is_array($all[$type . ':' . $id] ?? null) ? $all[$type . ':' . $id] : []);
        return $value + ['language' => $value['default_language']];
    }
    public function get(string $type, string $id): array { return $this->read($type, $id); }
    public function save(string $type, string $id, array $input): array
    {
        $this->platform->assertRole('teach'); $this->platform->authorize($type, $id, 'assign');
        $value = self::normalize($input); $all = $this->all();
        $all[$type . ':' . $id] = $value;
        if ($this->writer !== null) { ($this->writer)($all); }
        elseif (function_exists('update_option')) { update_option(self::OPTION, $all, false); }
        else { throw new DomainException('학급 설정을 저장할 수 없습니다.'); }
        if (function_exists('do_action')) { do_action('oj_primm_class_settings_changed', $this->platform->actorId(), $type, $id); }
        return $value + ['language' => $value['default_language']];
    }
    /** Public problem-save hooks still run as the current teacher and reauthorize every context. */
    public function automaticContexts(): array
    {
        $this->platform->assertRole('teach'); $result = [];
        foreach ($this->platform->contexts(true) as $context) {
            try {
                $settings = $this->read($context['type'], $context['id']);
                if ($settings['auto_prepare']) { $result[] = ['type' => $context['type'], 'id' => $context['id'], 'settings' => $settings]; }
            } catch (\Throwable) { continue; }
        }
        return $result;
    }
    private function all(): array
    {
        $value = $this->reader !== null ? ($this->reader)() : (function_exists('get_option') ? get_option(self::OPTION, []) : []);
        return is_array($value) ? $value : [];
    }
}
