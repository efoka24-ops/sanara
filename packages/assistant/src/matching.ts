import { plain } from './parse';
import { learnedMatches, type LearnedLink } from './learned';
import type { ReadLine } from './provider';

/** Produit du catalogue de la pharmacie, tel que la recherche le renvoie. */
export interface CatalogueItem {
  id: string;
  name: string;
  dci: string | null;
  strength: string | null;
  form: string | null;
  regulatory_status: string;
}

export interface Match {
  product: CatalogueItem;
  /** 0 à 100. */
  score: number;
  /** Proposé parce que le pharmacien l'a déjà choisi pour ce texte. */
  learned?: boolean;
}

const words = (text: string) =>
  plain(text)
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .split(' ')
    .filter(Boolean);

function bigrams(text: string): string[] {
  const s = ` ${text} `;
  const out: string[] = [];
  for (let i = 0; i < s.length - 1; i++) out.push(s.slice(i, i + 2));
  return out;
}

/** Ressemblance de deux textes (coefficient de Dice sur les paires de lettres), tolérante aux erreurs de lecture. */
export function similarity(a: string, b: string): number {
  if (!a || !b) return 0;
  if (a === b) return 1;
  const left = bigrams(a);
  const right = new Map<string, number>();
  for (const g of bigrams(b)) right.set(g, (right.get(g) ?? 0) + 1);
  let common = 0;
  for (const g of left) {
    const n = right.get(g) ?? 0;
    if (n > 0) {
      common++;
      right.set(g, n - 1);
    }
  }
  return (2 * common) / (left.length + bigrams(b).length);
}

/** Compare le nom lu au début du nom du produit, sur autant de mots (plus ou moins un). */
function nameScore(read: string[], candidate: string | null): number {
  if (!candidate) return 0;
  const target = words(candidate).filter((w) => !/^\d/.test(w));
  const text = read.join(' ');
  let best = 0;
  for (let k = Math.max(1, read.length - 1); k <= read.length + 1; k++) {
    best = Math.max(best, similarity(text, target.slice(0, k).join(' ')));
  }
  return best;
}

const numbers = (text: string | null) =>
  text ? (plain(text).match(/\d+(?:[.,]\d+)?/g) ?? []) : [];

/**
 * T164 : propose les produits du catalogue les plus proches d'une ligne lue, par nom commercial ou DCI,
 * avec un bonus quand le dosage concorde. Les liens appris de la pharmacie passent en premier.
 */
export function matchCatalogue(
  line: ReadLine,
  catalogue: CatalogueItem[],
  limit = 3,
  learned: LearnedLink[] = [],
): Match[] {
  const read = words(line.name).filter((w) => !/^\d/.test(w));
  if (read.length === 0) return [];
  const wanted = numbers(line.strength).map((n) => n.replace(',', '.'));
  const scored = catalogue.map((product) => {
    let score = Math.max(nameScore(read, product.name), nameScore(read, product.dci)) * 100;
    if (wanted.length > 0) {
      const has = numbers(`${product.name} ${product.strength ?? ''}`).map((n) =>
        n.replace(',', '.'),
      );
      if (has.length > 0) score += wanted.some((n) => has.includes(n)) ? 10 : -15;
    }
    if (line.form && product.form && plain(product.form).startsWith(plain(line.form).slice(0, 4)))
      score += 5;
    return { product, score: Math.round(Math.max(0, Math.min(100, score))) };
  });
  const byProduct = new Map<string, Match>();
  for (const found of [...scored, ...learnedMatches(line, learned, similarity)]) {
    const known = byProduct.get(found.product.id);
    if (!known || known.score < found.score) byProduct.set(found.product.id, found);
  }
  return (
    [...byProduct.values()]
      .filter((m) => m.score >= 50)
      // À score égal, ce que la pharmacie a appris passe devant.
      .sort((a, b) => b.score - a.score || Number(b.learned ?? false) - Number(a.learned ?? false))
      .slice(0, limit)
  );
}
