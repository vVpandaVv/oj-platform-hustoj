type PngChunk = {
  data: Uint8Array;
  name: string;
};

const PNG_SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

function readUint32(data: Uint8Array, offset: number) {
  return (
    data[offset] * 0x1000000 +
    ((data[offset + 1] << 16) | (data[offset + 2] << 8) | data[offset + 3])
  );
}

function toBytes(data: Uint8Array | ArrayBuffer | number[]) {
  if (data instanceof Uint8Array) {
    return data;
  }

  if (data instanceof ArrayBuffer) {
    return new Uint8Array(data);
  }

  return new Uint8Array(data);
}

export default function extractChunks(input: Uint8Array | ArrayBuffer | number[]) {
  const data = toBytes(input);

  PNG_SIGNATURE.forEach((byte, index) => {
    if (data[index] !== byte) {
      throw new Error('Invalid .png file header');
    }
  });

  const chunks: PngChunk[] = [];
  let offset = PNG_SIGNATURE.length;
  let ended = false;

  while (offset < data.length) {
    const length = readUint32(data, offset);
    offset += 4;

    const name = String.fromCharCode(
      data[offset],
      data[offset + 1],
      data[offset + 2],
      data[offset + 3]
    );
    offset += 4;

    const chunkData = data.slice(offset, offset + length);
    offset += length + 4;

    chunks.push({ name, data: chunkData });

    if (name === 'IEND') {
      ended = true;
      break;
    }
  }

  if (!ended) {
    throw new Error('.png file ended prematurely: no IEND header was found');
  }

  return chunks;
}
