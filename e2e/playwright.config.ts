import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 15_000,
  fullyParallel: true,
  workers: 4,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL ?? 'https://b2b-crm.local',
    ignoreHTTPSErrors: true,
    headless: true,
  },
});
