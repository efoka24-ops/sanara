import { app, BrowserWindow, ipcMain, shell } from 'electron';
import { fileURLToPath } from 'node:url';

export const APP_NAME = 'Sanara Desktop';

const IPC_CHANNELS = {
  getVersion: 'app:getVersion',
  ping: 'app:ping',
} as const;

const isAllowedNavigation = (targetUrl: string): boolean => {
  if (targetUrl === 'about:blank') return true;
  return targetUrl.startsWith('file://');
};

function registerIpcHandlers(): void {
  ipcMain.handle(IPC_CHANNELS.getVersion, () => app.getVersion());
  ipcMain.handle(IPC_CHANNELS.ping, (_event, message: string = 'ping') => `pong:${message}`);
}

function createMainWindow(): BrowserWindow {
  const window = new BrowserWindow({
    width: 1200,
    height: 780,
    show: false,
    webPreferences: {
      preload: fileURLToPath(new URL('./preload.js', import.meta.url)),
      contextIsolation: true,
      sandbox: true,
      nodeIntegration: false,
    },
  });

  window.webContents.on('will-navigate', (event, targetUrl) => {
    if (!isAllowedNavigation(targetUrl)) {
      event.preventDefault();
      void shell.openExternal(targetUrl);
    }
  });

  window.webContents.setWindowOpenHandler(({ url }) => {
    if (isAllowedNavigation(url)) {
      return { action: 'allow' };
    }
    void shell.openExternal(url);
    return { action: 'deny' };
  });

  window.once('ready-to-show', () => {
    window.show();
  });

  void window.loadURL('about:blank');

  return window;
}

app.whenReady().then(() => {
  registerIpcHandlers();
  createMainWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createMainWindow();
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});
