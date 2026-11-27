import { defineConfig } from '@playwright/test';
import base from './playwright.config';

/**
 * Application de bureau (Electron) contre l'API locale. Assembler d'abord :
 * pnpm --filter @sanara/desktop bundle
 */
export default defineConfig({
  ...base,
  testDir: 'tests/desktop',
  timeout: 180_000,
  // Le lanceur Electron plante rarement sous Windows à la réouverture : un second essai est permis.
  retries: 1,
  expect: { timeout: 15_000 },
  webServer: [base.webServer as Extract<typeof base.webServer, unknown[]>][0]!.slice(0, 1),
});
