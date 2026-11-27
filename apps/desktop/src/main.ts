/**
 * Application de bureau (T125, T126). L'interface web embarquée est servie sur app://sanara ; ses appels
 * /api vont à la passerelle locale, qui vend sans réseau et synchronise avec le serveur.
 */
import fs from 'node:fs';
import path from 'node:path';
import { BrowserWindow, app, dialog, protocol, shell } from 'electron';
import { ulid } from '@sanara/shared';
import { Gateway } from './gateway/gateway';
import { autoUpdater } from 'electron-updater';
import { Updater } from './updater/updater';
import { SqliteState, openLocalDatabase } from './local-db';
import { externalAllowed, serveFile } from './shell/files';

declare const __APP_VERSION__: string;

const ORIGIN = 'app://sanara';
const DEFAULT_SERVER = 'http://sanara.trugroup.cm/api';
const SYNC_EVERY_MS = 30_000;

protocol.registerSchemesAsPrivileged([
  { scheme: 'app', privileges: { standard: true, secure: true, supportFetchAPI: true } },
]);

// Les tests isolent leurs données ; en usage normal, elles vont dans le profil Windows.
if (process.env.SANARA_DATA) app.setPath('userData', process.env.SANARA_DATA);

/** Adresse de l'API : variable d'environnement, sinon config.json du profil, sinon la préproduction. */
function serverUrl(): string {
  if (process.env.SANARA_API) return process.env.SANARA_API;
  const file = path.join(app.getPath('userData'), 'config.json');
  try {
    const config = JSON.parse(fs.readFileSync(file, 'utf8')) as { serverUrl?: string };
    if (config.serverUrl) return config.serverUrl;
  } catch {
    // Premier lancement : le fichier est créé ci-dessous.
  }
  if (!fs.existsSync(file)) {
    fs.writeFileSync(file, JSON.stringify({ serverUrl: DEFAULT_SERVER }, null, 2));
  }
  return DEFAULT_SERVER;
}

function openGateway(): Gateway {
  const db = openLocalDatabase(path.join(app.getPath('userData'), 'sanara.db'), {
    migrations: path.join(__dirname, 'migrations'),
    nativeBinding: path.join(__dirname, 'native', 'better_sqlite3.node'),
  });
  const state = new SqliteState(db);
  let deviceId = state.get('device_id');
  if (!deviceId) {
    deviceId = ulid();
    state.set('device_id', deviceId);
  }
  app.on('will-quit', () => db.close());
  return new Gateway({ db, deviceId, serverUrl: serverUrl(), version: __APP_VERSION__ });
}

function createWindow(): BrowserWindow {
  const window = new BrowserWindow({
    width: 1280,
    height: 800,
    show: false,
    icon: path.join(__dirname, 'icon.png'),
    minWidth: 1024,
    minHeight: 680,
    autoHideMenuBar: true,
    titleBarStyle: 'hidden',
    titleBarOverlay: { color: '#14201a', symbolColor: '#ffffff', height: 38 },
    webPreferences: {
      preload: path.join(__dirname, 'preload.cjs'),
      contextIsolation: true,
      sandbox: true,
      nodeIntegration: false,
    },
  });
  const leave = (url: string) => {
    if (externalAllowed(url)) void shell.openExternal(url);
  };
  window.webContents.on('will-navigate', (event, url) => {
    if (url.startsWith(`${ORIGIN}/`)) return;
    event.preventDefault();
    leave(url);
  });
  window.webContents.setWindowOpenHandler(({ url }) => {
    leave(url);
    return { action: 'deny' };
  });
  window.webContents.session.setPermissionRequestHandler((_contents, permission, allow) =>
    allow(permission === 'geolocation'),
  );
  window.once('ready-to-show', () => window.show());
  void window.loadURL(`${ORIGIN}/connexion`);
  return window;
}

function start(): void {
  const gateway = openGateway();
  const web = path.join(__dirname, 'web');
  protocol.handle('app', (request) => {
    const url = new URL(request.url);
    if (url.host !== 'sanara') return new Response('Introuvable', { status: 404 });
    return url.pathname.startsWith('/api/')
      ? gateway.handle(request)
      : serveFile(web, url.pathname);
  });
  const window = createWindow();
  const printers = () => {
    if (window.isDestroyed()) return;
    void window.webContents
      .getPrintersAsync()
      .then((list) => gateway.setPrinters(list.length))
      .catch(() => undefined);
  };
  window.webContents.once('did-finish-load', printers);
  const watch = setInterval(printers, 60_000);
  app.on('will-quit', () => clearInterval(watch));
  // T128 : téléchargées en arrière-plan, installées hors caisse ouverte.
  const updater = new Updater({
    updater: autoUpdater,
    serverUrl: serverUrl(),
    packaged: app.isPackaged,
    tillOpen: () => gateway.tillOpen(),
    log: (message) => console.error(message),
  });
  gateway.setUpdates(updater);
  updater.start();
  app.on('will-quit', () => updater.stop());
  const timer = setInterval(() => void gateway.syncNow(), SYNC_EVERY_MS);
  app.on('will-quit', () => clearInterval(timer));
}

// Une seule instance : deux processus ne doivent pas écrire dans la même caisse.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    const [window] = BrowserWindow.getAllWindows();
    if (window) {
      if (window.isMinimized()) window.restore();
      window.focus();
    }
  });
  app
    .whenReady()
    .then(start)
    .catch((e: unknown) => {
      dialog.showErrorBox(
        'Sanara ne peut pas démarrer',
        e instanceof Error ? e.message : String(e),
      );
      app.quit();
    });
  app.on('window-all-closed', () => app.quit());
}
