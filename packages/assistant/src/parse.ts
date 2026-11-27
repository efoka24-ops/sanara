import type { ReadLine } from './provider';

/** Minuscules sans accents : la comparaison ignore la façon d'écrire. */
export function plain(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const STRENGTH = /\b(\d+(?:[.,]\d+)?)\s?(mg|g|ml|mcg|µg|ug|ui|%)(?![a-z])/i;

/** Formes galéniques courantes, écrites en entier ou abrégées. */
const FORMS: [RegExp, string][] = [
  [/\b(cp|cpr|comp|comprimes?|comprimés?)\b/i, 'comprimé'],
  [/\b(gel|gél|gelules?|gélules?|caps|capsules?)\b/i, 'gélule'],
  [/\b(sirop|sp)\b/i, 'sirop'],
  [/\b(sachets?|sach)\b/i, 'sachet'],
  [/\b(amp|ampoules?|inj|injectables?)\b/i, 'injectable'],
  [/\b(suppo|suppositoires?)\b/i, 'suppositoire'],
  [/\b(pommade|creme|crème|gel dermique)\b/i, 'pommade'],
  [/\b(collyre|gouttes?)\b/i, 'gouttes'],
  [/\b(susp|suspension)\b/i, 'suspension'],
];

/** En-têtes, pieds et coordonnées : jamais des médicaments. */
const NOT_MEDICINE =
  /^(dr\.?|docteur|pr\.?\s|professeur|medecin|hopital|clinique|centre|cabinet|service|tel|tél|telephone|fax|e-?mail|date|le\s+\d|fait\s+a|patient|nom|prenom|age|poids|taille|n[°o]\s*(d'?\s*)?ordre|ordre\s+des|signature|cachet|ordonnance|renouvel|adresse|bp\s*\d|www\.|diagnostic)/;

/** Ligne de posologie : se rattache au médicament qui la précède. */
const POSOLOGY =
  /^(\d+(?:[.,]\d+)?\s*(cp|cpr|comprim|gel|gél|sachet|cuill|c\.|amp|goutte|suppo|fois|x|dose|bouff|application|mesure)|(matin|midi|soir|au coucher)\b|(pendant|durant|si\s+(douleur|fievre|besoin))\b|\d+\s*(x|fois)\s*\/?\s*(j|jour)|\d+\s*\/\s*j\b)/;

const BOXES = /\b(\d+)\s*(bo[iî]tes?|btes?|flacons?|fl|tubes?)\b/i;
const NUMBER_SIGN = /\bn[°o]\s*(\d+)\b/i;
const QSP = /\bqsp\s*(\d+)\s*(j|jours?|mois|sem)/i;

function quantityOf(text: string): number | null {
  const m = BOXES.exec(text) ?? NUMBER_SIGN.exec(text);
  const n = m ? Number(m[1]) : NaN;
  return Number.isInteger(n) && n > 0 && n < 100 ? n : null;
}

function formOf(text: string): string | null {
  return FORMS.find(([re]) => re.test(text))?.[1] ?? null;
}

/** Nom du médicament : la ligne sans numéro, dosage, forme ni quantité. */
function nameOf(text: string): string {
  let name = text
    .replace(/^\s*(\d+\s*[).:-]|[-•*·>]+)\s*/, '')
    .replace(STRENGTH, ' ')
    .replace(BOXES, ' ')
    .replace(NUMBER_SIGN, ' ')
    .replace(QSP, ' ');
  for (const [re] of FORMS) name = name.replace(re, ' ');
  return name
    .replace(/[|_~=]+/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/^[\s,.;:/-]+|[\s,.;:/-]+$/g, '')
    .trim();
}

/** Mot qui ressemble à un nom de médicament (au moins quatre lettres). */
const WORD = /[a-zàâäéèêëîïôöùûüç]{4,}/i;

/**
 * T163 : découpe le texte reconnu d'une ordonnance en lignes de médicaments. Règles volontairement
 * prudentes : le pharmacien voit toujours l'image et corrige.
 *
 * @param ocrConfidence confiance de la reconnaissance des caractères pour la page, 0 à 100
 */
export function parsePrescription(text: string, ocrConfidence = 70): ReadLine[] {
  const lines: ReadLine[] = [];
  for (const rawLine of text.split(/\r?\n/)) {
    const raw = rawLine.replace(/\s+/g, ' ').trim();
    if (raw.length < 3) continue;
    const p = plain(raw);
    if (NOT_MEDICINE.test(p) || /^[\d\s/.,:-]+$/.test(p)) continue;

    const last = lines[lines.length - 1];
    if (POSOLOGY.test(p)) {
      if (last) {
        last.posology = last.posology ? `${last.posology} ; ${raw}` : raw;
        last.quantity ??= quantityOf(raw);
      }
      continue;
    }
    if (
      last &&
      !STRENGTH.test(raw) &&
      !formOf(raw) &&
      quantityOf(raw) !== null &&
      !WORD.test(nameOf(raw))
    ) {
      last.quantity ??= quantityOf(raw);
      continue;
    }

    const strength = STRENGTH.exec(raw);
    const form = formOf(raw);
    const numbered = /^\s*(\d+\s*[).:-]|[-•*·>]+)/.test(raw);
    const name = nameOf(raw);
    // Sans dosage, forme ni puce, une ligne de texte est plus probablement une note qu'un médicament.
    if (!WORD.test(name) || (!strength && !form && !numbered)) continue;

    const signals = (strength ? 1 : 0) + (form ? 1 : 0) + (numbered ? 1 : 0);
    lines.push({
      raw,
      name,
      strength: strength ? `${strength[1]!.replace(',', '.')} ${strength[2]!.toLowerCase()}` : null,
      form,
      posology: null,
      quantity: quantityOf(raw),
      confidence: Math.round(Math.max(0, Math.min(100, ocrConfidence)) * (0.55 + 0.15 * signals)),
    });
  }
  return lines;
}
