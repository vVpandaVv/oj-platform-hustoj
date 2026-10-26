<?php
declare(strict_types=1);

/** @return array{source_commit:string,source_dirty:bool,source_tag:?string} */
function researchValidateRelease(string $version, string $commit, bool $dirty, ?string $tagCommit, bool $allowDirty): array
{
    if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
        throw new RuntimeException('Invalid release version');
    }
    if (preg_match('/^[a-f0-9]{40,64}$/D', $commit) !== 1) {
        throw new RuntimeException('A release requires a known Git commit');
    }
    $stable = ! str_contains($version, '-');
    if ($stable && $allowDirty) { throw new RuntimeException('Stable releases cannot allow dirty sources'); }
    if ($dirty && ! $allowDirty) { throw new RuntimeException('Commit all release changes before packaging'); }
    if ($stable && $tagCommit !== $commit) { throw new RuntimeException('The research release tag must point to HEAD'); }
    return ['source_commit'=>$commit, 'source_dirty'=>$dirty, 'source_tag'=>$stable ? 'oj-research-v' . $version : null];
}

function researchGit(string $root, array $arguments): ?string
{
    $process = proc_open(['git', '-c', 'core.excludesFile=', '-C', $root, ...$arguments], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot inspect Git release state'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    return proc_close($process) === 0 ? trim($output) : null;
}

function researchReleaseState(string $root, string $version, bool $allowDirty): array
{
    $commit = researchGit($root, ['rev-parse', 'HEAD']);
    $status = researchGit($root, ['status', '--porcelain', '--untracked-files=all']);
    if ($commit === null || $status === null) { throw new RuntimeException('Cannot read a complete Git release state'); }
    $tagCommit = str_contains($version, '-') ? null : researchGit($root, ['rev-list', '-n', '1', 'oj-research-v' . $version]);
    return researchValidateRelease($version, $commit, $status !== '', $tagCommit, $allowDirty);
}
