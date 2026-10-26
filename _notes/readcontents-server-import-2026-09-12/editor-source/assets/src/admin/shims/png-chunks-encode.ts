import { pngCrc32 } from './png-crc';

type PngChunk = {
  data: Uint8Array;
  name: string;
};

const PNG_SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

function writeUint32(output: Uint8Array, offset: number, value: number) {
  output[offset] = (value >>> 24) & 0xff;
  output[offset + 1] = (value >>> 16) & 0xff;
  output[offset + 2] = (value >>> 8) & 0xff;
  output[offset + 3] = value & 0xff;
}

export default function encodeChunks(chunks: PngChunk[]) {
  const totalSize =
    PNG_SIGNATURE.length +
    chunks.reduce((size, chunk) => size + chunk.data.length + 12, 0);
  const output = new Uint8Array(totalSize);
  let offset = 0;

  output.set(PNG_SIGNATURE, offset);
  offset += PNG_SIGNATURE.length;

  chunks.forEach((chunk) => {
    const nameBytes = new Uint8Array(4);

    for (let index = 0; index < 4; index += 1) {
      nameBytes[index] = chunk.name.charCodeAt(index);
    }

    writeUint32(output, offset, chunk.data.length);
    offset += 4;

    output.set(nameBytes, offset);
    offset += 4;

    output.set(chunk.data, offset);
    offset += chunk.data.length;

    const crcBytes = new Uint8Array(nameBytes.length + chunk.data.length);
    crcBytes.set(nameBytes, 0);
    crcBytes.set(chunk.data, nameBytes.length);
    writeUint32(output, offset, pngCrc32(crcBytes));
    offset += 4;
  });

  return output;
}
