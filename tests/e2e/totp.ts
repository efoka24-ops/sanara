import crypto from 'node:crypto';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** TOTP RFC 6238 pour piloter l'application d'authentification dans les tests. */
export function totp(secret: string, atSeconds = Math.floor(Date.now() / 1000)): string {
  let bits = '';
  for (const ch of secret.toUpperCase()) bits += ALPHABET.indexOf(ch).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(atSeconds / 30)));
  const h = crypto.createHmac('sha1', key).update(counter).digest();
  const o = h[19]! & 0x0f;
  const n = ((h[o]! & 0x7f) << 24) | (h[o + 1]! << 16) | (h[o + 2]! << 8) | h[o + 3]!;
  return String(n % 1_000_000).padStart(6, '0');
}
