import { describe, expect, it } from 'vitest';
import { ulid } from './ids';

describe('ulid', () => {
  it('fait 26 caractères valides', () => {
    expect(ulid()).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
  });
  it('est triable dans le temps', () => {
    const a = ulid(1_000_000);
    const b = ulid(2_000_000);
    expect(a < b).toBe(true);
  });
  it('ne collisionne pas sur 10 000 tirages', () => {
    const s = new Set(Array.from({ length: 10000 }, () => ulid()));
    expect(s.size).toBe(10000);
  });
});
