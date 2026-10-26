<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;
interface CodeExecutionMaintenance
{
    public function housekeeping():array;
    public function status():array;
}
