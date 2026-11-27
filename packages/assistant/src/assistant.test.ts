import { describe, expect, it } from 'vitest';
import {
  createLocalProvider,
  matchCatalogue,
  parsePrescription,
  similarity,
  validateReading,
  type CatalogueItem,
} from './index';

// Ordonnance fictive, telle qu'un moteur de reconnaissance la rend (coquilles comprises).
const SCAN = `Dr NGONO Paul
Médecin généraliste - Tél : 690 00 00 00
Douala, le 12/11/2026
Patient : Mme A.
ORDONNANCE
1) Amoxicilline 500 mg gélules
   1 gél x 3/j pendant 7 jours
   2 boîtes
2) Paracétamol 1 g cp
   1 cp si douleur, 3 fois par jour au plus
- Sirop Toplexil
   1 cuillère matin et soir
Bien boire de l'eau.
Signature et cachet`;

const CATALOGUE: CatalogueItem[] = [
  {
    id: 'a',
    name: 'AMOXICILLINE 500MG GELULE B/12',
    dci: 'amoxicilline',
    strength: '500 mg',
    form: 'gélule',
    regulatory_status: 'prescription',
  },
  {
    id: 'b',
    name: 'AMOXICILLINE 1G CPR B/14',
    dci: 'amoxicilline',
    strength: '1 g',
    form: 'comprimé',
    regulatory_status: 'prescription',
  },
  {
    id: 'c',
    name: 'DOLIPRANE 1000MG CPR B/8',
    dci: 'paracétamol',
    strength: '1000 mg',
    form: 'comprimé',
    regulatory_status: 'free',
  },
  {
    id: 'd',
    name: 'PARACETAMOL 1G CPR B/8',
    dci: 'paracétamol',
    strength: '1 g',
    form: 'comprimé',
    regulatory_status: 'free',
  },
  {
    id: 'e',
    name: 'TOPLEXIL SIROP 150ML',
    dci: 'oxomémazine',
    strength: null,
    form: 'sirop',
    regulatory_status: 'free',
  },
  {
    id: 'f',
    name: 'VITAMINE C 500MG CPR',
    dci: 'acide ascorbique',
    strength: '500 mg',
    form: 'comprimé',
    regulatory_status: 'free',
  },
];

describe('lecture des lignes', () => {
  const lines = parsePrescription(SCAN, 80);

  it('garde les médicaments et laisse de côté en-têtes, conseils et signature', () => {
    expect(lines.map((l) => l.name)).toEqual(['Amoxicilline', 'Paracétamol', 'Toplexil']);
  });

  it('rattache dosage, forme, posologie et nombre de boîtes à chaque médicament', () => {
    expect(lines[0]).toMatchObject({
      strength: '500 mg',
      form: 'gélule',
      posology: '1 gél x 3/j pendant 7 jours',
      quantity: 2,
    });
    expect(lines[1]).toMatchObject({ strength: '1 g', form: 'comprimé', quantity: null });
    expect(lines[1]!.posology).toMatch(/^1 cp si douleur/);
    expect(lines[2]).toMatchObject({
      strength: null,
      form: 'sirop',
      posology: '1 cuillère matin et soir',
    });
  });

  it('donne une confiance plus forte aux lignes complètes', () => {
    expect(lines[0]!.confidence).toBeGreaterThan(lines[2]!.confidence);
    expect(lines.every((l) => l.confidence > 0 && l.confidence <= 100)).toBe(true);
  });

  it('ne rend rien pour un texte sans médicament', () => {
    expect(parsePrescription('Dr Ngono\nTél : 690 00 00 00\nBon rétablissement')).toEqual([]);
  });
});

