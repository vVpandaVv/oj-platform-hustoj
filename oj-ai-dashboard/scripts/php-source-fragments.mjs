import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const helper = fileURLToPath(new URL('../tests/Support/Php_Source_Contract.php', import.meta.url));
const driver = String.raw`
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require $input['helper'];
$missing = [];
foreach ($input['fragments'] as $fragment) {
    if (!Ojai_Php_Source_Contract::contains($input['source'], $fragment)) {
        $missing[] = $fragment;
    }
}
echo json_encode($missing, JSON_THROW_ON_ERROR);
`;

// Reuse PHP's tokenizer; never normalize whitespace inside string literals.
export function missingPhpFragments(source, fragments, { phpExecutable = process.env.OJAI_PHP || 'php' } = {}) {
  const result = spawnSync(phpExecutable, ['-r', driver], {
    input: JSON.stringify({ helper, source, fragments }),
    encoding: 'utf8',
    timeout: 15000,
    maxBuffer: 32 * 1024 * 1024,
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`PHP fragment inspection failed: ${result.stderr || result.stdout}`);
  const missing = JSON.parse(result.stdout);
  if (!Array.isArray(missing) || missing.some((fragment) => !fragments.includes(fragment))) {
    throw new Error('Invalid PHP fragment inspection result.');
  }
  return missing;
}
