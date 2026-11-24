import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/** Base SQLite vierge + migrations + super admin avant chaque exécution. */
export default function globalSetup() {
  const dir = path.join(__dirname, '..', '..', '.e2e');
  fs.rmSync(dir, { recursive: true, force: true });
  fs.mkdirSync(dir, { recursive: true });
  execFileSync('php', [path.join('apps', 'api', 'bin', 'provision.php')], {
    cwd: path.join(__dirname, '..', '..'),
    env: {
      ...process.env,
      DB_DRIVER: 'sqlite',
      DB_DATABASE: path.join(dir, 'db.sqlite'),
      JWT_SECRET: 'e2e-secret-with-at-least-32-characters!!',
      SUPERADMIN_EMAIL: 'admin@e2e.test',
      SUPERADMIN_PASSWORD: 'Admin#E2e-Pass1',
    },
    stdio: 'inherit',
  });
  execFileSync('php', [path.join('apps', 'api', 'bin', 'seed-demo.php')], {
    cwd: path.join(__dirname, '..', '..'),
    env: {
      ...process.env,
      APP_ENV: 'testing',
      DB_DRIVER: 'sqlite',
      DB_DATABASE: path.join(dir, 'db.sqlite'),
      JWT_SECRET: 'e2e-secret-with-at-least-32-characters!!',
    },
    stdio: 'inherit',
  });
}
