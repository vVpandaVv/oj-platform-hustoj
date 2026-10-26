import { pathToFileURL } from 'node:url';

export async function loadPlaywright() {
  try {
    return await import('playwright');
  } catch (packageError) {
    const modulePath = String(process.env.OJAI_PLAYWRIGHT_MODULE || '').trim();
    if (!modulePath) {
      throw new Error(
        'Playwright is unavailable. Install playwright or set OJAI_PLAYWRIGHT_MODULE. Original error: '
        + packageError.message
      );
    }
    try {
      return await import(pathToFileURL(modulePath).href);
    } catch (moduleError) {
      throw new Error(
        'Playwright could not be loaded from OJAI_PLAYWRIGHT_MODULE: '
        + moduleError.message
      );
    }
  }
}

export function browserLaunchOptions() {
  const executablePath = String(process.env.OJAI_BROWSER_EXECUTABLE || '').trim();
  return executablePath ? { executablePath } : {};
}