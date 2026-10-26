import { readFileSync, writeFileSync, statSync } from 'node:fs';

const [input, output, mode = 'text'] = process.argv.slice(2);
if (!input || !output || !['text', 'binary'].includes(mode)) {
  console.error('Usage: node prepare-reference.mjs reference.png case01.out [text|binary]');
  process.exit(2);
}
const inputStat = statSync(input);
if (!inputStat.isFile() || inputStat.size > 8 * 1024 * 1024) throw Error('Use a regular PNG file of at most 8 MiB.');
const png = readFileSync(input);
if (png.length < 33 || png.length > 8 * 1024 * 1024
    || !png.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))) {
  throw Error('An actual PNG file of at most 8 MiB is required. Renaming JPEG is not conversion.');
}
const width = png.readUInt32BE(16), height = png.readUInt32BE(20);
if (!width || !height || width > 2048 || height > 2048 || png[24] > 8) {
  throw Error('Use an 8-bit PNG no larger than 2048 x 2048.');
}
const data = mode === 'binary' ? png
  : `OJ_IMAGE_PNG_BASE64_V1\n${png.toString('base64').match(/.{1,120}/g).join('\n')}\n`;
writeFileSync(output, data, { flag: 'wx' });
console.log(`Created ${output} (${mode}, ${width} x ${height}). Verify with: spj --check-reference <file>`);
