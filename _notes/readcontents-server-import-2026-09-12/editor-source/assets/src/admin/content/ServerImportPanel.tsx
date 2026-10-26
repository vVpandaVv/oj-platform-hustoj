import * as React from "react";
import { Copy, Eye, FolderOpen, Pause, Play, RefreshCw, Square } from "lucide-react";
import { request } from "./api";
import type { ContentImportLessonConflictPolicy as Policy, PackageImportPreviewResult, PackageImportResult } from "./types";

type Job = {
  id: string;
  fileName: string;
  status: "running" | "paused" | "failed" | "needs_review" | "completed" | "cancelled";
  cursor: number;
  canControl: boolean;
  current: string;
  error: string | null;
  assets: { done: number; total: number };
  content: { done: number; total: number };
  result: PackageImportResult | null;
};
type Info = { directory: string; files: { name: string; bytes: number; modified: number }[]; error: string | null; job: Job | null };
type Preview = { fileName: string; fingerprint: string; preview: PackageImportPreviewResult };
const labels: Record<Job["status"], string> = { running: "진행 대기", paused: "일시 정지", failed: "오류", needs_review: "등록 결과 확인 필요", completed: "완료", cancelled: "취소됨" };
const inputClass = "min-w-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 disabled:opacity-50";
const buttonClass = "inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 disabled:opacity-50";
const iconClass = "inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white disabled:opacity-50";
const post = <T,>(url: string, data: unknown) => request<T>(url, { method: "POST", body: JSON.stringify(data) });

