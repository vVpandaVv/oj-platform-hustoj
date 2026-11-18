// WHATWG URL supplies the platform's IPv6 parser; arithmetic stays out of JSON.
export function ipv6(value) {
    if (typeof value !== 'string' || !/^[0-9a-f:.]+$/i.test(value) || !value.includes(':')) throw new Error('올바른 IPv6 주소가 필요합니다.');
    try { return new URL(`http://[${value}]/`).hostname.slice(1, -1); }
    catch { throw new Error('올바른 IPv6 주소가 필요합니다.'); }
}
function number(value) {
    const parts = ipv6(value).split('::'), left = parts[0] ? parts[0].split(':') : [], right = parts[1] ? parts[1].split(':') : [];
    return [...left, ...Array(8 - left.length - right.length).fill('0'), ...right].reduce((a, h) => (a << 16n) | BigInt('0x' + h), 0n);
}
function fromNumber(n) { return ipv6(Array.from({length: 8}, (_, i) => ((n >> BigInt(112 - i * 16)) & 65535n).toString(16)).join(':')); }
export function cidr6(value) {
    const [a, bits, extra] = String(value).split('/');
    if (extra !== undefined || !/^(0|[1-9]\d?|1[01]\d|12[0-8])$/.test(bits || '')) throw new Error('IPv6 주소/접두사(0~128)가 필요합니다.');
    const address = ipv6(a), prefix = Number(bits), host = BigInt(128 - prefix), network = fromNumber((number(address) >> host) << host);
    return {address, bits: prefix, network, value: `${address}/${prefix}`, dest: `${network}/${prefix}`};
}
export function contains6(network, address) { const c = cidr6(network), host = BigInt(128 - c.bits); return number(c.address) >> host === number(address) >> host; }
export function linkLocal(f) {
    const mac = f.mac.split(':').map(h => parseInt(h, 16)); mac[0] ^= 2;
    const bytes = [...mac.slice(0, 3), 255, 254, ...mac.slice(3)];
    return ipv6('fe80::' + Array.from({length: 4}, (_, i) => ((bytes[i * 2] << 8) | bytes[i * 2 + 1]).toString(16)).join(':'));
}
export const isLinkLocal = a => contains6('fe80::/10', a);
export function addresses6(f) { return f.ipv6?.length ? [...f.ipv6.map(a => cidr6(a).address), linkLocal(f)] : []; }
export function multicastMAC(a) { const n = number(a); return '33:33:' + [24, 16, 8, 0].map(s => ((n >> BigInt(s)) & 255n).toString(16).padStart(2, '0')).join(':'); }
export function solicitedNode(a) { return fromNumber(number('ff02::1:ff00:0') | (number(a) & 0xffffffn)); }
