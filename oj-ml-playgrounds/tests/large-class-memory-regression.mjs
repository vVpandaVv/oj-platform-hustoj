import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

// Bound this check independently of the build machine's available memory.
if (process.argv[2] !== '--bounded-child') {
    const child = spawnSync(process.execPath, ['--max-old-space-size=256', fileURLToPath(import.meta.url), '--bounded-child'], {
        encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024,
    });
    assert.equal(child.status, 0, child.error?.message || child.stderr || child.stdout);
    process.stdout.write(child.stdout);
} else {
    const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
    assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed pinned source.');
    const requirePackage = createRequire(path.join(frontend, 'package.json'));
    const ts = requirePackage('typescript');
    const modules = new Map();
    function sourceModule(filename) {
        const absolute = path.resolve(filename);
        assert(absolute.startsWith(path.join(frontend, 'src') + path.sep), 'Only upstream source modules may load.');
        if (modules.has(absolute)) return modules.get(absolute).exports;
        const module = { exports: {} };
        modules.set(absolute, module);
        const compiled = ts.transpileModule(readFileSync(absolute, 'utf8'), {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
        }).outputText;
        const localRequire = specifier => specifier.startsWith('.')
            ? sourceModule(path.resolve(path.dirname(absolute), specifier + '.ts'))
            : requirePackage(specifier);
        runInThisContext('(function(require,module,exports){' + compiled + '\n})', { filename: absolute })(localRequire, module, module.exports);
        return module.exports;
    }
    const { evaluate } = sourceModule(path.join(frontend, 'src/ml/metrics.ts'));
    const { MAX_DENSE_CLASSIFICATION_LABELS } = sourceModule(path.join(frontend, 'src/limits.ts'));
    assert.equal(MAX_DENSE_CLASSIFICATION_LABELS, 50);
    for (const count of [50, 51]) {
        const labels = Array.from({ length: count }, (_, index) => String(index));
        const result = evaluate('classification', labels, labels);
        assert.equal(result.metrics.accuracy, 1);
        assert.equal(result.metrics.f1Macro, 1);
        assert.equal(result.confusionMatrix !== undefined, count <= MAX_DENSE_CLASSIFICATION_LABELS);
    }
    const actual = Array.from({ length: 8760 }, (_, index) => String(index % 2166));
    const predicted = actual.map((label, index) => index % 17 === 0 ? String((Number(label) + 1) % 2166) : label);
    const expectedCorrect = actual.reduce((sum, label, index) => sum + Number(label === predicted[index]), 0);
    for (let modelIndex = 0; modelIndex < 3; modelIndex++) {
        const result = evaluate('classification', actual, predicted);
        assert.equal(result.confusionMatrix, undefined, 'Large-class evaluation must not allocate or serialize a quadratic matrix.');
        assert.equal(result.perClass.length, 2166);
        assert.equal(result.perClass.reduce((sum, row) => sum + row.support, 0), 8760);
        assert.equal(result.metrics.accuracy, expectedCorrect / 8760);
        assert(Number.isFinite(result.metrics.f1Macro));
        assert(Buffer.byteLength(JSON.stringify(result)) < 1024 * 1024, 'Per-model summary must stay bounded for the reported class count.');
    }
    console.log('Large-class memory regression passed (actual upstream metrics, 8760 rows / 2166 classes / three evaluations, 256 MiB heap limit).');
}
