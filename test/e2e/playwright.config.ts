import { defineConfig, devices } from '@playwright/test';

const PORT = process.env.REVIEWQUEUE_TEST_PORT ?? '8080';
const BASE_URL = `http://localhost:${PORT}`;

export default defineConfig({
  testDir: './tests',
  fullyParallel: false, // tests share one DokuWiki instance and its queue state
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
