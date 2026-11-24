import { contextBridge, ipcRenderer } from 'electron';

type AppBridge = {
  getVersion: () => Promise<string>;
  ping: (message?: string) => Promise<string>;
};

const appBridge: AppBridge = {
  getVersion: () => ipcRenderer.invoke('app:getVersion') as Promise<string>,
  ping: (message = 'ping') => ipcRenderer.invoke('app:ping', message) as Promise<string>,
};

contextBridge.exposeInMainWorld('sanaraApp', appBridge);

declare global {
  interface Window {
    sanaraApp: AppBridge;
  }
}
