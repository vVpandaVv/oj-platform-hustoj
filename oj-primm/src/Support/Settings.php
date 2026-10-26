<?php
declare(strict_types=1);
namespace OjPrimm\Support;

final class Settings
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerOptions']);
        add_action('updated_option', [self::class, 'updated'], 10, 3);
        add_action('added_option', [self::class, 'added'], 10, 2);
    }
    public static function menu(): void
    {
        add_options_page('OJ PRIMM 설정', 'OJ PRIMM', 'manage_options', 'oj-primm', [self::class, 'render']);
    }
    public static function registerOptions(): void
    {
        register_setting('oj_primm', 'oj_primm_retention_days', ['type' => 'integer', 'default' => 0, 'sanitize_callback' => [self::class, 'sanitizeRetention']]);
        register_setting('oj_primm', 'oj_primm_delete_on_uninstall', ['type' => 'boolean', 'default' => false, 'sanitize_callback' => [self::class, 'sanitizeDeletion']]);
        register_setting('oj_primm', GenerationPolicy::OPTION, ['type' => 'array', 'default' => GenerationPolicy::defaults(), 'sanitize_callback' => [GenerationPolicy::class, 'sanitize']]);
    }
    public static function sanitizeRetention(mixed $value): int { return max(0, min(3650, is_scalar($value) ? (int) $value : 0)); }
    public static function sanitizeDeletion(mixed $value): bool { return in_array($value, [true, 1, '1'], true); }
    public static function retentionDays(): int
    {
        return max(0, min(3650, (int) get_option('oj_primm_retention_days', 0)));
    }
    public static function deleteOnUninstall(): bool { return in_array(get_option('oj_primm_delete_on_uninstall', false), [true, 1, '1'], true); }
    public static function render(): void
    {
        if (! current_user_can('manage_options')) { wp_die('PRIMM 설정을 변경할 권한이 없습니다.'); }
        echo '<div class="wrap"><h1>OJ PRIMM 설정</h1><form method="post" action="options.php">';
        settings_fields('oj_primm');
        echo '<table class="form-table"><tr><th scope="row"><label for="oj-primm-retention">개인 기록 보관 기간</label></th><td>'
            . '<input id="oj-primm-retention" type="number" min="0" max="3650" name="oj_primm_retention_days" value="' . self::retentionDays() . '"> 일'
            . '<p class="description">기본값 0은 기간 제한 없이 보존합니다. 1~3650일을 지정하면 오래된 학생 응답·초안·진행 이력과 완료·실패한 생성 작업 이력을 매일 정리합니다. 활동과 생성된 수업 자료는 유지합니다.</p></td></tr>'
            . '<tr><th scope="row">플러그인 삭제 시</th><td><input type="hidden" name="oj_primm_delete_on_uninstall" value="0">'
            . '<label><input type="checkbox" name="oj_primm_delete_on_uninstall" value="1"' . checked(self::deleteOnUninstall(), true, false)
            . '> 플러그인을 완전히 삭제할 때 PRIMM 활동·학습 기록·설정도 삭제</label>'
            . '<p class="description">기본값은 기록 보존입니다. 이 옵션을 켠 뒤 WordPress에서 플러그인을 삭제하면 복구할 수 없으므로 먼저 백업하세요. 비활성화만으로는 기록을 삭제하지 않습니다.</p></td></tr></table>';
        $policy = GenerationPolicy::get();
        echo '<h2>생성 작업 한도</h2><table class="form-table">';
        foreach (['teacher_active_limit' => '교사별 활성 작업', 'site_active_limit' => '사이트 활성 작업', 'teacher_daily_ai_limit' => '교사별 일일 AI 호출',
            'site_daily_ai_limit' => '사이트 일일 AI 호출', 'job_ai_limit' => '작업별 AI 호출', 'transient_retry_limit' => '일시 오류 자동 재시도'] as $key => $label) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td><input type="number" min="0" max="10000" name="' . GenerationPolicy::OPTION . '[' . esc_attr($key) . ']" value="' . (int) $policy[$key] . '"></td></tr>';
        }
        echo '<tr><th scope="row">사이트 생성 중지</th><td><input type="hidden" name="' . GenerationPolicy::OPTION . '[paused]" value="0"><label><input type="checkbox" name="' . GenerationPolicy::OPTION . '[paused]" value="1"'
            . checked($policy['paused'], true, false) . '> 새 작업과 진행 중인 작업의 다음 단계를 일시 중지</label></td></tr></table>';
        submit_button('설정 저장');
        echo '</form></div>';
    }
    public static function updated(string $option, mixed $old, mixed $new): void
    {
        if (in_array($option, ['oj_primm_retention_days', 'oj_primm_delete_on_uninstall', GenerationPolicy::OPTION], true) && $old !== $new) {
            do_action('oj_primm_settings_changed', get_current_user_id(), $option, $old, $new);
        }
    }
    public static function added(string $option, mixed $new): void { self::updated($option, null, $new); }
}
