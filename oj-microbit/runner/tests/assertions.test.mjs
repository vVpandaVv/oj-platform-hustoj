import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateAssertion } from '../src/assertions.mjs';
import { assertion } from './fixtures.mjs';
const write = (atMs, value, signal = 'digital') => ({ atMs, pin: 1, signal, value, kind: 'write' });

test('unwritten initial zero is not a correct program output', () => {
  assert.equal(evaluateAssertion([], assertion({ expected: 0 })).passed, false);
});
test('output must have the expected signal type', () => {
  assert.equal(evaluateAssertion([write(300, 1, 'pwm')], assertion()).passed, false);
});
test('prior output is retained as real output state', () => {
  assert.equal(evaluateAssertion([write(0, 1)], assertion()).matchedAtMs, 300);
});
test('a transient match that does not hold fails', () => {
  assert.equal(evaluateAssertion([write(310, 1), write(350, 0)], assertion()).passed, false);
});
test('a matching value after the response deadline fails', () => {
  assert.equal(evaluateAssertion([write(401, 1)], assertion()).passed, false);
});
test('later valid span inside response window can pass', () => {
  assert.equal(evaluateAssertion([write(300, 1), write(310, 0), write(330, 1)], assertion()).matchedAtMs, 330);
});
test('a mismatch at the end of the hold period fails', () => {
  assert.equal(evaluateAssertion([write(300, 1), write(400, 0)], assertion({ withinMs: 1 })).passed, false);
});
test('PWM tolerance is applied without converting digital signal types', () => {
  const result = evaluateAssertion([write(300, 511, 'pwm')], assertion({ signal: 'pwm', expected: 512, tolerance: 1 }));
  assert.equal(result.passed, true);
});
