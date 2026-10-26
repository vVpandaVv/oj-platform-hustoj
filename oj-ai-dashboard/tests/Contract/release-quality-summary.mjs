import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { summarizeReports } from '../../scripts/summarize-release-quality.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const message = (source, type = 'ERROR', fixable = false) => ({ source, type, fixable, line: 12, column: 4, message: 'Review required' });
const styles = {
  totals: { errors: 3, warnings: 1 },
  files: {
    [path.join(root, 'src/Example.php')]: { messages: [message('WordPress.Security.EscapeOutput.OutputNotEscaped'), message('Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed', 'WARNING', true)] },
    [path.join(root, 'tests/Contract/example.php')]: { messages: [message('Squiz.Commenting.FunctionComment.Missing'), message('WordPress.DB.PreparedSQL.NotPrepared')] },
  },
};
const types = {
  totals: { errors: 1, file_errors: 2 },
  errors: ['Analysis incomplete'],
  files: {
    [path.join(root, 'src/Example.php')]: { messages: [
      { identifier: 'function.alreadyNarrowedType', line: 8, message: 'Known type' },
      { identifier: 'parameterByRef.type', line: 20, message: 'Reference contract' },
    ] },
  },
};
const result = summarizeReports(styles, types, root);
assert.equal(result.productionApproved, false);
assert.equal(result.phpcs.diagnostics, 4);
assert.equal(result.phpstan.diagnostics, 2);
assert.deepEqual(result.phpstan.globalErrors, ['Analysis incomplete']);
assert.equal(result.phpcs.byScope.find(item => item.name === 'runtime').count, 2);
assert.equal(result.phpcs.byScope.find(item => item.name === 'tests').count, 2);
assert.equal(result.phpcs.byCategory.find(item => item.name === 'security_review').count, 1);
assert.equal(result.phpcs.byCategory.find(item => item.name === 'tool_fixable').fixable, 1);
assert.equal(result.phpcs.byCategory.find(item => item.name === 'documentation_naming').count, 1);
assert.equal(result.phpcs.byCategory.find(item => item.name === 'database_review').count, 1);
assert.equal(result.phpstan.byCategory.find(item => item.name === 'type_control_flow').count, 1);
assert.equal(result.phpstan.byCategory.find(item => item.name === 'reference_contract').count, 1);
assert.equal(result.phpstan.findings.length, 2);
assert.throws(() => summarizeReports({ ...styles, totals: { errors: 0, warnings: 0 } }, types, root), /totals/);
assert.throws(() => summarizeReports(styles, { ...types, errors: [] }, root), /global errors/);
assert.throws(() => summarizeReports(styles, { ...types, totals: { errors: 1, file_errors: 0 } }, root), /totals/);
assert.throws(() => summarizeReports({ ...styles, files: { '../outside.php': { messages: [] } } }, types, root), /outside/);
assert.throws(() => summarizeReports({ files: [] }, types, root), /files object/);
const malformed = structuredClone(styles);
malformed.files[path.join(root, 'src/Example.php')].messages[0].type = 'PASS';
assert.throws(() => summarizeReports(malformed, types, root), /Invalid PHPCS/);
const empty = summarizeReports({ totals: { errors: 0, warnings: 0 }, files: {} }, { totals: { errors: 0, file_errors: 0 }, errors: [], files: {} }, root);
assert.equal(empty.productionApproved, false);
console.log('Release quality summary PASS: counts, scopes, categories, failure preservation, path boundary and no implicit approval.');
