import { createHash } from 'node:crypto';
import { compileProject, runtimeVersion } from './compiler.mjs';
import { validateCompileRequest } from './validation.mjs';
export async function compileLive(payload) {
  validateCompileRequest(payload);
  const project={name:payload.project.name,files:Object.fromEntries(Object.entries(payload.project.files).sort(([a],[b])=>Buffer.compare(Buffer.from(a),Buffer.from(b))))};
  const projectHash=createHash('sha256').update(JSON.stringify(project)).digest('hex');
  const compiled=await compileProject(project);
  return {status:compiled.success?'compiled':'compile_error',runtimeVersion,liveProtocol:'oj-live/1',projectHash,diagnostics:compiled.diagnostics, ...(compiled.success?{js:compiled.js}:{})};
}
