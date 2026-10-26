import assert from 'node:assert/strict';
import { missingPhpFragments } from '../../scripts/php-source-fragments.mjs';

const source = `<?php
public const SCHEMA   = 'oj_ai.result_print';
$row = array( 'source_mode'\t=> 'approve' );
$this->authorized( );
`;
const valid = ["public const SCHEMA = 'oj_ai.result_print'", "'source_mode' => 'approve'", '$this->authorized('];
assert.deepEqual(missingPhpFragments(source, valid), []);
const changed = ["public const SCHEMA = 'oj_ai.private_result'", "'source_mode' => 'publish'", '$this->write('];
assert.deepEqual(missingPhpFragments(source, changed), changed);
for (const fake of [
  "<?php /* public const SCHEMA = 'oj_ai.result_print'; */",
  `<?php $example = "public const SCHEMA = 'oj_ai.result_print';";`,
]) {
  assert.deepEqual(missingPhpFragments(fake, [valid[0]]), [valid[0]]);
}
assert.deepEqual(missingPhpFragments("<?php $value = 'two  spaces';", ["$value = 'two spaces';"]), ["$value = 'two spaces';"]);
assert.deepEqual(missingPhpFragments('<?php $a == $b;', ['$a === $b;']), ['$a === $b;']);
assert.throws(() => missingPhpFragments(source, valid, { phpExecutable: '__ojai_missing_php__' }));
console.log('Design PHP fragments PASS: formatting accepted; changed values, comments, strings, operators and missing PHP rejected.');
