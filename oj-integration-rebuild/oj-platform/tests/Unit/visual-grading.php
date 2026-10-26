<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionWrapperCode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\PngReferenceImage;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingWrapper;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\PluginVisualGradingTemplates;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourceComposer;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$assertions = 0;

function visualAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function visualThrows(callable $callback, string $needle, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        visualAssert(str_contains($error->getMessage(), $needle), $message . ' (' . $error->getMessage() . ')');

        return;
    }
    throw new RuntimeException($message . ' (예외 없음)');
}

/**
 * @param list<array{0: string, 1: string}> $extraChunks
 */
function visualPng(
    int $width = 2,
    int $height = 2,
    int $depth = 8,
    int $colorType = 2,
    array $extraChunks = [],
    ?string $trailing = null
): string {
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data
            . pack('N', crc32($type . $data) & 0xFFFFFFFF);
    };
    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NN', $width, $height) . chr($depth) . chr($colorType) . "\0\0\0");
    foreach ($extraChunks as [$type, $data]) {
        $png .= $chunk($type, $data);
    }

    return $png . $chunk('IDAT', gzcompress("\0\xff\xff\xff\xff\xff\xff")) . $chunk('IEND', '')
        . ($trailing ?? '');
}

// 1. 모드
visualAssert(VisualGradingMode::fromValue('turtle') === VisualGradingMode::Turtle, 'turtle 값을 읽어야 합니다.');
visualAssert(VisualGradingMode::fromValue('bogus') === VisualGradingMode::None, '알 수 없는 값은 사용 안 함이어야 합니다.');
visualAssert(! VisualGradingMode::None->isEnabled() && VisualGradingMode::Matplotlib->isEnabled(), '활성 여부를 구분해야 합니다.');

// 2. 래퍼 값 객체
$wrapper = new VisualGradingWrapper("a = 1\n\nb = 2\n", "print(a)\n");
visualAssert(
    $wrapper->hiddenPrefix() === "a = 1  #hidden\n  #hidden\nb = 2  #hidden",
    '모든 줄 끝에 비공개 표식을 붙이고 빈 줄도 유지해야 합니다.'
);
visualAssert($wrapper->hiddenSuffix() === 'print(a)  #hidden', '뒤 코드도 줄마다 표식을 붙여야 합니다.');
foreach ([["x = '''a'''\n", 'ok'], ["x = 1 + \\\n 2\n", 'ok'], ["  \n", 'ok']] as [$bad]) {
    visualThrows(
        static fn () => new VisualGradingWrapper($bad, 'pass'),
        '',
        '여러 줄 문자열·줄 이음·빈 코드는 래퍼로 쓸 수 없습니다.'
    );
}

// 3. 실제 리소스 템플릿
$templates = new PluginVisualGradingTemplates($pluginRoot . '/resources/features');
$composer = new SubmissionSourceComposer();
foreach ([VisualGradingMode::Matplotlib, VisualGradingMode::Turtle] as $mode) {
    $template = $templates->wrapper($mode);
    foreach ([$template->hiddenPrefix(), $template->hiddenSuffix()] as $hidden) {
        foreach (explode("\n", $hidden) as $line) {
            visualAssert(
                preg_match('/[ \t]*#hidden[ \t]*$/', $line) === 1,
                $mode->value . ' 래퍼의 모든 줄은 비공개 표식으로 끝나야 합니다.'
            );
        }
        $code = new SubmissionWrapperCode($hidden);
        visualAssert(
            trim($code->visibleCode()) === '' && $code->hasHiddenLines(),
            $mode->value . ' 래퍼는 학생에게 보이지 않아야 합니다.'
        );
        visualAssert(
            substr_count($code->judgeCode(), "\n") === substr_count($hidden, "\n"),
            $mode->value . ' 래퍼는 채점용 소스에서 줄 수를 유지해야 합니다.'
        );
    }
}
visualThrows(
    static fn () => $templates->wrapper(VisualGradingMode::None),
    '선택되지',
    '사용 안 함 모드는 래퍼를 제공하면 안 됩니다.'
);
$spj = $templates->spjSource();
visualAssert(
    str_contains($spj, 'OJ_IMAGE_PNG_BASE64_V1') && strlen($spj) > 100000 && strlen($spj) < 2097152,
    '이미지 채점기 단일 소스를 제공해야 합니다.'
);
visualThrows(
    static fn () => (new PluginVisualGradingTemplates($pluginRoot . '/resources/features/missing'))->spjSource(),
    '구성 파일',
    '리소스가 없으면 안전한 오류를 내야 합니다.'
);

