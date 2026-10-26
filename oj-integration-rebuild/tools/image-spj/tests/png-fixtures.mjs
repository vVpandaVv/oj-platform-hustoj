import { deflateSync } from 'node:zlib';

export const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
export function crc32(bytes) {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}
export function chunk(type, bytes = Buffer.alloc(0)) {
  const name = Buffer.from(type);
  const result = Buffer.alloc(bytes.length + 12);
  result.writeUInt32BE(bytes.length, 0);
  name.copy(result, 4);
  bytes.copy(result, 8);
  result.writeUInt32BE(crc32(Buffer.concat([name, bytes])), bytes.length + 8);
  return result;
}
export function pixels(width, height, color = [255, 255, 255, 255]) {
  const result = Buffer.alloc(width * height * 4);
  for (let i = 0; i < result.length; i += 4) color.forEach((value, c) => { result[i + c] = value; });
  return result;
}
export function fillRect(data, width, x, y, w, h, color) {
  for (let row = y; row < y + h; row++)
    for (let col = x; col < x + w; col++) color.forEach((value, c) => { data[(row * width + col) * 4 + c] = value; });
}
export function png(width, height, data, { level = 6, metadata = false, type = 6 } = {}) {
  const channels = type === 6 ? 4 : (type === 2 ? 3 : 1);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;
  ihdr[9] = type;
  const raw = Buffer.alloc((width * channels + 1) * height);
  for (let y = 0; y < height; y++)
    for (let x = 0; x < width; x++)
      for (let c = 0; c < channels; c++) raw[y * (width * channels + 1) + 1 + x * channels + c] = data[(y * width + x) * 4 + c];
  return Buffer.concat([signature, chunk('IHDR', ihdr),
    ...(metadata ? [chunk('tEXt', Buffer.from('Comment\0Different PNG metadata'))] : []),
    chunk('IDAT', deflateSync(raw, { level })), chunk('IEND')]);
}
export function envelope(data) { return Buffer.from(`OJ_IMAGE_PNG_BASE64_V1\n${data.toString('base64')}\n`); }
export function drawing(size = 64) {
  const data = pixels(size, size);
  const edge = Math.floor(size / 4), length = Math.floor(size / 2);
  fillRect(data, size, edge, edge, length, 2, [0, 0, 0, 255]);
  fillRect(data, size, edge, edge + length - 2, length, 2, [0, 0, 0, 255]);
  fillRect(data, size, edge, edge, 2, length, [0, 0, 0, 255]);
  fillRect(data, size, edge + length - 2, edge, 2, length, [0, 0, 0, 255]);
  return data;
}
