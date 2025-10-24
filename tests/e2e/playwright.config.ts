import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080';
const configDir = dirname(fileURLToPath(import.meta.url));

const headless = (() => {
  const flag = process.env.PLAYWRIGHT_HEADLESS;
  if (flag === '0' || flag?.toLowerCase() === 'false') return false;
  if (flag === '1' || flag?.toLowerCase() === 'true') return true;
  return true;
})();

const config = {
  testDir: configDir,
  timeout: 120_000,
  expect: { timeout: 10_000 },
  retries: process.env.CI ? 1 : 0,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['list']] : 'list',
  use: {
    baseURL,
    trace: 'on-first-retry',
    headless: !!process.env.CI || headless,
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,
  },
  workers: process.env.CI ? 1 : undefined,
};

export default config;
