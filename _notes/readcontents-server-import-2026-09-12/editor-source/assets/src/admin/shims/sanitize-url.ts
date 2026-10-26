const invalidProtocolRegex = /^([^\w]*)(javascript|data|vbscript)/im;
const htmlEntitiesRegex = /&#(\w+)(^\w|;)?/g;
const htmlCtrlEntityRegex = /&(newline|tab);/gi;
const ctrlCharactersRegex =
  /[\u0000-\u001f\u007f-\u009f\u2000-\u200d\ufeff]/gim;
const urlSchemeRegex = /^.+(:|&colon;)/gim;
const relativeFirstCharacters = ['.', '/'];

function isRelativeUrlWithoutProtocol(url: string) {
  return relativeFirstCharacters.includes(url[0]);
}

function decodeHtmlCharacters(value: string) {
  return value.replace(htmlEntitiesRegex, (_match, dec) =>
    String.fromCharCode(dec)
  );
}

export function sanitizeUrl(url?: string | null) {
  const sanitizedUrl = decodeHtmlCharacters(url || '')
    .replace(htmlCtrlEntityRegex, '')
    .replace(ctrlCharactersRegex, '')
    .trim();

  if (!sanitizedUrl) {
    return 'about:blank';
  }

  if (isRelativeUrlWithoutProtocol(sanitizedUrl)) {
    return sanitizedUrl;
  }

  const urlSchemeParseResults = sanitizedUrl.match(urlSchemeRegex);

  if (!urlSchemeParseResults) {
    return sanitizedUrl;
  }

  const urlScheme = urlSchemeParseResults[0];

  if (invalidProtocolRegex.test(urlScheme)) {
    return 'about:blank';
  }

  return sanitizedUrl;
}

export default { sanitizeUrl };
