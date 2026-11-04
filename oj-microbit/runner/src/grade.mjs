import { createHash } from 'node:crypto';
import { compileProject, runtimeVersion } from './compiler.mjs';
import { simulate } from './simulator.mjs';
import { evaluateAssertion, evaluateI2CAssertions, evaluateOutputSequence } from './assertions.mjs';
import { validateRequest } from './validation.mjs';

export async function grade(payload) {
  validateRequest(payload);
  // Match PHP SORT_STRING for all accepted UTF-8 names, including non-BMP letters.
  const project = { name: payload.project.name, files: Object.fromEntries(Object.entries(payload.project.files).sort(([a], [b]) => Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8')))) };
  const projectHash = createHash('sha256').update(JSON.stringify(project)).digest('hex');
  const base = { runtimeVersion, projectHash, trace: [], i2cTrace: [], traceScenarioId: null };
  const compiled = await compileProject(project);
  if (!compiled.success) return { ...base, status: 'compile_error', passed: false, score: 0, scenarios: [], diagnostics: compiled.diagnostics };
  const scenarios = [];
  let points = 0, total = 0;
  for (const scenario of payload.exercise.scenarios) {
    const simulated = simulate(compiled.js, payload.exercise.connections, scenario, payload.exercise.i2cDevices || []);
    const assertions = scenario.assertions.map(assertion => evaluateAssertion(simulated.trace, assertion));
    const i2cAssertions = evaluateI2CAssertions(simulated.i2cTrace, scenario.i2cAssertions || []);
    const outputSequences = (scenario.outputSequences || []).map(sequence => evaluateOutputSequence(simulated.trace, sequence));
    if (simulated.error) {
      for (const sequence of outputSequences) {
        sequence.passed = false;
        for (const step of sequence.steps) Object.assign(step, { passed: false, matchedAtMs: null, completedAtMs: null, observed: null });
      }
    }
    const allAssertions = [...assertions, ...i2cAssertions, ...outputSequences.flatMap(sequence => sequence.steps)];
    if (simulated.error) for (const assertion of allAssertions) assertion.passed = false;
    const passed = allAssertions.length ? !simulated.error && allAssertions.every(assertion => assertion.passed) : null;
    points += allAssertions.filter(assertion => assertion.passed).length;
    total += allAssertions.length;
    scenarios.push({ id: scenario.id, name: scenario.name || scenario.id, passed, error: simulated.error, assertions, i2cAssertions, outputSequences });
    if (base.traceScenarioId === null && scenario.visible !== false) {
      base.trace = simulated.trace;
      base.i2cTrace = simulated.i2cTrace;
      base.traceScenarioId = scenario.id;
    }
  }
  const hasErrors = scenarios.some(scenario => scenario.error);
  return {
    ...base,
    status: hasErrors ? 'runtime_error' : total ? 'graded' : 'preview',
    passed: total ? !hasErrors && points === total : null,
    score: total ? Math.round(points * 10000 / total) / 100 : null,
    scenarios,
    diagnostics: [],
  };
}
