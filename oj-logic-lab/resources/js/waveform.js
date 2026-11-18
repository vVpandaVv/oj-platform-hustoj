export function waveRange(project, now) {
    const w = project.settings.wave || {}, span = Math.max(10, Math.min(1000000, Number(w.span) || 400));
    const end = w.start === null || w.start === undefined ? Math.max(span, now + 10) : Math.max(span, w.start + span);
    return { start: Math.max(0, end - span), end, span };
}
export function changeWave(project, now, action) {
    const old = { span: 400, start: null, signals: null, ...project.settings.wave }, range = waveRange(project, now);
    if (action === 'follow') return { ...old, start: null };
    if (action === 'back' || action === 'forward') return { ...old, start: Math.max(0, Math.min(Math.max(0, now + 10 - range.span), range.start + (action === 'back' ? -1 : 1) * Math.round(range.span / 2))) };
    const span = Math.max(10, Math.min(1000000, Math.round(range.span * (action === 'in' ? 0.5 : 2))));
    return { ...old, span, start: old.start === null ? null : Math.max(0, Math.round((range.start + range.end - span) / 2)) };
}
