<?php
declare(strict_types=1);

namespace TeachingTools\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

/**
 * Shared pieces of the D-010 platform look: page responses (layout and dark
 * mode on Addon API 1.3, plain html() before it), the tool page header, and
 * the inline line icons that replaced the emoji.
 */
final class Ui {
    /**
     * Lucide-style 24px line icons (stroke="currentColor"), inner markup only.
     */
    private const ICONS = array(
        'timer' => '<line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/>',
        'seating' => '<path d="M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3"/><path d="M3 16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v1.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V11a2 2 0 0 0-4 0z"/><path d="M5 18v2"/><path d="M19 18v2"/>',
        'picker' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M16 8h.01"/><path d="M8 8h.01"/><path d="M8 16h.01"/><path d="M16 16h.01"/><path d="M12 12h.01"/>',
        'ladder' => '<path d="M6 3v18"/><path d="M12 3v18"/><path d="M18 3v18"/><path d="M6 8h6"/><path d="M12 13h6"/><path d="M6 18h6"/>',
        'whiteboard' => '<path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/>',
        'quiz' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 16h8"/><path d="M7 11h12"/><path d="M7 6h3"/>',
        'grouping' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'timeattack' => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
        'signal' => '<path d="M12 2a7 7 0 0 0-7 7c0 4.5-2 6-2 6h18s-2-1.5-2-6a7 7 0 0 0-7-7"/><path d="M10 21h4"/><path d="M8 9h8"/><path d="M12 5v8"/>',
        'quickboard' => '<rect width="18" height="14" x="3" y="4" rx="2"/><path d="M7 8h.01"/><path d="M11 8h6"/><path d="M7 12h4"/><path d="M15 12h2"/><path d="M8 22h8"/><path d="M12 18v4"/>',
        'operations' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'pen' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'eraser' => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/>',
        'line' => '<path d="M5 19 19 5"/>',
        'rect' => '<rect width="18" height="14" x="3" y="5" rx="1"/>',
        'circle' => '<circle cx="12" cy="12" r="9"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'text' => '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>',
        'redo' => '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'arrow-up' => '<path d="m18 15-6-6-6 6"/>',
        'arrow-down' => '<path d="m6 9 6 6 6-6"/>',
        'play' => '<path d="m6 3 14 9-14 9z"/>',
        'pause' => '<rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/>',
        'reset' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'volume' => '<path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18 5a9 9 0 0 1 0 14"/>',
        'fullscreen' => '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M16 3h3a2 2 0 0 1 2 2v3"/><path d="M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'print' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
    );

    /**
     * Wraps a page body. On Addon API 1.3 (OJ Platform 2.1+) the page picks
     * its layout and, when its CSS uses only --ojp-* tokens, the platform dark
     * mode. Older platforms get the plain light html() response.
     */
    public static function page(string $title, string $body, string $layout = 'standard', bool $dark = true): AddonPageResponseV1 {
        if (method_exists(AddonPageResponseV1::class, 'page')) {
            return AddonPageResponseV1::page($title, $body, $layout, $dark);
        }

        return AddonPageResponseV1::html($title, $body);
    }

    public static function icon(string $name, int $size = 20): string {
        $paths = self::ICONS[$name] ?? '';
        if ('' === $paths) {
            return '';
        }

        return '<svg class="htt-icon" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /**
     * Page header every tool page opens with: menu name, tool name, one line
     * of description, and the "사용법" button the tool scripts listen for.
     */
    public static function tool_header(string $title, string $description, bool $audience = true): string {
        return '<header class="ojp-page-header htt-page-header"><div>'
            . '<p class="ojp-eyebrow">' . esc_html__('교실 도구', 'teaching-tools') . '</p>'
            . '<h1>' . esc_html($title) . '</h1>'
            . '<p>' . esc_html($description) . '</p>'
            . '</div><div class="ojp-heading-actions">'
            . '<button type="button" class="ojp-button ojp-button--plain" data-action="show-usage">' . esc_html__('사용법', 'teaching-tools') . '</button>'
            . '</div></header>'
            . ($audience ? self::audience_control() : '');
    }

    private static function audience_control(): string {
        return '<div class="htt-audience" data-htt-audience>'
            . '<label><span>' . esc_html__('학생 연결', 'teaching-tools') . '</span>'
            . '<select data-field="audience-mode"><option value="code">'
            . esc_html__('참여 코드', 'teaching-tools') . '</option><option value="context">'
            . esc_html__('학급·그룹 자동 연결', 'teaching-tools') . '</option></select></label>'
            . '<label data-field="audience-target" hidden><span>'
            . esc_html__('자동 연결 대상', 'teaching-tools') . '</span>'
            . '<select data-field="audience-context"><option value="">'
            . esc_html__('학급·그룹 선택', 'teaching-tools') . '</option></select></label>'
            . '<div data-field="audience-open-option" hidden>' . self::auto_open_control() . '</div>'
            . '<button type="button" class="ojp-button ojp-button--plain" data-action="audience-refresh" hidden>'
            . esc_html__('목록 새로고침', 'teaching-tools') . '</button>'
            . '<span data-field="audience-status" role="status"></span></div>';
    }

    public static function auto_open_control(): string {
        return '<label class="htt-auto-open-choice"><input type="checkbox" data-field="audience-auto-open">'
            . '<span>' . esc_html__('활동 시작 시 학생 화면 자동 열기', 'teaching-tools') . '</span></label>';
    }

    /**
     * Shared roster label selector. The selected value changes only what is
     * rendered; the numeric platform learner id remains the stored identity.
     */
    public static function student_name_mode_control(): string {
        return '<label class="htt-student-name-mode">'
            . '<span>' . esc_html__('화면에 표시할 이름', 'teaching-tools') . '</span>'
            . '<select data-field="student-name-mode">'
            . '<option value="display">' . esc_html__('이름', 'teaching-tools') . '</option>'
            . '<option value="management">' . esc_html__('관리용 이름', 'teaching-tools') . '</option>'
            . '<option value="anonymous">' . esc_html__('익명 번호', 'teaching-tools') . '</option>'
            . '</select>'
            . '</label>';
    }
}
