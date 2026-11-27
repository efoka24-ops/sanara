import { plain } from './parse';
import type { CatalogueItem, Match } from './matching';
import type { ReadLine } from './provider';

/**
 * Ce que la pharmacie a appris : un texte lu sur une ordonnance et le produit que le pharmacien a
 * retenu pour lui, avec le nombre de fois où ce lien a été confirmé.
 */
export interface LearnedLink {
  read: string;
  uses: number;
  product: CatalogueItem;
}

/** Clé d'un texte lu : minuscules, sans accents ni ponctuation. */
export function readKey(text: string): string {
  return plain(text)
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

/** Texte à mémoriser pour une ligne : nom et dosage, comme ils ont été lus. */
export function readText(line: Pick<ReadLine, 'name' | 'strength'>): string {
  return [line.name, line.strength].filter(Boolean).join(' ');
}

/** Ressemblance tolérante aux erreurs de lecture, déjà utilisée pour le catalogue. */
type Similarity = (a: string, b: string) => number;

/**
 * Produits appris pour une ligne lue. Un lien confirmé plusieurs fois l'emporte sur la ressemblance des
 * noms : c'est ainsi que le moteur s'améliore avec l'usage, sans service extérieur.
 */
export function learnedMatches(
  line: ReadLine,
  links: LearnedLink[],
  similarity: Similarity,
): Match[] {
  const key = readKey(readText(line));
  if (key === '') return [];
  const best = new Map<string, Match>();
  for (const link of links) {
    const close = similarity(key, readKey(link.read));
    if (close < 0.85) continue;
    // Confiance de 94 à 100 selon le nombre de confirmations, réduite si le texte diffère un peu.
    const score = Math.round(Math.min(100, 88 + 6 * Math.min(link.uses, 2)) * close);
    const known = best.get(link.product.id);
    if (!known || known.score < score)
      best.set(link.product.id, { product: link.product, score, learned: true });
  }
  return [...best.values()];
}
