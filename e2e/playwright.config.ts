import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 5_000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL ?? 'https://b2b-crm.local',
    ignoreHTTPSErrors: true,
    headless: true,
  },
});
