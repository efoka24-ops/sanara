import type { CSSProperties } from 'react';

type Tone = 'brand' | 'light';

const PALETTES: Record<Tone, [string, string, string, string]> = {
  brand: ['#0b5d3b', '#14804f', '#3fae7a', '#e8a33d'],
  light: ['#ffffff', '#bfe5d0', '#7fcaa3', '#e8a33d'],
};

/** Logo Sanara : quatre pétales (vert profond, vert, vert clair, ambre) et le mot-symbole. */
export function Logo({
  size = 30,
  tone = 'brand',
  wordmark = true,
  style,
}: {
  size?: number;
  tone?: Tone;
  wordmark?: boolean;
  style?: CSSProperties;
}) {
  const [a, b, c, d] = PALETTES[tone];
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, ...style }}>
      <svg width={size} height={size} viewBox="0 0 100 100" role="img" aria-label="Sanara">
        <path d="M50 45C38 37 37 15 50 3C63 15 62 37 50 45Z" fill={a} />
        <path d="M55 50C63 38 85 37 97 50C85 63 63 62 55 50Z" fill={b} />
        <path d="M50 55C62 63 63 85 50 97C37 85 38 63 50 55Z" fill={c} />
        <path d="M45 50C37 62 15 63 3 50C15 37 37 38 45 50Z" fill={d} />
      </svg>
      {wordmark && (
        <span
          style={{
            fontFamily: 'var(--sa-font-display)',
            fontWeight: 800,
            letterSpacing: '-0.04em',
            fontSize: Math.round(size * 0.8),
            color: tone === 'brand' ? '#0b5d3b' : '#fff',
          }}
        >
          sanara
        </span>
      )}
    </span>
  );
}
