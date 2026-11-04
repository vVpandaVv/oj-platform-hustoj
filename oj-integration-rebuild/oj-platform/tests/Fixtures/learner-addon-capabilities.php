<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;

// The combined learner grants observed on the production subscriber role.
$definitions = [
    'edu-games' => ['edu_games', ['play'], ['manage']],
    'focus-tracker' => ['focus_tracker', ['participate'], ['manage']],
    'learning-materials' => ['learning_materials', ['view'], ['manage']],
    'oj-ai-dashboard' => ['oj_ai_dashboard', ['participate'], ['manage_submissions']],
    'oj-code-arena' => ['oj_code_arena', ['play'], ['teach', 'settings']],
    'oj-code-lab' => ['oj_code_lab', ['use'], ['review', 'settings']],
    'oj-microbit' => ['oj_microbit', ['learn'], ['manage']],
    'oj-ml-playgrounds' => ['oj_ml_playgrounds', ['use', 'submit'], ['review', 'settings']],
    'oj-primm' => ['oj_primm', ['learn'], ['manage']],
    'readcontents-library' => ['readcontents_library', ['view'], ['edit']],
    'teaching-tools' => ['teaching_tools', ['join'], ['host', 'settings']],
];
$entries = [];
$learnerKeys = [];
$protectedKeys = [];
foreach ($definitions as $addonId => [$namespace, $learnerActions, $educatorActions]) {
    $capabilities = [];
    foreach (['learner' => $learnerActions, 'educator' => $educatorActions, 'operator' => ['operate']] as $audience => $actions) {
        foreach ($actions as $action) {
            $key = $namespace . '_' . $action;
            $capabilities[] = new AddonCapabilityDefinitionV1($key, $key, $audience);
            if ($audience === 'learner') {
                $learnerKeys[] = AddonCapabilityKeyV1::wordpress($key);
            } else {
                $protectedKeys[] = AddonCapabilityKeyV1::wordpress($key);
            }
        }
    }
    $entries[] = new AddonCatalogEntry($addonId, new AddonManifest(
        $namespace, '1.0.0', __FILE__, '^2.0', '^1.0', $addonId, $addonId . '/support',
        $capabilities, [], [], [], [], [], []
    ), 'accepted', '');
}
$entries[] = new AddonCatalogEntry('rejected-addon', null, 'rejected', 'addon_manifest_invalid');
$entries[] = new AddonCatalogEntry('incompatible-addon', null, 'incompatible', 'addon_api_incompatible');

return [
    'catalog' => new AcceptedAddonCatalog(),
    'entries' => $entries,
    'learner_keys' => $learnerKeys,
    'protected_keys' => $protectedKeys,
];
