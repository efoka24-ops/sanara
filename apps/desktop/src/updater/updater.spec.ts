import { EventEmitter } from 'node:events';
import { describe, expect, it, vi } from 'vitest';
import { TillOpenError, Updater, updateFeed, type AutoUpdaterLike } from './updater';

class FakeUpdater extends EventEmitter implements AutoUpdaterLike {
  autoDownload = false;
  autoInstallOnAppQuit = false;
  allowDowngrade = false;
  feed: string | null = null;
  checks = 0;
  installed = 0;
  setFeedURL(options: { provider: 'generic'; url: string }): void {
    this.feed = options.url;
  }
  async checkForUpdates(): Promise<unknown> {
    this.checks++;
    return null;
  }
  quitAndInstall(): void {
    this.installed++;
  }
}

const make = (over: { serverUrl?: string; packaged?: boolean; tillOpen?: () => boolean } = {}) => {
  const fake = new FakeUpdater();
  const updater = new Updater({
    updater: fake,
    serverUrl: over.serverUrl ?? 'https://sanara.example/api',
    packaged: over.packaged ?? true,
    tillOpen: over.tillOpen ?? (() => false),
  });
  return { fake, updater };
};

describe('mises à jour du poste (T128)', () => {
  it('ne cherche des mises à jour qu’en HTTPS et depuis une application installée', () => {
    expect(updateFeed('https://sanara.example/api')).toBe(
      'https://sanara.example/desktop-releases/',
    );
    expect(updateFeed('http://sanara.example')).toBeNull();
    expect(updateFeed('pas une adresse')).toBeNull();

    const http = make({ serverUrl: 'http://sanara.example' });
    http.updater.start();
    expect(http.updater.state()).toMatchObject({ status: 'disabled', reason: 'https_required' });
    expect(http.fake.checks).toBe(0);

    const dev = make({ packaged: false });
    dev.updater.start();
    expect(dev.updater.state()).toMatchObject({ status: 'disabled', reason: 'not_packaged' });
  });

  it('télécharge en arrière-plan, installe à la fermeture et permet le retour arrière', () => {
    vi.useFakeTimers();
    const { fake, updater } = make();
    updater.start();
    expect(fake).toMatchObject({
      feed: 'https://sanara.example/desktop-releases/',
      autoDownload: true,
      autoInstallOnAppQuit: true,
      allowDowngrade: true,
      checks: 1,
    });
    fake.emit('update-available', { version: '0.2.0' });
    expect(updater.state()).toEqual({ status: 'downloading', version: '0.2.0', reason: null });
    fake.emit('update-downloaded', { version: '0.2.0' });
    expect(updater.state().status).toBe('ready');
    vi.advanceTimersByTime(6 * 60 * 60 * 1000);
    // Une version prête n'est pas retéléchargée.
    expect(fake.checks).toBe(1);
    updater.stop();
    vi.useRealTimers();
  });

  it('n’installe jamais pendant qu’une caisse est ouverte', () => {
    let open = true;
    const { fake, updater } = make({ tillOpen: () => open });
    updater.start();
    expect(() => updater.install()).toThrow('no_update');
    fake.emit('update-downloaded', { version: '0.2.0' });
    expect(() => updater.install()).toThrow(TillOpenError);
    expect(fake.installed).toBe(0);
    open = false;
    updater.install();
    expect(fake.installed).toBe(1);
    updater.stop();
  });

  it('garde la dernière erreur sans s’arrêter', async () => {
    const { fake, updater } = make();
    updater.start();
    fake.emit('error', new Error('réseau coupé'));
    expect(updater.state()).toMatchObject({ status: 'error', reason: 'réseau coupé' });
    await updater.check();
    expect(fake.checks).toBe(2);
    updater.stop();
  });
});
