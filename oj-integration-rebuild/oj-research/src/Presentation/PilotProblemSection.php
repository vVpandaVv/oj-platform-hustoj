<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Presentation;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionItem;
use CodingPanda\OjResearch\Application\PilotService;
use CodingPanda\OjResearch\Domain\PilotProtocol;
final class PilotProblemSection implements ProblemDetailSectionProvider
{
    public function __construct(private readonly PilotService $pilot) {}
    public function key():string {return 'research';}
    public function provide(int $problemId,int $userId,int $limit=5):?ProblemDetailSection
    {
        $ctx=$this->pilot->learner($userId);if($ctx===null||!in_array($ctx['study']['status'],array_merge(PilotProtocol::ACTIVE,['paused']),true)){return null;}
        $mode=$this->pilot->aiMode($ctx['study'],$ctx['participant']);
        return new ProblemDetailSection('research','현재 연구 활동',[
            new ProblemDetailSectionItem($ctx['study']['title'],'/research',PilotProtocol::AI[$mode],PilotProtocol::PHASES[$ctx['study']['status']])],
            '풀이 시작·일시 중단','/research','내 연구 활동','/research');
    }
}
