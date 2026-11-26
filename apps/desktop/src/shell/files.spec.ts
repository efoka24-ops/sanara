import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { externalAllowed, serveFile } from './files';

let root: string;
beforeAll(() => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-web-'));
  root = path.join(dir, 'web');
  fs.mkdirSync(path.join(root, 'assets'), { recursive: true });
  fs.writeFileSync(path.join(root, 'index.html'), '<p>accueil</p>');
  fs.writeFileSync(path.join(root, 'assets', 'app.js'), 'console.log(1)');
  fs.writeFileSync(path.join(dir, 'secret.txt'), 'secret');
});
afterAll(() => fs.rmSync(path.dirname(root), { recursive: true, force: true }));

describe('fichiers de l’interface', () => {
  it('sert les fichiers avec leur type', async () => {
    const r = serveFile(root, '/assets/app.js');
    expect(r.headers.get('Content-Type')).toBe('text/javascript; charset=utf-8');
    expect(await r.text()).toBe('console.log(1)');
  });

  it('renvoie la page unique pour les routes de l’application, avec une politique stricte', async () => {
    for (const route of ['/', '/app/caisse', '/connexion']) {
      const r = serveFile(root, route);
      expect(await r.text()).toBe('<p>accueil</p>');
      expect(r.headers.get('Content-Security-Policy')).toContain("script-src 'self';");
    }
    expect(serveFile(root, '/assets/absent.js').status).toBe(404);
  });

  it('ne sort jamais du dossier de l’interface', async () => {
    for (const attempt of [
      '/../secret.txt',
      '/assets/..%2f..%2fsecret.txt',
      '/%2e%2e/secret.txt',
      '/..\\secret.txt',
      '/%00',
    ]) {
      const r = serveFile(root, attempt);
      expect(await r.text()).not.toContain('secret');
    }
  });

  it('n’ouvre à l’extérieur que des adresses sans danger', () => {
    expect(externalAllowed('https://www.openstreetmap.org/search?query=x')).toBe(true);
    expect(externalAllowed('tel:+237600000000')).toBe(true);
    expect(externalAllowed('mailto:a@b.cm')).toBe(true);
    expect(externalAllowed('file:///c:/windows/system32/calc.exe')).toBe(false);
    expect(externalAllowed('javascript:alert(1)')).toBe(false);
    expect(externalAllowed('ms-msdt:test')).toBe(false);
    expect(externalAllowed('pas une adresse')).toBe(false);
  });
});
