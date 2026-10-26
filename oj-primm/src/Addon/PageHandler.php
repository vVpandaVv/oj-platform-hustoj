<?php

declare(strict_types=1);

namespace OjPrimm\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

final class PageHandler implements AddonPageHandlerV1
{
    public function __construct(private readonly string $mode) {}

    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $teacher = $this->mode === 'teacher';
        $title = $teacher ? 'PRIMM 수업 관리' : 'PRIMM 활동';
        $config = htmlspecialchars((string) wp_json_encode([
            'mode' => $this->mode, 'restUrl' => rest_url('oj-primm/v1/'),
            'nonce' => wp_create_nonce('wp_rest'), 'userId' => get_current_user_id(),
            'isAdmin' => current_user_can('manage_options'),
        ]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<div class="ojprimm" data-oj-primm data-config="' . $config . '">'
            . '<header class="ojp-page-header"><div><p class="ojp-eyebrow">학습 활동</p><h1>'
            . $title . '</h1><p>' . ($teacher
                ? '목표 문제를 고르면 AI가 PRIMM 자료를 만들고, 기존 OJ로 예제와 예상 출력을 검증해 준비합니다.'
                : '코드를 읽고 예상한 뒤, 결과를 비교하고 내 프로그램을 만들어 보세요.')
            . '</p></div></header><p class="ojprimm-status" data-primm-status role="status" aria-live="polite">'
            . '활동을 불러오고 있습니다.</p><div data-primm-content></div>'
            . '<noscript><p>PRIMM 활동을 진행하려면 브라우저에서 JavaScript를 켜 주세요.</p></noscript></div>';
        return AddonPageResponseV1::page($title, $body, 'wide', true);
    }
}
