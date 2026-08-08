import { defineConfig, devices } from '@playwright/test';

const PORT = process.env.REVIEWQUEUE_TEST_PORT ?? '8080';
const BASE_URL = `http://localhost:${PORT}`;

export default defineConfig({
  testDir: './tests',
  // Every test runs against one shared DokuWiki: one review queue, and one
  // login session per user (the storageState is reused across specs). DokuWiki
  // carries msg() notices through that session, so a concurrently running spec
  // can consume the notice another spec is asserting on. Serialising removes a
  // whole class of flakiness for a suite that only takes seconds anyway.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  timeout: 30_000,
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /.*\.setup\.ts/,
    },
    {
      name: 'martin',
      testMatch: /.*\.martin\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], storageState: '.auth/martin-storage.json' },
      dependencies: ['setup'],
    },
    {
      name: 'kail',
      testMatch: /.*\.kail\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], storageState: '.auth/kail-storage.json' },
      dependencies: ['setup'],
    },
    {
      name: 'anonymous',
      testMatch: /.*\.anon\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'api',
      testMatch: /.*\.api\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
