import { createRequire } from 'node:module';
import { target, runtimeVersion } from './version.mjs';

const require = createRequire(import.meta.url);
require('pxt-core/built/pxt.js');
globalThis.pxt.setAppTarget(target);
globalThis.pxt.log = () => {};
globalThis.pxt.debug = () => {};
globalThis.pxt.Util.httpRequestCoreAsync = async () => { throw new Error('External package downloads are disabled.'); };

export { runtimeVersion };

const nativeMetadata = new Set([
  'shim', 'helper', 'shimArgument', 'indexerGet', 'indexerSet', 'autoCreate',
  'callingConvention', 'async', 'promise', 'enumval', 'native', 'extern',
]);

/** Inspect only project sources; trusted bundled package declarations are loaded later by PXT. */
export function studentSourceDiagnostics(files) {
  const ts = globalThis.ts;
  const diagnostics = [];
  const report = (file, source, node, code, message) => {
    const position = node ? node.getStart(source) : 0;
    diagnostics.push({ code, file, line: source ? ts.getLineAndCharacterOfPosition(source, position).line + 1 : 1, message });
  };
  for (const [file, content] of Object.entries(files)) {
    if (!/\.ts$/i.test(file)) continue;
    if (/\.d\.ts$/i.test(file)) {
      report(file, null, null, 95001, 'Declaration files are not supported in student MakeCode projects.');
      continue;
    }
    try {
      const source = ts.createSourceFile(file, content, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
      const checkedComments = new Set();
      const visit = node => {
        if (diagnostics.length >= 30) return;
        if (node.modifiers?.some(modifier => modifier.kind === ts.SyntaxKind.DeclareKeyword)) {
          report(file, source, node, 95002, 'Ambient declarations are not supported in student MakeCode projects.');
          return;
        }
        // Use PXT's own attachment and attribute parsers, including multiple
        // attributes after one //% prefix and quoted display-only values.
        const comments = ts.pxtc.getComments(node);
        if (comments && !checkedComments.has(comments)) {
          checkedComments.add(comments);
          const attributes = ts.pxtc.parseCommentString(comments);
          // The parser always supplies Plain (0); that default is ordinary code.
          if ([...nativeMetadata].some(key => key === 'callingConvention'
            ? attributes.callingConvention !== 0 : Object.hasOwn(attributes, key))) {
            report(file, source, node, 95003, 'Native compiler linkage metadata is reserved for bundled MakeCode packages.');
          }
        }
        ts.forEachChild(node, visit);
      };
      visit(source);
    } catch {
      // The compiler still owns normal syntax diagnostics. A parser failure must
      // never let unchecked source proceed into compilation.
      report(file, null, null, 95004, 'Student TypeScript could not be checked safely.');
    }
    if (diagnostics.length >= 30) break;
  }
  return diagnostics.slice(0, 30);
}

export async function compileProject(project) {
  const files = { ...project.files };
  const policyDiagnostics = studentSourceDiagnostics(files);
  if (policyDiagnostics.length) return { success: false, diagnostics: policyDiagnostics };
  // MakeCode saves TypeScript alongside block/Python views. That saved TS is the
  // one source used for both grading and the editor's native HEX compilation.
  const config = JSON.parse(files['pxt.json']);
  config.preferredEditor = 'tsprj';
  config.files = config.files.filter(file => !file.endsWith('.py') && !file.endsWith('.blocks'));
  files['pxt.json'] = JSON.stringify(config);
  const result = await globalThis.pxt.simpleCompileAsync(files, { native: false });
  const diagnostics = (result.diagnostics || []).map(d => ({
    code: d.code,
    file: d.fileName || '',
    line: (d.line || 0) + 1,
    message: globalThis.ts.pxtc.getDiagnosticString(d).slice(0, 2000),
  })).slice(0, 30);
  if (!result.success || !result.outfiles?.['binary.js']) {
    return { success: false, diagnostics };
  }
  return { success: true, js: result.outfiles['binary.js'], diagnostics };
}
