/**
 * T162 : interface du lecteur d'ordonnances (constitution VIII). Le moteur Sanara l'implémente sans
 * service externe ; un autre moteur peut la remplacer sans toucher aux écrans.
 */

/** Une ligne de médicament reconnue sur l'ordonnance. */
export interface ReadLine {
  /** Texte tel qu'il a été lu, pour que le pharmacien le compare à l'image. */
  raw: string;
  name: string;
  strength: string | null;
  form: string | null;
  posology: string | null;
  /** Nombre de boîtes ou de flacons demandés, s'il est écrit. */
  quantity: number | null;
  /** 0 à 100 : à quel point la lecture de cette ligne est sûre. */
  confidence: number;
}

export type Anomaly = 'empty' | 'low_confidence' | 'no_medicine_found' | 'unreadable_page';

export interface Reading {
  text: string;
  lines: ReadLine[];
  /** Confiance moyenne de la reconnaissance des caractères, 0 à 100. */
  confidence: number;
  anomalies: Anomaly[];
}

/** Moteur de reconnaissance de caractères : renvoie le texte d'une page et sa confiance. */
export interface OcrEngine {
  recognize(page: unknown): Promise<{ text: string; confidence: number }>;
}

export interface AssistantProvider {
  readonly name: string;
  read(pages: unknown[], onProgress?: (done: number, total: number) => void): Promise<Reading>;
}

const isString = (v: unknown): v is string => typeof v === 'string';
const isScore = (v: unknown): v is number => typeof v === 'number' && v >= 0 && v <= 100;
const ANOMALIES: Anomaly[] = ['empty', 'low_confidence', 'no_medicine_found', 'unreadable_page'];

/** Contrôle la forme d'une lecture venue d'un moteur, quel qu'il soit, avant de l'afficher. */
export function validateReading(value: unknown): Reading {
  const r = value as Partial<Reading> | null;
  const fail = (what: string): never => {
    throw new TypeError(`Lecture invalide : ${what}`);
  };
  if (!r || typeof r !== 'object') fail('objet attendu');
  if (!isString(r!.text)) fail('text');
  if (!isScore(r!.confidence)) fail('confidence');
  if (!Array.isArray(r!.anomalies) || r!.anomalies.some((a) => !ANOMALIES.includes(a)))
    fail('anomalies');
  if (!Array.isArray(r!.lines)) fail('lines');
  r!.lines!.forEach((l, i) => {
    const ok =
      l &&
      isString(l.raw) &&
      isString(l.name) &&
      l.name.trim() !== '' &&
      (l.strength === null || isString(l.strength)) &&
      (l.form === null || isString(l.form)) &&
      (l.posology === null || isString(l.posology)) &&
      (l.quantity === null || (Number.isInteger(l.quantity) && l.quantity > 0)) &&
      isScore(l.confidence);
    if (!ok) fail(`ligne ${i + 1}`);
  });
  return r as Reading;
}
