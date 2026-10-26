<?php

declare(strict_types=1);

namespace CodingPanda\OjResearch\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjResearch\Domain\PilotProtocol;

final class PilotConfigurationInput
{
    public static function fromRequest(HttpRequest $request): array
    {
        $input = ['groups' => $request->bodyIntList('groups')];
        $input['design'] = $request->bodyString('design', 'custom');
        $input['covariance'] = $request->bodyString('covariance', 'auto');
        foreach ([
            'title', 'practice', 'pretest', 'posttest', 'lessons_count', 'test_minutes',
            'request_limit', 'allocation', 'level_timing', 'scoring', 'errors',
            'pause_time', 'survey_max', 'survey_items', 'common_instructions',
        ] as $key) {
            $input[$key] = $request->bodyString($key);
        }
        foreach (['A', 'B'] as $arm) {
            foreach (['name', 'ai', 'recommendation', 'weight', 'minutes', 'conditions'] as $key) {
                $input['arms'][$arm][$key] = $request->bodyString('arm_' . $arm . '_' . $key);
            }
            foreach (['pretest', 'posttest'] as $phase) {
                $input['support'][$phase][$arm] = $request->bodyString('support_' . $phase . '_' . $arm);
            }
        }
        foreach (array_keys(PilotProtocol::NOTES) as $key) {
            $input['notes'][$key] = $request->bodyString('note_' . $key);
        }
        return $input;
    }
}
