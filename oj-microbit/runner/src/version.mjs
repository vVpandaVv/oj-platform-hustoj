import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const require = createRequire(import.meta.url);
export const target = JSON.parse(readFileSync(require.resolve('pxt-microbit/built/target.json'), 'utf8'));
export const runtimeVersion = `pxt-microbit/${target.versions.target};pxt-core/${target.versions.pxt};oj-gpio/3`;
