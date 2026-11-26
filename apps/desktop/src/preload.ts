import { contextBridge } from 'electron';

/** Seul signal donné à l'interface : elle tourne dans l'application de bureau. */
contextBridge.exposeInMainWorld('sanaraDesktop', Object.freeze({ desktop: true }));
