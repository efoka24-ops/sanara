export type { Anomaly, AssistantProvider, OcrEngine, ReadLine, Reading } from './provider';
export { validateReading } from './provider';
export { parsePrescription, plain } from './parse';
export { matchCatalogue, similarity, type CatalogueItem, type Match } from './matching';
export { createLocalProvider } from './local';
export { learnedMatches, readKey, readText, type LearnedLink } from './learned';