describe('rapprochement avec le catalogue', () => {
  const [amox, para, sirop] = parsePrescription(SCAN, 80);

  it('préfère le produit dont le dosage concorde', () => {
    const found = matchCatalogue(amox!, CATALOGUE);
    expect(found[0]!.product.id).toBe('a');
    expect(found[0]!.score).toBeGreaterThan(found[1]!.score);
  });

  it('retrouve un produit par sa DCI et par son nom commercial', () => {
    expect(matchCatalogue(para!, CATALOGUE).map((m) => m.product.id)).toContain('d');
    expect(
      matchCatalogue({ ...para!, name: 'Doliprane', strength: '1000 mg' }, CATALOGUE)[0]!.product
        .id,
    ).toBe('c');
    expect(matchCatalogue(sirop!, CATALOGUE)[0]!.product.id).toBe('e');
  });

  it('tolère une coquille de lecture', () => {
    expect(matchCatalogue({ ...amox!, name: 'Amoxici11ine' }, CATALOGUE)[0]?.product.id).toBe('a');
    expect(similarity('amoxicilline', 'amoxicilline')).toBe(1);
  });

  it('ne propose rien de trop éloigné', () => {
    expect(matchCatalogue({ ...amox!, name: 'Metformine', strength: null }, CATALOGUE)).toEqual([]);
  });
});

describe('moteur de lecture Sanara', () => {
  it('assemble les pages, signale les pages illisibles et valide sa sortie', async () => {
    const pages = [SCAN, '...'];
    const engine = {
      recognize: async (p: unknown) => ({ text: String(p), confidence: p === SCAN ? 82 : 12 }),
    };
    const progress: number[] = [];
    const reading = await createLocalProvider(engine).read(pages, (done) => progress.push(done));
    expect(progress).toEqual([1, 2]);
    expect(reading.lines).toHaveLength(3);
    expect(reading.confidence).toBe(47);
    expect(reading.anomalies).toEqual(['unreadable_page', 'low_confidence']);
  });

  it('dit quand aucun médicament n’a été trouvé', async () => {
    const engine = { recognize: async () => ({ text: '', confidence: 0 }) };
    const reading = await createLocalProvider(engine).read(['vide']);
    expect(reading.anomalies).toEqual([
      'unreadable_page',
      'empty',
      'low_confidence',
      'no_medicine_found',
    ]);
  });

  it('refuse une lecture mal formée', () => {
    expect(() =>
      validateReading({ text: '', lines: [{ name: '' }], confidence: 50, anomalies: [] }),
    ).toThrow(/ligne 1/);
    expect(() => validateReading({ text: '', lines: [], confidence: 120, anomalies: [] })).toThrow(
      /confidence/,
    );
  });
});

describe('apprentissage par les corrections', () => {
  const [amox] = parsePrescription(SCAN, 80);
  const brand: CatalogueItem = {
    id: 'g',
    name: 'CLAMOXYL 500MG GELULE',
    dci: 'amoxicilline',
    strength: '500 mg',
    form: 'gélule',
    regulatory_status: 'prescription',
  };

  it('propose d’abord le produit que le pharmacien a déjà retenu pour ce texte', () => {
    const before = matchCatalogue(amox!, CATALOGUE);
    expect(before[0]!.product.id).toBe('a');
    const links = [{ read: 'Amoxicilline 500 mg', uses: 3, product: brand }];
    const after = matchCatalogue(amox!, CATALOGUE, 3, links);
    expect(after[0]).toMatchObject({ product: { id: 'g' }, learned: true });
    expect(after[0]!.score).toBeGreaterThanOrEqual(before[0]!.score);
  });

  it('reconnaît un texte appris malgré une coquille de lecture', () => {
    const links = [{ read: 'Amoxicilline 500 mg', uses: 1, product: brand }];
    const typo = { ...amox!, name: 'Amoxici1line' };
    expect(matchCatalogue(typo, CATALOGUE, 3, links).some((m) => m.learned)).toBe(true);
  });

  it('ignore ce qui n’a rien à voir', () => {
    const links = [{ read: 'Metformine 850 mg', uses: 9, product: brand }];
    expect(matchCatalogue(amox!, CATALOGUE, 3, links).some((m) => m.learned)).toBe(false);
  });
});
