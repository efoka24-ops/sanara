import { defineConfig } from '@playwright/test';
import path from 'node:path';

const root = __dirname;
const e2e = path.join(root, '.e2e');
export const E2E = {
  dir: e2e,
  mail: path.join(e2e, 'mail'),
  cronKey: 'e2e-cron-key',
  admin: { email: 'admin@e2e.test', password: 'Admin#E2e-Pass1' },
};

const apiEnv = {
  APP_ENV: 'testing',
  DB_DRIVER: 'sqlite',
  DB_DATABASE: path.join(e2e, 'db.sqlite'),
  JWT_SECRET: 'e2e-secret-with-at-least-32-characters!!',
  STORAGE_KEY: Buffer.alloc(32, 7).toString('base64'),
  STORAGE_DIR: path.join(e2e, 'storage'),
  MAIL_DIR: E2E.mail,
  CRON_KEY: E2E.cronKey,
  APP_URL: 'http://127.0.0.1:5173',
  SUPERADMIN_EMAIL: E2E.admin.email,
  SUPERADMIN_PASSWORD: E2E.admin.password,
};

export default defineConfig({
  testDir: 'tests/e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  reporter: [['list']],
  globalSetup: './tests/e2e/global-setup.ts',
  use: { baseURL: 'http://127.0.0.1:5173', trace: 'retain-on-failure', locale: 'fr-FR' },
  webServer: [
    {
      command: 'php -S 127.0.0.1:8081 -t apps/api/public apps/api/public/index.php',
      url: 'http://127.0.0.1:8081/health',
      env: apiEnv,
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      command: 'corepack pnpm --filter @sanara/web dev --host 127.0.0.1',
      url: 'http://127.0.0.1:5173',
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
});
