// Assemble l'application dans dist/ : processus principal, interface web, migrations et binaire SQLite
// compilé pour Electron (les tests gardent celui de Node dans node_modules).
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repo = path.resolve(root, '..', '..');
const dist = path.join(root, 'dist');
const require = createRequire(path.join(root, 'package.json'));

const electronVersion = require('electron/package.json').version;
const sqliteVersion = require('better-sqlite3/package.json').version;

/** Télécharge une fois le binaire publié pour cette version d'Electron (curl reprend les coupures). */
function nativeBinding() {
  // Numéro d'ABI donné par Electron lui-même.
  const abi = execFileSync(require('electron'), ['-p', 'process.versions.modules'], {
    env: { ...process.env, ELECTRON_RUN_AS_NODE: '1' },
    encoding: 'utf8',
  }).trim();
  const name = `better-sqlite3-v${sqliteVersion}-electron-v${abi}-${process.platform}-${process.arch}`;
  const cache = path.join(root, '.native', name);
  const file = path.join(cache, 'better_sqlite3.node');
  if (fs.existsSync(file)) return file;
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-native-'));
  const url = `https://github.com/WiseLibs/better-sqlite3/releases/download/v${sqliteVersion}/${name}.tar.gz`;
  execFileSync(
    'curl',
    ['-sSfL', '--retry', '5', '--retry-all-errors', '-o', path.join(work, 'sqlite.tar.gz'), url],
    { stdio: 'inherit' },
  );
  // Chemin relatif : le tar de Windows et celui de Git comprennent mal les lettres de lecteur.
  execFileSync('tar', ['-xzf', 'sqlite.tar.gz'], { cwd: work, stdio: 'inherit' });
  fs.mkdirSync(cache, { recursive: true });
  fs.copyFileSync(path.join(work, 'build', 'Release', 'better_sqlite3.node'), file);
  fs.rmSync(work, { recursive: true, force: true });
  return file;
}

try {
  fs.rmSync(dist, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
} catch (error) {
  // Windows verrouille les fichiers d'une application encore ouverte.
  console.error('Fermez Sanara (et ses processus electron.exe) avant de reconstruire.');
  throw error;
}
fs.mkdirSync(path.join(dist, 'native'), { recursive: true });

if (!process.argv.includes('--skip-web')) {
  execFileSync('corepack', ['pnpm', '--filter', '@sanara/web', 'build'], {
    cwd: repo,
    stdio: 'inherit',
    shell: process.platform === 'win32',
  });
}
fs.cpSync(path.join(repo, 'apps', 'web', 'dist'), path.join(dist, 'web'), { recursive: true });
fs.cpSync(path.join(root, 'src', 'local-db', 'migrations'), path.join(dist, 'migrations'), {
  recursive: true,
});
fs.copyFileSync(nativeBinding(), path.join(dist, 'native', 'better_sqlite3.node'));
fs.copyFileSync(path.join(root, 'build', 'icon.png'), path.join(dist, 'icon.png'));

const common = {
  bundle: true,
  platform: 'node',
  format: 'cjs',
  target: 'node22',
  external: ['electron'],
  define: { __APP_VERSION__: JSON.stringify(require('./package.json').version) },
  // Les chemins fondés sur import.meta sont fournis explicitement par main.ts.
  logOverride: { 'empty-import-meta': 'silent' },
};
await build({
  ...common,
  entryPoints: [path.join(root, 'src', 'main.ts')],
  outfile: path.join(dist, 'main.cjs'),
});
await build({
  ...common,
  entryPoints: [path.join(root, 'src', 'preload.ts')],
  outfile: path.join(dist, 'preload.cjs'),
});

fs.writeFileSync(
  path.join(dist, 'package.json'),
  JSON.stringify(
    {
      name: 'sanara',
      productName: 'Sanara',
      description: 'Sanara, gestion de pharmacie',
      author: 'Sanara',
      version: require('./package.json').version,
      main: 'main.cjs',
    },
    null,
    2,
  ),
);
console.log(`Application assemblée dans ${dist}`);
