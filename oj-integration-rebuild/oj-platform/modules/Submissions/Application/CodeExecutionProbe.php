<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use DomainException;

/** A private completion suffix disambiguates native TR stdout from runtime errors/truncation. */
final class CodeExecutionProbe
{
    public function instrument(string $language, string $source, string $marker): string
    {
        if (preg_match('/^OJVERIFY_[a-f0-9]{64}$/D', $marker) !== 1) { throw new DomainException('Invalid probe.'); }
        return match ($language) {
            'python' => "import atexit as _oj_a, sys as _oj_s\n_oj_a.register(lambda: _oj_s.stdout.write('" . $marker . "'))\n"
                . "exec(compile(bytes.fromhex('" . bin2hex($source) . "'), '<primm-example>', 'exec'), globals())\n",
            'c', 'cpp' => "#line 1 \"primm-example\"\n" . $source . "\n#line 1 \"primm-verification\"\n#include <stdio.h>\n#include <stdlib.h>\n"
                . ($language === 'cpp' ? "#include <iostream>\n" : '')
                . "static void oj_verify_done(void){" . ($language === 'cpp' ? 'std::cout.flush();' : '')
                . "fputs(\"" . $marker . "\",stdout);fflush(stdout);}\n"
                . "__attribute__((constructor)) static void oj_verify_init(void){atexit(oj_verify_done);}\n",
            'java' => $this->java($source, $marker),
            default => throw new DomainException('Unsupported execution language.'),
        };
    }

    /** @return array{verdict:string,actual_output:string,diagnostics:string} */
    public function inspect(int $result, string $output, string $compileError, string $marker): array
    {
        if ($result === 11) {
            return ['verdict' => 'compile_error', 'actual_output' => '', 'diagnostics' => $this->safe($compileError, $marker)];
        }
        if ($result === 13 && str_ends_with($output, $marker)) {
            $actual = substr($output, 0, -strlen($marker));
            if (preg_match('//u', $actual) === 1 && strlen($actual) <= 16000 && ! str_contains($actual, "\0") && ! str_contains($actual, $marker)) {
                return ['verdict' => 'executed', 'actual_output' => $actual, 'diagnostics' => ''];
            }
        }
        $verdict = match ($result) { 7 => 'time_limit', 8 => 'memory_limit', 9 => 'output_limit', 10 => 'runtime_error',
            default => 'output_incomplete' };
        // Native TR can contain stderr instead of stdout. Never treat an absent
        // marker (including empty output, kill, timeout or truncated output) as execution success.
        return ['verdict' => $verdict, 'actual_output' => '', 'diagnostics' => $this->safe($output, $marker)
            ?: '정상 종료와 전체 출력을 확인하지 못했습니다. 실행 오류·제한 초과 또는 출력 잘림을 확인해 주세요.'];
    }

    private function java(string $source, string $marker): string
    {
        // Keep the original source intact except a Main initializer. Its hook runs
        // on normal return/System.exit; nonzero exit and exceptions produce native stderr instead.
        $count = 0;
        $result = preg_replace('/\bclass\s+Main\s*\{/', '$0' . "\nstatic {Runtime.getRuntime().addShutdownHook(new Thread(() -> System.out.print(\""
            . $marker . "\")));}\n", $source, 1, $count);
        if ($count !== 1 || ! is_string($result)) {
            // Keep malformed/unconventional entry points repairable by the native
            // compiler. No probe means they can never be marked executed.
            return $source;
        }
        return $result;
    }

    private function safe(string $text, string $marker): string
    {
        $text = str_replace($marker, '[검증 표식]', $text);
        $text = preg_replace('/OJVERIFY_[a-f0-9]{0,64}/', '[검증 표식]', $text) ?? '';
        $text = preg_replace('~(?:[A-Za-z]:[\\\\/]|/)(?:[^\s:<>"\x27]+[/\\\\])+([^\s:<>"\x27]+)~', '$1', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = substr($text, 0, 7900);
        while ($text !== '' && preg_match('//u', $text) !== 1) { $text = substr($text, 0, -1); }
        return $text;
    }
}
