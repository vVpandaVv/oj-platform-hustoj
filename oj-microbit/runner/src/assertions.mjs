/** A value is observable only after the program actually writes that pin. */
export function evaluateAssertion(trace, assertion) {
  const writes = trace.filter(event => event.kind === 'write' && event.pin === assertion.pin);
  const deadline = assertion.afterMs + assertion.withinMs;
  const matches = event => event && event.signal === assertion.signal
    && Math.abs(event.value - assertion.expected) <= assertion.tolerance;
  let matchedAtMs = null;
  let spanStart = null;
  for (const event of [...writes, { atMs: Infinity }]) {
    if (matches(event)) { if (spanStart === null) spanStart = event.atMs; }
    else if (spanStart !== null) {
      const candidate = Math.max(spanStart, assertion.afterMs);
      if (candidate <= deadline && candidate + assertion.holdMs < event.atMs) { matchedAtMs = candidate; break; }
      spanStart = null;
    }
  }
  const latest = writes.findLast(event => event.atMs <= deadline);
  return {
    pin: assertion.pin,
    signal: assertion.signal,
    expected: assertion.expected,
    afterMs: assertion.afterMs,
    withinMs: assertion.withinMs,
    holdMs: assertion.holdMs,
    tolerance: assertion.tolerance,
    passed: matchedAtMs !== null,
    matchedAtMs,
    observed: latest ? { signal: latest.signal, value: latest.value, atMs: latest.atMs } : null,
  };
}

/** Match transactions in teacher-defined order, using each actual event once. */
export function evaluateI2CAssertions(trace, assertions) {
  let cursor = 0;
  return assertions.map(assertion => {
    const deadline = assertion.afterMs + assertion.withinMs;
    let match = -1, observed = null;
    for (let index = cursor; index < trace.length; index++) {
      const event = trace[index];
      if (event.atMs < assertion.afterMs || event.atMs > deadline) continue;
      if (!observed) observed = event;
      if (event.kind === 'i2c' && event.address === assertion.address && event.operation === assertion.operation
        && event.repeated === assertion.repeated && event.data.length === assertion.data.length
        && event.data.every((value, offset) => value === assertion.data[offset])) {
        match = index;
        observed = event;
        break;
      }
    }
    if (match !== -1) cursor = match + 1;
    return {
      address: assertion.address,
      operation: assertion.operation,
      data: [...assertion.data],
      repeated: assertion.repeated,
      afterMs: assertion.afterMs,
      withinMs: assertion.withinMs,
      passed: match !== -1,
      matchedAtMs: match !== -1 ? trace[match].atMs : null,
      observed,
    };
  });
}

/**
 * Follow output states using actual completion times, rather than guessing
 * absolute timestamps for later actions. The first matching span must hold;
 * a glitch cannot be hidden by retrying a later span. Hold intervals are
 * half-open, so a transition exactly at completion starts the next action.
 */
export function evaluateOutputSequence(trace, sequence) {
  const writes = trace.filter(event => event.kind === 'write' && event.pin === sequence.pin);
  const snapshot = event => event ? { signal: event.signal, value: event.value, atMs: event.atMs } : null;
  let start = sequence.afterMs, blocked = false;
  const steps = sequence.steps.map(step => {
    const result = { expected: step.expected, withinMs: step.withinMs, holdMs: step.holdMs, tolerance: step.tolerance,
      passed: false, matchedAtMs: null, completedAtMs: null, observed: null };
    if (blocked) return result;
    const deadline = start + step.withinMs;
    const matches = event => event && event.signal === sequence.signal && Math.abs(event.value - step.expected) <= step.tolerance;
    // Prior state is observable only if no write replaces it exactly at the
    // new action boundary. Boundary writes keep their original write order.
    let previous = -1;
    for (let index = 0; index < writes.length && writes[index].atMs < start; index++) previous = index;
    const boundaryWrite = writes[previous + 1]?.atMs === start;
    let matched = !boundaryWrite && matches(writes[previous]) ? previous : -1;
    let matchedAt = matched !== -1 ? start : null;
    if (matched === -1) {
      for (let index = previous + 1; index < writes.length; index++) {
        const event = writes[index];
        if (event.atMs > deadline) break;
        if (event.atMs >= start && matches(event)) { matched = index; matchedAt = event.atMs; break; }
      }
    }
    if (matched === -1) {
      result.observed = snapshot(writes.findLast(event => event.atMs <= deadline));
      blocked = true;
      return result;
    }
    const completedAt = matchedAt + step.holdMs;
    const glitch = writes.slice(matched + 1).find(event => event.atMs >= matchedAt && event.atMs < completedAt && !matches(event));
    if (glitch) {
      result.observed = snapshot(glitch);
      blocked = true;
      return result;
    }
    result.passed = true;
    result.matchedAtMs = matchedAt;
    result.completedAtMs = completedAt;
    result.observed = snapshot(writes[matched]);
    start = completedAt;
    return result;
  });
  return { pin: sequence.pin, signal: sequence.signal, afterMs: sequence.afterMs, passed: steps.every(step => step.passed), steps };
}