export function ServerImportPanel({ disabled, onBeforeStart, onComplete, onActiveChange }: {
  disabled: boolean;
  onBeforeStart: () => Promise<boolean>;
  onComplete: (result: PackageImportResult) => Promise<void>;
  onActiveChange: (active: boolean) => void;
}) {
  const [info, setInfo] = React.useState<Info | null>(null);
  const [file, setFile] = React.useState("");
  const [preview, setPreview] = React.useState<Preview | null>(null);
  const [job, setJob] = React.useState<Job | null>(null);
  const [policy, setPolicy] = React.useState<Policy>("skip");
  const [policies, setPolicies] = React.useState<Record<string, Policy>>({});
  const [replaceConfirmed, setReplaceConfirmed] = React.useState(false);
  const [busy, setBusy] = React.useState(false);
  const [running, setRunning] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [copied, setCopied] = React.useState(false);
  const mounted = React.useRef(true);
  const stop = React.useRef<"pause" | "cancel" | null>(null);
  const requestId = React.useRef("");
  const active = !!job && !["completed", "cancelled"].includes(job.status);
  const replaces = !!preview?.preview.conflicts.some(conflict => (policies[conflict.incoming.id] ?? policy) === "replace");

  const receiveJob = (value: Job | null) => { if (mounted.current) setJob(value); };
  async function refresh() {
    const value = await request<Info>("/packages/server");
    if (mounted.current) {
      setInfo(value);
      receiveJob(value.job);
      setFile(current => value.files.some(item => item.name === current) ? current : value.files[0]?.name ?? "");
    }
    return value.job;
  }
  React.useEffect(() => {
    mounted.current = true;
    void refresh().catch(problem => { if (mounted.current) setError(message(problem)); });
    return () => { mounted.current = false; stop.current = "pause"; };
  }, []);
  React.useEffect(() => { onActiveChange(active); }, [active, onActiveChange]);
  React.useEffect(() => {
    if (!running) return;
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ""; };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [running]);

  async function inspectFile() {
    if (!file || disabled || active) return;
    setBusy(true); setError(null); setPreview(null); setReplaceConfirmed(false);
    try {
      if (!await onBeforeStart()) throw new Error("현재 레슨을 저장한 뒤 다시 시도해 주세요.");
      const next = await post<Preview>("/packages/server/preview", { fileName: file });
      requestId.current = crypto.randomUUID();
      setPreview(next); setPolicy("skip"); setPolicies({});
    } catch (problem) { setError(message(problem)); }
    finally { setBusy(false); }
  }

  async function execute(seed: Job) {
    stop.current = null; setRunning(true); setError(null);
    let current = seed;
    try {
      while (current.status === "running" && !stop.current && mounted.current) {
        current = await post<Job>(`/packages/server/${current.id}/step`, { cursor: current.cursor });
        receiveJob(current);
      }
      if ((stop.current || !mounted.current) && current.status === "running") {
        current = await post<Job>(`/packages/server/${current.id}/control`, { action: stop.current ?? "pause" });
        receiveJob(current);
      }
      if (mounted.current && current.status === "completed" && current.result) await onComplete(current.result);
    } catch (problem) {
      if (mounted.current) {
        setError(message(problem));
        await refresh().catch(() => undefined);
      }
    } finally { if (mounted.current) setRunning(false); }
  }

  async function start() {
    if (!preview || (replaces && !replaceConfirmed)) return;
    setBusy(true); setError(null);
    try {
      if (!await onBeforeStart()) throw new Error("현재 레슨을 저장한 뒤 다시 시도해 주세요.");
      const next = await post<Job>("/packages/server/start", {
        fileName: preview.fileName, fingerprint: preview.fingerprint, requestId: requestId.current,
        options: { lessonConflictPolicy: policy, lessonConflictPolicyBySourceId: policies },
      });
      receiveJob(next); setPreview(null);
      await execute(next);
    } catch (problem) { setError(message(problem)); await refresh().catch(() => undefined); }
    finally { if (mounted.current) setBusy(false); }
  }

  async function resume() {
    if (!job) return;
    setBusy(true); setError(null);
    try {
      if (!await onBeforeStart()) throw new Error("현재 레슨을 저장한 뒤 다시 시도해 주세요.");
      const next = await post<Job>(`/packages/server/${job.id}/control`, { action: "resume" });
      receiveJob(next); await execute(next);
    } catch (problem) { setError(message(problem)); }
    finally { if (mounted.current) setBusy(false); }
  }

  async function cancel() {
    if (!job || !window.confirm("가져오기를 중지할까요? 이미 등록된 본문과 미디어는 유지됩니다.")) return;
    if (running) { stop.current = "cancel"; return; }
    setBusy(true); setError(null);
    try { receiveJob(await post<Job>(`/packages/server/${job.id}/control`, { action: "cancel" })); }
    catch (problem) { setError(message(problem)); }
    finally { setBusy(false); }
  }

  return <section className="min-w-0 space-y-4 border-b border-slate-200 pb-6" aria-label="서버 ZIP 가져오기">
    <div className="flex items-center justify-between gap-3">
      <h3 className="flex items-center gap-2 text-base font-semibold text-slate-950"><FolderOpen className="size-5 shrink-0" aria-hidden="true" />서버 ZIP 가져오기</h3>
      <button className={iconClass} type="button" title="서버 파일 새로고침" aria-label="서버 파일 새로고침" disabled={busy || running} onClick={() => { setPreview(null); setError(null); void refresh().catch(problem => setError(message(problem))); }}><RefreshCw className="size-4" aria-hidden="true" /></button>
    </div>
    <div className="space-y-1">
      <div className="text-xs font-semibold text-slate-600">FTP 업로드 폴더</div>
      <div className="flex items-start gap-2"><code className="min-w-0 flex-1 break-all text-xs text-slate-700">{info?.directory || "READCONTENTS_LIBRARY_IMPORT_DIR 설정 필요"}</code>
        <button className={iconClass} type="button" disabled={!info?.directory} title={copied ? "복사됨" : "폴더 경로 복사"} aria-label="폴더 경로 복사" onClick={() => { void navigator.clipboard.writeText(info?.directory ?? "").then(() => setCopied(true)).catch(() => setError("경로를 복사하지 못했습니다.")); }}><Copy className="size-4" aria-hidden="true" /></button>
      </div>
    </div>
    {info?.error ? <p role="alert" className="break-words text-sm text-red-700">{info.error}</p> : null}
    {error ? <p role="alert" className="break-words text-sm text-red-700">{error}</p> : null}
    <div className="flex flex-wrap items-end gap-2">
      <label className="min-w-0 flex-1 space-y-1 text-xs font-semibold text-slate-600">서버 ZIP 파일
        <select className={`${inputClass} block w-full font-normal`} aria-label="서버 ZIP 파일" value={file} disabled={busy || running || active || disabled || !!info?.error} onChange={event => { setFile(event.target.value); setPreview(null); setReplaceConfirmed(false); }}>
          {!info?.files.length ? <option value="">ZIP 파일 없음</option> : info.files.map(item => <option key={item.name} value={item.name}>{item.name} ({(item.bytes / 1048576).toFixed(1)} MB)</option>)}
        </select>
      </label>
      <button type="button" className={buttonClass} disabled={!file || busy || running || active || disabled || !!info?.error} onClick={() => void inspectFile()}><Eye className="size-4" aria-hidden="true" />미리보기</button>
    </div>
    {preview ? <div className="space-y-3 border-t border-slate-200 pt-3">
      <p className="break-all text-sm font-semibold text-slate-950">{preview.fileName}</p>
      <p className="text-sm text-slate-600">레슨 {preview.preview.created.lessons + preview.preview.conflicts.length}개 · 이미지 {preview.preview.assetsDetected ?? 0}개 · 중복 {preview.preview.conflicts.length}개</p>
      <label className="block space-y-1 text-sm font-medium">중복 레슨 처리
        <select className={`${inputClass} block w-full`} aria-label="중복 레슨 처리" value={policy} disabled={busy} onChange={event => { setPolicy(event.target.value as Policy); setPolicies({}); setReplaceConfirmed(false); }}><Policies /></select>
      </label>
      {preview.preview.conflicts.length > 0 ? <details className="text-sm"><summary className="cursor-pointer font-medium">중복 레슨 {preview.preview.conflicts.length}개</summary>
        <div className="mt-2 divide-y divide-slate-200">{preview.preview.conflicts.map(conflict => <div key={conflict.incoming.id} className="grid grid-cols-1 gap-2 py-2 sm:grid-cols-[minmax(0,1fr)_9rem]">
          <span className="min-w-0 break-words">{conflict.path.join(" / ")}</span>
          <select className={inputClass} aria-label={`${conflict.incoming.title} 처리 방식`} disabled={busy} value={policies[conflict.incoming.id] ?? policy} onChange={event => { setPolicies(current => ({ ...current, [conflict.incoming.id]: event.target.value as Policy })); setReplaceConfirmed(false); }}><Policies /></select>
        </div>)}</div>
      </details> : null}
      {replaces ? <label className="flex items-start gap-2 text-sm text-red-800"><input className="mt-1 shrink-0" type="checkbox" checked={replaceConfirmed} disabled={busy} onChange={event => setReplaceConfirmed(event.target.checked)} /><span>선택한 기존 레슨의 본문과 상태를 덮어쓰는 데 동의합니다.</span></label> : null}
      <button type="button" className={`${buttonClass} !border-blue-600 !bg-blue-600 !text-white`} disabled={busy || disabled || active || (replaces && !replaceConfirmed)} onClick={() => void start()}><Play className="size-4" aria-hidden="true" />서버 ZIP 가져오기 시작</button>
    </div> : null}
    {job ? <div className="space-y-2 border-t border-slate-200 pt-3" aria-label="서버 가져오기 진행 상황">
      <div className="flex flex-wrap items-center justify-between gap-2"><strong className="min-w-0 break-all text-sm">{job.fileName}</strong><span role="status" className="text-sm font-medium">{running ? "처리 중" : labels[job.status]}</span></div>
      <progress className="block h-2 w-full accent-blue-600" aria-label="서버 가져오기 진행률" value={job.assets.done + job.content.done} max={job.assets.total + job.content.total || 1} />
      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600"><span>미디어 {job.assets.done} / {job.assets.total}</span><span>목차·레슨 {job.content.done} / {job.content.total}</span></div>
      <p className="min-h-5 break-all text-xs text-slate-500">{job.current}</p>
      {job.error ? <p role="alert" className="break-words text-sm text-red-700">{job.error}</p> : null}
      {job.status === "cancelled" ? <p className="text-sm text-slate-600">이미 등록된 항목은 유지되었습니다.</p> : null}
      <div className="flex flex-wrap gap-2">
        {job.canControl && active ? <>
          {running ? <button type="button" className={buttonClass} onClick={() => { stop.current = "pause"; }}><Pause className="size-4" aria-hidden="true" />일시 정지</button> : job.status !== "needs_review" ? <button type="button" className={buttonClass} disabled={busy || disabled} onClick={() => void resume()}><Play className="size-4" aria-hidden="true" />이어하기</button> : null}
          <button type="button" className={buttonClass} disabled={busy && !running} onClick={() => void cancel()}><Square className="size-4" aria-hidden="true" />가져오기 취소</button>
        </> : null}
        {job.status === "completed" && job.result ? <button type="button" className={buttonClass} onClick={() => void onComplete(job.result!)}><RefreshCw className="size-4" aria-hidden="true" />가져온 콘텐츠 확인</button> : null}
      </div>
    </div> : null}
  </section>;
}

function Policies() {
  return <><option value="skip">기존 레슨 유지</option><option value="create_new">새 레슨으로 추가</option><option value="replace">기존 레슨 대체</option></>;
}
function message(problem: unknown) { return problem instanceof Error ? problem.message : "서버 ZIP 가져오기에 실패했습니다."; }
