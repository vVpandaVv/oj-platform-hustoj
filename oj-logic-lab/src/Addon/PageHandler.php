<?php
declare(strict_types=1);
namespace OjLogicLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
final class PageHandler implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $key = htmlspecialchars(hash('sha256', home_url('/') . ':' . get_current_user_id()), ENT_QUOTES, 'UTF-8');
        return AddonPageResponseV1::page('논리회로 실습', '<div class="oj-logic-lab" data-logic-lab data-storage-key="' . $key . '"><p role="status">논리회로 실습을 준비하고 있습니다.</p><noscript>이 실습에는 JavaScript가 필요합니다.</noscript></div>', 'immersive', false);
    }
}
