import { defineConfig } from '@playwright/test';

/** Tests contre la préproduction (données fictives uniquement tant que le site est en HTTP). */
export default defineConfig({
  testDir: 'tests/staging',
  fullyParallel: false,
  workers: 1,
  timeout: 180_000,
  // L'hébergement mutualisé répond en 1 à 5 s : les attentes locales (5 s) sont trop courtes ici.
  expect: { timeout: 30_000 },
  reporter: [['list']],
  use: {
    baseURL: process.env.STAGING_URL ?? 'http://sanara.trugroup.cm',
    trace: 'retain-on-failure',
    locale: 'fr-FR',
  },
});
