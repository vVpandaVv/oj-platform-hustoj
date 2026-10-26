<?php
/** CLI adapter for legacy text markers plus format-independent PHP fragments. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/tests/Support/Php_Source_Contract.php';

$input = json_decode(base64_decode(trim(stream_get_contents(STDIN)), true), true, 512, JSON_THROW_ON_ERROR);
$source = $input['source'];
$marker = $input['marker'];
if (!is_string($source) || !is_string($marker) || $marker === '') {
    throw new InvalidArgumentException('Expected source and a nonempty marker.');
}
$matches = str_contains($source, $marker);
if (!$matches && str_starts_with(ltrim($source), '<?php')) {
    $matches = Ojai_Php_Source_Contract::contains($source, $marker);
    if (!$matches) {
        // Old route markers intentionally stop inside a quoted path prefix.
        $tokens = static function (string $text): array {
            if (!str_starts_with(ltrim($text), '<?php')) $text = '<?php ' . $text;
            $result = [];
            foreach (token_get_all($text) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                    $result[] = [$token[0], $token[1]];
                } else {
                    $result[] = [0, $token];
                }
            }
            return $result;
        };
        $needle = $tokens($marker);
        $last = $needle[count($needle) - 1] ?? null;
        if ($last !== null && $last[0] === T_ENCAPSED_AND_WHITESPACE && str_starts_with($last[1], "'")) {
            $haystack = $tokens($source);
            for ($i = 0, $limit = count($haystack) - count($needle); $i <= $limit; ++$i) {
                foreach ($needle as $j => $wanted) {
                    $actual = $haystack[$i + $j];
                    $same = $j === count($needle) - 1
                        ? $actual[0] === T_CONSTANT_ENCAPSED_STRING && str_starts_with($actual[1], $wanted[1])
                        : $actual === $wanted;
                    if (!$same) continue 2;
                }
                $matches = true;
                break;
            }
        }
    }
}
echo $matches ? 'true' : 'false';
