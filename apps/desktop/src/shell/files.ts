/** Service des fichiers de l'interface embarquée et règles de navigation (T125). */
import fs from 'node:fs';
import path from 'node:path';

const TYPES: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
};

const POLICY = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob:",
  "font-src 'self' data:",
  "connect-src 'self'",
  "frame-src 'self' blob:",
  "object-src 'none'",
  "base-uri 'none'",
  "form-action 'self'",
].join('; ');

function respond(file: string): Response {
  const type = TYPES[path.extname(file).toLowerCase()] ?? 'application/octet-stream';
  const headers: Record<string, string> = {
    'Content-Type': type,
    'X-Content-Type-Options': 'nosniff',
  };
  if (type.startsWith('text/html')) headers['Content-Security-Policy'] = POLICY;
  return new Response(new Uint8Array(fs.readFileSync(file)), { headers });
}

export function serveFile(root: string, pathname: string): Response {
  let relative: string;
  try {
    relative = decodeURIComponent(pathname);
  } catch {
    relative = '/';
  }
  const base = path.resolve(root);
  const file = path.resolve(base, `.${path.posix.normalize(`/${relative}`)}`);
  const inside = file === base || file.startsWith(base + path.sep);
  if (inside && !relative.includes('\0') && fs.existsSync(file) && fs.statSync(file).isFile()) {
    return respond(file);
  }
  // Un fichier demandé et absent est une erreur ; une route de l'application rend la page unique.
  if (path.extname(relative) !== '') return new Response('Introuvable', { status: 404 });
  return respond(path.join(base, 'index.html'));
}

export function externalAllowed(url: string): boolean {
  try {
    return ['https:', 'http:', 'tel:', 'mailto:'].includes(new URL(url).protocol);
  } catch {
    return false;
  }
}
