<?php
declare(strict_types=1);
namespace OjNetworkLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
final class PageHandler implements AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $config = htmlspecialchars((string) wp_json_encode([
            'restUrl' => rest_url('oj-network-lab/v1/'), 'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(), 'siteKey' => substr(hash('sha256', home_url('/')), 0, 16),
            'assetUrl' => plugins_url('resources/js/', OJ_NETWORK_LAB_FILE), 'version' => OJ_NETWORK_LAB_VERSION,
        ]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return AddonPageResponseV1::page('네트워크 실습', '<div class="oj-network-lab" data-network-lab data-config="' . $config . '"><p role="status">네트워크 실습을 준비하고 있습니다.</p><noscript>이 실습에는 JavaScript가 필요합니다.</noscript></div>', 'wide', true);
    }
}
