<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

/** @internal No login, email or WordPress ID is stored in native custom runs. */
final class CodeExecutionIdentity
{
    public static function actorPrefix(int $actor, string $key): string
    {
        return 'ojv' . substr(hash_hmac('sha256', 'code-execution-actor:' . $actor, $key), 0, 12);
    }
}
