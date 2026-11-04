import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { grade } from '../src/grade.mjs';
import { request } from './fixtures.mjs';

test('accepted Unicode source and asset names keep the PHP project hash through real PXT grading', async () => {
  const project = JSON.parse(readFileSync(new URL('../../tests/fixtures/unicode-project.json', import.meta.url), 'utf8'));
  const expected = JSON.parse(readFileSync(new URL('../../tests/fixtures/unicode-project-hash.json', import.meta.url), 'utf8'));
  const input = request(); input.project = project;
  const result = await grade(input);
  assert.equal(result.status, 'graded');
  assert.equal(result.projectHash, expected.hash);
  assert(Object.hasOwn(project.files, '센서 도우미.ts'));
  assert(Object.hasOwn(project.files, '神.txt'));
  assert(Object.hasOwn(project.files, '𠀀.txt'));
});
