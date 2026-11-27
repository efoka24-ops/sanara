import { parsePrescription } from './parse';
import {
  validateReading,
  type Anomaly,
  type AssistantProvider,
  type OcrEngine,
  type Reading,
} from './provider';

/** En dessous, la page est sans doute floue, mal éclairée ou manuscrite. */
const LOW_CONFIDENCE = 55;
const UNREADABLE = 25;

/**
 * T163 : moteur de lecture Sanara. La reconnaissance des caractères tourne sur le poste de la
 * pharmacie (moteur fourni par l'appelant) ; l'analyse des lignes est la nôtre. Aucun service extérieur.
 */
export function createLocalProvider(engine: OcrEngine): AssistantProvider {
  return {
    name: 'sanara-local',
    async read(pages, onProgress) {
      const texts: string[] = [];
      const scores: number[] = [];
      const anomalies = new Set<Anomaly>();
      for (const [i, page] of pages.entries()) {
        const { text, confidence } = await engine.recognize(page);
        texts.push(text);
        scores.push(confidence);
        if (confidence < UNREADABLE || text.trim().length < 10) anomalies.add('unreadable_page');
        onProgress?.(i + 1, pages.length);
      }
      const confidence = scores.length
        ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length)
        : 0;
      const lines = texts.flatMap((text, i) => parsePrescription(text, scores[i]));
      if (texts.join('').trim() === '') anomalies.add('empty');
      if (confidence < LOW_CONFIDENCE) anomalies.add('low_confidence');
      if (lines.length === 0) anomalies.add('no_medicine_found');
      const reading: Reading = {
        text: texts.join('\n\n'),
        lines,
        confidence,
        anomalies: [...anomalies],
      };
      return validateReading(reading);
    },
  };
}
