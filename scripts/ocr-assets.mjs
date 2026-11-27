// Copie les fichiers du moteur de reconnaissance (Tesseract) dans apps/web/public/ocr pour qu'ils soient
// servis par notre site : aucune requête vers un service tiers lors de la lecture d'une ordonnance.
import { copyFileSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const web = join(dirname(fileURLToPath(import.meta.url)), '..', 'apps', 'web');
const require = createRequire(join(web, 'package.json'));
const out = join(web, 'public', 'ocr');
const pkg = (name) => dirname(require.resolve(`${name}/package.json`));

mkdirSync(join(out, 'core'), { recursive: true });
mkdirSync(join(out, 'lang'), { recursive: true });

copyFileSync(join(pkg('tesseract.js'), 'dist', 'worker.min.js'), join(out, 'worker.min.js'));

// Variantes « lstm » seulement : le moteur choisit selon le processeur.
const core = pkg('tesseract.js-core');
const variants = readdirSync(core).filter((f) => /lstm\.wasm\.js$/.test(f));
for (const f of variants) copyFileSync(join(core, f), join(out, 'core', f));

const data = pkg('@tesseract.js-data/fra');
const trained = ['4.0.0_best_int', '4.0.0', '4.0.0_fast']
  .map((d) => join(data, d, 'fra.traineddata.gz'))
  .find((p) => existsSync(p));
if (!trained) throw new Error('fra.traineddata.gz introuvable dans @tesseract.js-data/fra');
copyFileSync(trained, join(out, 'lang', 'fra.traineddata.gz'));

console.log(`ocr : ${variants.length} variantes du moteur et la langue française copiées`);
