import {
  createLocalProvider,
  matchCatalogue,
  readText,
  type CatalogueItem,
  type LearnedLink,
  type Match,
  type OcrEngine,
  type ReadLine,
  type Reading,
} from '@sanara/assistant';
import { api } from '../api/client';

/**
 * Lecteur d'ordonnances Sanara côté pharmacie. La reconnaissance des caractères (Tesseract) tourne dans
 * ce navigateur, avec des fichiers servis par notre site (/ocr/) : l'image ne part nulle part ailleurs.
 */
let engine: Promise<OcrEngine> | null = null;

function loadEngine(onProgress: (ratio: number) => void): Promise<OcrEngine> {
  engine ??= (async () => {
    const { createWorker, OEM } = await import('tesseract.js');
    const worker = await createWorker('fra', OEM.LSTM_ONLY, {
      workerPath: '/ocr/worker.min.js',
      corePath: '/ocr/core',
      langPath: '/ocr/lang',
      gzip: true,
      workerBlobURL: false,
      logger: (m: { status: string; progress: number }) => {
        if (m.status === 'recognizing text') onProgress(m.progress);
      },
    });
    return {
      async recognize(page: unknown) {
        const { data } = await worker.recognize(page as Blob);
        return { text: data.text, confidence: Math.round(data.confidence) };
      },
    };
  })().catch((e: unknown) => {
    engine = null;
    throw e;
  });
  return engine;
}

export interface Proposal {
  line: ReadLine;
  matches: Match[];
}

export interface LearnedState {
  links: LearnedLink[];
  reliability: { lines: number; corrected: number; accepted_rate: number | null };
}

export const loadLexicon = () => api<LearnedState>('GET', '/app/assistant/lexicon');

/** Lit les pages (photos), puis propose pour chaque ligne les produits du catalogue les plus proches. */
export async function readPrescription(
  pages: Blob[],
  learned: LearnedLink[],
  onProgress: (ratio: number) => void,
): Promise<{ reading: Reading; proposals: Proposal[] }> {
  const ocr = await loadEngine((p) => onProgress(p));
  const reading = await createLocalProvider(ocr).read(pages, (done, total) =>
    onProgress(done / total),
  );
  const proposals = await Promise.all(
    reading.lines.map(async (line) => {
      const term = line.name.split(' ').slice(0, 2).join(' ');
      const found = await api<{ items: CatalogueItem[] }>(
        'GET',
        `/app/products?${new URLSearchParams({ q: term, limit: '8', picker: '1' })}`,
      ).catch(() => ({ items: [] as CatalogueItem[] }));
      return { line, matches: matchCatalogue(line, found.items, 3, learned) };
    }),
  );
  return { reading, proposals };
}

export { readText };