// 4. PNG 정답 이미지 검증
$png = visualPng();
$image = PngReferenceImage::fromBytes($png);
visualAssert($image->width() === 2 && $image->height() === 2, 'PNG 크기를 읽어야 합니다.');
$wrapped = $image->wrappedText();
visualAssert(
    str_starts_with($wrapped, "OJ_IMAGE_PNG_BASE64_V1\n") && str_ends_with($wrapped, "\n"),
    'Base64 포장 형식은 헤더 줄로 시작하고 줄바꿈으로 끝나야 합니다.'
);
visualAssert(PngReferenceImage::fromWrappedText($wrapped)?->bytes() === $png, '포장 해제 후 원본 PNG와 같아야 합니다.');
visualAssert(
    PngReferenceImage::fromWrappedText(str_replace("\n", "\r\n", $wrapped))?->bytes() === $png,
    'CRLF 포장도 해제해야 합니다.'
);
visualAssert(PngReferenceImage::fromWrappedText("hello\n") === null, '일반 텍스트 .out은 이미지로 취급하면 안 됩니다.');
visualThrows(
    static fn () => PngReferenceImage::fromWrappedText("OJ_IMAGE_PNG_BASE64_V1\n!!!\n"),
    'Base64',
    '잘못된 Base64는 거부해야 합니다.'
);
$longLines = chunk_split(
    base64_encode(visualPng(300, 200, 8, 2, [['tEXt', "k\0" . str_repeat('a', 400)]])),
    120,
    "\n"
);
visualAssert(
    strlen($longLines) > 120 && PngReferenceImage::fromWrappedText("OJ_IMAGE_PNG_BASE64_V1\n" . $longLines) !== null,
    '120자 줄바꿈 Base64를 읽어야 합니다.'
);
foreach ([
    ['JPEG 위장', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 80), '실제 PNG'],
    ['잘림', substr($png, 0, 40), ''],
    ['CRC 손상', substr_replace($png, 'X', 20, 1), '손상'],
    ['16비트', visualPng(2, 2, 16), '8비트'],
    ['너무 큼', visualPng(2049, 10), '2048'],
    ['픽셀 수 초과', visualPng(2048, 2048) !== '' ? visualPng(2048, 2048) : '', ''],
    ['APNG', visualPng(2, 2, 8, 2, [['acTL', pack('NN', 2, 0)]]), 'APNG'],
    ['뒤 데이터', visualPng(2, 2, 8, 2, [], 'junk'), '불필요'],
    ['지원하지 않는 색상', visualPng(2, 2, 8, 5), '8비트'],
] as [$label, $bytes, $needle]) {
    if ($label === '픽셀 수 초과') {
        // 2048 x 2048 = 4,194,304 픽셀은 허용 한계이므로 통과해야 한다.
        visualAssert(PngReferenceImage::fromBytes($bytes)->width() === 2048, '최대 크기 PNG는 허용해야 합니다.');
        continue;
    }
    visualThrows(
        static fn () => PngReferenceImage::fromBytes($bytes),
        $needle,
        $label . ' PNG는 거부해야 합니다.'
    );
}

// 5. 래퍼를 실제 Python으로 실행해 표준 출력 PNG 계약 검증
$python = null;
foreach (['python3', 'python'] as $candidate) {
    $probe = @shell_exec($candidate . ' -c "import sys; print(sys.version_info[0])" 2>&1');
    if (is_string($probe) && trim($probe) === '3') {
        $python = $candidate;
        break;
    }
}

/**
 * @return array{exit: int, stdout: string, stderr: string}
 */
