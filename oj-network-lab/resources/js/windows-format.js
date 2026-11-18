export const winText = value => value === null || value === undefined ? '' : typeof value === 'boolean' ? value ? 'True' : 'False' : Array.isArray(value) ? `{${value.map(winText).join(', ')}}` : typeof value === 'object' ? value.IPAddress || value.NextHop || JSON.stringify(value) : String(value);
export function winProperty(row, name) {
    if (!row || typeof row !== 'object' || Array.isArray(row)) throw new Error('속성이 있는 객체 입력이 필요합니다.');
    const key = Object.keys(row).find(k => k.toLowerCase() === String(name).toLowerCase());
    if (!key || ['__proto__', 'constructor', 'prototype'].includes(key.toLowerCase())) throw new Error(`객체 속성이 없습니다: ${name}`);
    return key;
}
export function formatWindows(rows, list = false, properties = null) {
    if (!rows.length) return '';
    if (rows.some(r => r === null || typeof r !== 'object' || Array.isArray(r))) return rows.map(winText).join('\n');
    const keys = properties || Object.keys(rows[0]);
    if (list) return rows.map(r => keys.map(k => `${k.padEnd(Math.min(30, Math.max(...keys.map(s => s.length))))} : ${winText(r[k])}`).join('\n')).join('\n\n');
    const widths = keys.map(k => Math.min(64, Math.max(k.length, ...rows.map(r => winText(r[k]).length))));
    return [keys.map((k, i) => k.padEnd(widths[i])).join('  '), keys.map((k, i) => '-'.repeat(widths[i])).join('  '), ...rows.map(r => keys.map((k, i) => winText(r[k]).padEnd(widths[i])).join('  ').trimEnd())].join('\n');
}
