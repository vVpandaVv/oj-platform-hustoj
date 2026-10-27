<?php

declare(strict_types=1);

namespace OjAddonStarter\Page;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use OjAddonStarter\Core\Plugin;
use OjAddonStarter\Rest\NoteApi;
use OjAddonStarter\Storage\NoteRepository;

final class NotePage implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        if (! Plugin::can_use() || $request->actorUserId() !== get_current_user_id()) {
            return AddonPageResponseV1::html('개인 메모', '<p>이 화면을 열 권한이 없습니다.</p>', 403);
        }
        $config = wp_json_encode(
            ['endpoint' => rest_url(NoteApi::NAMESPACE . '/note'), 'nonce' => wp_create_nonce('wp_rest')],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (! is_string($config)) {
            return AddonPageResponseV1::unavailable('화면 설정을 준비하지 못했습니다.', 'addon_runtime_unavailable');
        }
        $html = '<section class="oj-addon-starter">'
            . '<header class="ojp-page-header"><div><h1>개인 메모</h1><p>로그인한 본인의 메모를 저장합니다.</p></div></header>'
            . '<form class="ojp-form-stack" data-starter-form>'
            . '<fieldset class="ojp-fieldset" data-starter-fields>'
            . '<div class="ojp-field"><label for="starter-note">메모</label>'
            . '<textarea class="ojp-textarea" id="starter-note" name="note" rows="8" aria-describedby="starter-help">'
            . esc_textarea(NoteRepository::read_for_current_user())
            . '</textarea><p class="ojp-field-help" id="starter-help">UTF-8 텍스트 4,000바이트까지 저장합니다. HTML 태그는 제거됩니다.</p></div>'
            . '<button class="ojp-button ojp-button--primary" type="submit">저장</button></fieldset>'
            . '<p class="oj-addon-starter__status" role="status" aria-live="polite" data-starter-status></p>'
            . '</form><script type="application/json" id="oj-addon-starter-config">' . $config . '</script></section>';
        return AddonPageResponseV1::page('개인 메모', $html, 'standard', true);
    }
}
