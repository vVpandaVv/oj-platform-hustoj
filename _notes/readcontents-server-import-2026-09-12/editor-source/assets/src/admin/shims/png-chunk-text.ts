type PngTextChunk = {
  data: Uint8Array;
  name: string;
};

function assertLatin1(value: string, label: string) {
  if (!/^[\x00-\xff]+$/.test(value)) {
    throw new Error(
      `Only Latin-1 characters are permitted in PNG tEXt ${label}.`
    );
  }
}

export function encode(keyword: string, content: string): PngTextChunk {
  const safeKeyword = String(keyword);
  const safeContent = String(content);

  assertLatin1(safeKeyword, 'keywords');
  assertLatin1(safeContent, 'content');

  if (safeKeyword.length >= 80) {
    throw new Error(
      `Keyword "${safeKeyword}" is longer than the 79-character PNG limit.`
    );
  }

  const output = new Uint8Array(safeKeyword.length + safeContent.length + 1);
  let offset = 0;

  for (let index = 0; index < safeKeyword.length; index += 1) {
    const code = safeKeyword.charCodeAt(index);

    if (code === 0) {
      throw new Error('0x00 is not permitted in PNG tEXt keywords.');
    }

    output[offset] = code;
    offset += 1;
  }

  output[offset] = 0;
  offset += 1;

  for (let index = 0; index < safeContent.length; index += 1) {
    const code = safeContent.charCodeAt(index);

    if (code === 0) {
      throw new Error('0x00 is not permitted in PNG tEXt content.');
    }

    output[offset] = code;
    offset += 1;
  }

  return { name: 'tEXt', data: output };
}

export function decode(input: PngTextChunk | Uint8Array | number[]) {
  const data =
    input && !Array.isArray(input) && 'data' in input
      ? input.data
      : new Uint8Array(input as Uint8Array | number[]);
  let keyword = '';
  let text = '';
  let naming = true;

  for (let index = 0; index < data.length; index += 1) {
    const code = data[index];

    if (naming) {
      if (code) {
        keyword += String.fromCharCode(code);
      } else {
        naming = false;
      }
    } else if (code) {
      text += String.fromCharCode(code);
    } else {
      throw new Error('Invalid NULL character found in PNG tEXt content.');
    }
  }

  return { keyword, text };
}

export default { encode, decode };
