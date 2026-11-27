import { createRequire } from 'node:module';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { _electron, expect, test, type Page } from '@playwright/test';
import { DEMO_PW } from '../e2e/helpers';

/**
 * T280 : le poste doit afficher les écrans comme le site. Les mêmes fichiers web sont ouverts dans la
 * fenêtre Electron et dans un navigateur à la même taille, puis les captures sont comparées.
 * Seuls le cadre du poste (barre de titre, bandeau, barre d'état) diffère, et il n'est pas comparé.
 */
const root = path.resolve(__dirname, '..', '..');
const desktop = path.join(root, 'apps', 'desktop');
const web = path.join(desktop, 'dist', 'web');
const electron = createRequire(path.join(desktop, 'package.json'))('electron') as string;
const PORT = 8093;
// Les champs de date suivent la langue du navigateur : français, comme le poste.
test.use({ launchOptions: { args: ['--lang=fr-FR'] } });
const TYPES: Record<string, string> = {
  '.html': 'text/html',
  '.js': 'text/javascript',
  '.css': 'text/css',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
};

/** Sert le site construit et relaie /api vers l'API locale, comme l'hébergement. */
function site(): Promise<http.Server> {
  const server = http.createServer((req, res) => {
    const url = new URL(req.url ?? '/', `http://127.0.0.1:${PORT}`);
    if (url.pathname.startsWith('/api/')) {
      const upstream = http.request(
        {
          host: '127.0.0.1',
          port: 8081,
          path: url.pathname.slice(4) + url.search,
          method: req.method,
          headers: req.headers,
        },
        (r) => {
          res.writeHead(r.statusCode ?? 502, r.headers);
          r.pipe(res);
        },
      );
      upstream.on('error', () => res.writeHead(502).end());
      req.pipe(upstream);
      return;
    }
    const file = path.join(web, decodeURIComponent(url.pathname));
    const target =
      file.startsWith(web) && fs.existsSync(file) && fs.statSync(file).isFile()
        ? file
        : path.join(web, 'index.html');
    res.writeHead(200, {
      'Content-Type': TYPES[path.extname(target)] ?? 'application/octet-stream',
    });
    fs.createReadStream(target).pipe(res);
  });
  return new Promise((done) => server.listen(PORT, '127.0.0.1', () => done(server)));
}

async function signIn(page: Page) {
  await page.getByLabel('E-mail').fill('owner@demo.test');
  await page.getByLabel('Mot de passe').fill(DEMO_PW);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
}

/** Rien ne doit bouger pendant la capture (curseur, animations, polices). */
async function settle(page: Page) {
  await page.addStyleTag({
    content:
      '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }',
  });
  await page.evaluate(() => document.fonts.ready);
}

test('T280 : les écrans du poste sont ceux du site (demande d’accès, tableau de bord, catalogue, caisse)', async ({
  browser,
}, info) => {
  const server = await site();
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-visuel-'));
  const app = await _electron.launch({
    executablePath: process.env.SANARA_EXE ?? electron,
    args: process.env.SANARA_EXE ? [] : [path.join(desktop, 'dist', 'main.cjs')],
    env: {
      ...(process.env as Record<string, string>),
      SANARA_DATA: data,
      SANARA_API: 'http://127.0.0.1:8081',
    },
  });
  try {
    const poste = await app.firstWindow();
    await poste
      .getByLabel('Nom du poste')
      .fill(`V${Date.now().toString(36).slice(-6)}`.toUpperCase());
    await signIn(poste);

    // Le navigateur prend exactement la taille de la zone utile du poste.
    const size = await poste.evaluate(() => {
      const body = document.querySelector('.desktop-frame__body')!;
      return { width: body.clientWidth, height: body.clientHeight, scale: window.devicePixelRatio };
    });
    const context = await browser.newContext({
      viewport: { width: size.width, height: size.height },
      // Même densité que l'écran du poste (Windows à 125 % ou 150 %).
      deviceScaleFactor: size.scale,
      locale: 'fr-FR',
    });
    const site = await context.newPage();
    await site.goto(`http://127.0.0.1:${PORT}/connexion`);
    await signIn(site);

    const screens: {
      name: string;
      path: string;
      ready: (p: Page) => Promise<void>;
      part: string;
    }[] = [
      {
        name: 'B1-tableau-de-bord',
        path: '/app',
        ready: (p) => expect(p.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible(),
        part: '.layout--pharmacy',
      },
      {
        name: 'catalogue',
        path: '/app/produits',
        ready: (p) => expect(p.getByRole('heading', { name: 'Produits' }).first()).toBeVisible(),
        part: '.layout--pharmacy',
      },
      {
        name: 'C1-caisse',
        path: '/app/caisse',
        ready: (p) => expect(p.getByRole('button', { name: 'Ouvrir la caisse' })).toBeVisible(),
        part: '.layout--pharmacy',
      },
      {
        name: 'A1-demande-acces',
        path: '/inscription',
        ready: (p) => expect(p.getByLabel(/Nom de l’officine/)).toBeVisible(),
        part: '.card-form',
      },
    ];
    for (const screen of screens) {
      for (const p of [poste, site]) {
        // Même chemin pour les deux : navigation interne à l'application.
        await p.evaluate((to) => {
          window.history.pushState({}, '', to);
          window.dispatchEvent(new PopStateEvent('popstate'));
        }, screen.path);
        await screen.ready(p);
        await settle(p);
        await p.waitForTimeout(300);
      }
      // Même région des deux côtés : l'arrondi de la zone utile du poste peut changer la hauteur d'un pixel ou deux.
      const [a, b] = await Promise.all(
        [poste, site].map((p) => p.locator(screen.part).first().boundingBox()),
      );
      // Partie visible seulement : le poste fait défiler sa zone utile, le navigateur sa page.
      const bottom = (p: Page) =>
        p.evaluate(
          () =>
            document.querySelector('.desktop-frame__body')?.getBoundingClientRect().bottom ??
            window.innerHeight,
        );
      const [bottomA, bottomB] = await Promise.all([bottom(poste), bottom(site)]);
      const height = Math.floor(Math.min(a!.height, b!.height, bottomA - a!.y, bottomB - b!.y));
      const width = Math.floor(Math.min(a!.width, b!.width));
      const shot = (p: Page, box: { x: number; y: number }) =>
        p.screenshot({
          clip: { x: box.x, y: box.y, width, height },
          // Les champs de date sont dessinés par le système, dans sa langue : hors comparaison.
          mask: [p.locator('input[type=date]')],
        });
      const reference = await shot(poste, a!);
      const snapshot = info.snapshotPath(`${screen.name}.png`);
      fs.mkdirSync(path.dirname(snapshot), { recursive: true });
      fs.writeFileSync(snapshot, reference);
      // Le rendu du site est comparé à celui du poste ; un écart de rendu des polices est toléré.
      expect(await shot(site, b!), screen.name).toMatchSnapshot(`${screen.name}.png`, {
        // Le lissage des lettres diffère entre le Chromium d'Electron et celui du test (environ 2 %) ;
        // un écart de mise en page dépasse largement ce seuil (5 à 22 % mesurés).
        maxDiffPixelRatio: 0.03,
        threshold: 0.25,
      });
    }
    await context.close();
  } finally {
    await app.close().catch(() => undefined);
    server.close();
    fs.rmSync(data, { recursive: true, force: true });
  }
});
