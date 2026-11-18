// Shared vector symbols for the palette, editor and standalone schematics.
// Coordinates are local to the icon; device bounds and electrical pins stay unchanged.
import { spec, height as deviceHeight, isSequential, isFF, nodeWidth } from './model.js?v=1.0.2';
const andBody = 'M18 8H34A16 16 0 0 1 34 40H18Z';
const orBody = 'M16 8Q40 8 54 24Q40 40 16 40Q28 24 16 8Z';
const triangle = 'M18 8L50 24L18 40Z';
const shapes = Object.freeze({
    AND: { body: andBody, inputs: 'M4 16H18M4 32H18', tip: 50 },
    NAND: { body: andBody, inputs: 'M4 16H18M4 32H18', tip: 50, inverted: true },
    OR: { body: orBody, inputs: 'M4 16H20.5M4 32H20.5', tip: 54 },
    NOR: { body: orBody, inputs: 'M4 16H20.5M4 32H20.5', tip: 54, inverted: true },
    XOR: { body: orBody, inputs: 'M4 16H20.5M4 32H20.5', tip: 54, exclusive: true },
    XNOR: { body: orBody, inputs: 'M4 16H20.5M4 32H20.5', tip: 54, exclusive: true, inverted: true },
    NOT: { body: triangle, inputs: 'M4 24H18', tip: 50, inverted: true },
    BUF: { body: triangle, inputs: 'M4 24H18', tip: 50 },
    TRI: { body: triangle, inputs: 'M4 24H18M34 1V16', tip: 50 },
});

export function gateSymbol(type, { x = 0, y = 0, width = 48, height = 32 } = {}) {
    const s = shapes[type]; if (!s) return '';
    const bubble = s.inverted ? `<circle cx="${s.tip + 4}" cy="24" r="4"/>` : '';
    const extra = s.exclusive ? '<path d="M10 8Q22 24 10 40"/>' : '';
    return `<svg class="ll-gate-drawing" data-gate-symbol="${type}" x="${x}" y="${y}" width="${width}" height="${height}" viewBox="0 0 72 48" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="${s.body}"/><path d="${s.inputs}M${s.tip + (s.inverted ? 8 : 0)} 24H68"/>${extra}${bubble}</g></svg>`;
}

// Sequential symbols use the existing electrical pin rows (24 px apart).
// RST remains active high. Only a falling-edge clock has an input bubble.
export function sequentialSymbol(n, { x = 0, y = 0, compact = false, ideal = false } = {}) {
    if (!isSequential(n.type)) return '';
    const edge = isFF(n.type) ? !ideal && n.config?.edge === 'falling' ? 'falling' : 'rising' : 'level';
    const clocked = edge !== 'level', falling = edge === 'falling';
    const inputs = spec(n).inputs, h = deviceHeight(n);
    const text = (value, atX, atY, anchor = 'start', size = 10) => `<text x="${atX}" y="${atY}" text-anchor="${anchor}" font-size="${size}" font-family="sans-serif" fill="currentColor" stroke="none">${value}</text>`;
    let drawing;
    if (compact) {
        const data = n.type === 'JKFF' ? 'J' : ['SRFF', 'SRLATCH'].includes(n.type) ? 'S' : n.type === 'TFF' ? 'T' : 'D';
        const multiple = ['JKFF', 'SRFF'].includes(n.type), cy = multiple ? 36 : 34;
        const control = clocked ? `<path d="M18 ${cy - 6}L25 ${cy}L18 ${cy + 6}"/>${falling ? `<path d="M3 ${cy}H7M15 ${cy}H18"/><circle cx="11" cy="${cy}" r="4"/>` : `<path d="M3 ${cy}H18"/>`}` : `<path d="M3 34H18"/>${text(n.type === 'DLATCH' ? 'EN' : 'R', 23, 37, 'start', 8)}`;
        const second = multiple ? `<path d="M3 24H18"/>${text(n.type === 'JKFF' ? 'K' : 'R', 24, 28)}` : '';
        drawing = `<rect x="18" y="4" width="36" height="40"/><path d="M3 14H18M54 14H69M62 34H69"/><circle cx="58" cy="34" r="4"/>${control}${second}${text(data, 24, 18)}${text('Q', 49, 18, 'end')}${text('Q', 49, 38, 'end')}<path d="M42 28H49"/>`;
    } else {
        drawing = `<rect x="28" y="12" width="64" height="${h - 24}"/>`;
        for (const [i, p] of inputs.entries()) {
            const at = (i + 1) * 24;
            if (p === 'CLK') drawing += `${falling ? `<path d="M0 ${at}H18M26 ${at}H28"/><circle cx="22" cy="${at}" r="4"/>` : `<path d="M0 ${at}H28"/>`}<path d="M28 ${at - 6}L36 ${at}L28 ${at + 6}"/>${text('CLK', 40, at + 3, 'start', 8)}`;
            else drawing += `<path d="M0 ${at}H28"/>${text(p, 35, at + 4, 'start', p === 'RST' ? 8 : 10)}`;
        }
        drawing += `<path d="M92 24H120M100 48H120"/><circle cx="96" cy="48" r="4"/>${text('Q', 85, 28, 'end')}${text('Q', 85, 52, 'end')}<path d="M78 40H85"/>`;
        if (n.type === 'WREG') drawing += text(`${nodeWidth(n)}b`, 85, h - 20, 'end', 8);
    }
    return `<svg class="ll-sequential-drawing" data-sequential-symbol="${n.type}" data-clock-edge="${edge}" x="${x}" y="${y}" width="${compact ? 48 : 120}" height="${compact ? 32 : h}" viewBox="0 0 ${compact ? '72 48' : `120 ${h}`}" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${drawing}</g></svg>`;
}