function visualRun(string $python, string $source, bool $withoutTkinter = false): array
{
    $file = tempnam(sys_get_temp_dir(), 'ojvg');
    file_put_contents($file, $source);
    $command = $withoutTkinter
        ? [$python, '-c', "import sys\nsys.modules['tkinter'] = None\n"
            . "exec(compile(open(sys.argv[1], encoding='utf-8').read(), sys.argv[1], 'exec'))", $file]
        : [$python, $file];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    unlink($file);

    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function visualCompose(PluginVisualGradingTemplates $templates, VisualGradingMode $mode, string $student): string
{
    global $composer;
    $wrapper = $templates->wrapper($mode);

    return $composer->compose($wrapper->hiddenPrefix(), $student, $wrapper->hiddenSuffix());
}

$pythonChecks = 0;
if ($python === null) {
    fwrite(STDOUT, "Python 3를 찾지 못해 래퍼 실행 검사는 건너뜁니다.\n");
} else {
    $hasPillow = trim((string) shell_exec($python . ' -c "import PIL; print(1)" 2>&1')) === '1';
    $hasMatplotlib = trim((string) shell_exec($python . ' -c "import matplotlib; print(1)" 2>&1')) === '1';
    $turtleStudent = "import turtle\nt = turtle.Turtle()\nt.pensize(3)\nt.color('red', 'yellow')\n"
        . "t.begin_fill()\nfor _ in range(4):\n    t.forward(100)\n    t.left(90)\nt.end_fill()\n"
        . "print('debug output must not reach the image')\nturtle.done()\n";
    if ($hasPillow) {
        $first = visualRun($python, visualCompose($templates, VisualGradingMode::Turtle, $turtleStudent));
        visualAssert($first['exit'] === 0, 'turtle 래퍼 프로그램이 정상 종료해야 합니다: ' . $first['stderr']);
        $turtleImage = PngReferenceImage::fromBytes($first['stdout']);
        visualAssert(
            $turtleImage->width() === 800 && $turtleImage->height() === 600,
            'turtle 출력은 800x600 PNG만 표준 출력에 있어야 합니다.'
        );
        visualAssert(str_contains($first['stderr'], 'debug output'), '학생의 print는 표준 오류로 가야 합니다.');
        $second = visualRun($python, visualCompose($templates, VisualGradingMode::Turtle, $turtleStudent));
        visualAssert($second['stdout'] === $first['stdout'], 'turtle 출력은 실행마다 같아야 합니다.');
        $stub = visualRun($python, visualCompose($templates, VisualGradingMode::Turtle, $turtleStudent), true);
        visualAssert(
            $stub['exit'] === 0 && $stub['stdout'] === $first['stdout'],
            'tkinter가 없는 서버에서도 같은 PNG를 내야 합니다: ' . $stub['stderr']
        );
        $failing = visualRun($python, visualCompose($templates, VisualGradingMode::Turtle, "raise RuntimeError('boom')\n"));
        visualAssert($failing['exit'] !== 0 && $failing['stdout'] === '', '학생 코드 오류는 PNG 없이 비정상 종료해야 합니다.');
        $pythonChecks += 5;
    }
    if ($hasMatplotlib) {
        $student = "import matplotlib.pyplot as plt\nplt.plot([1, 2, 3], [1, 4, 9])\nprint('log')\nplt.show()\n";
        $result = visualRun($python, visualCompose($templates, VisualGradingMode::Matplotlib, $student));
        visualAssert($result['exit'] === 0, 'Matplotlib 래퍼 프로그램이 정상 종료해야 합니다: ' . $result['stderr']);
        $mpl = PngReferenceImage::fromBytes($result['stdout']);
        visualAssert($mpl->width() === 640 && $mpl->height() === 480, 'Matplotlib 출력은 640x480 PNG여야 합니다.');
        $again = visualRun($python, visualCompose($templates, VisualGradingMode::Matplotlib, $student));
        visualAssert($again['stdout'] === $result['stdout'], 'Matplotlib 출력은 실행마다 같아야 합니다.');
        $empty = visualRun($python, visualCompose($templates, VisualGradingMode::Matplotlib, "print('no figure')\n"));
        visualAssert($empty['exit'] === 0 && $empty['stdout'] === '', '그림이 없으면 표준 출력이 비어 있어야 합니다.');
        $pythonChecks += 4;
    }
    fwrite(STDOUT, sprintf(
        "래퍼 실행 검사: Pillow=%s, Matplotlib=%s\n",
        $hasPillow ? '예' : '아니오(건너뜀)',
        $hasMatplotlib ? '예' : '아니오(건너뜀)'
    ));
}

fwrite(STDOUT, sprintf("이미지 채점 단위 검사 통과: assertion %d개\n", $assertions));
