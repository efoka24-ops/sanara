// Icône de l'application (.ico, .png) et bandeau de l'installateur (.bmp), rendus à partir des mêmes
// dessins que les illustrations. Usage : node apps/desktop/scripts/icons.mjs
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { icon, installerSidebar } from '../../../scripts/illustrations.mjs';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repo = path.resolve(root, '..', '..');
const out = path.join(root, 'build');
const { chromium } = createRequire(path.join(repo, 'package.json'))('@playwright/test');
const font = (family, weight) => {
  const dir = createRequire(path.join(repo, 'packages', 'ui', 'package.json')).resolve(
    `@fontsource/${family}/package.json`,
  );
  return fs
    .readFileSync(path.join(path.dirname(dir), 'files', `${family}-latin-${weight}-normal.woff2`))
    .toString('base64');
};

/** Pixels RGBA d'un dessin SVG à la taille voulue, et son PNG. */
async function render(page, svg, width, height) {
  return page.evaluate(
    async ({ svg, width, height, fonts }) => {
      for (const [family, weight, data] of fonts) {
        const face = new FontFace(family, `url(data:font/woff2;base64,${data})`, { weight });
        document.fonts.add(await face.load());
      }
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      // Le SVG est dessiné en ligne pour que le texte utilise les polices chargées.
      const image = new Image();
      const style = fonts
        .map(
          ([family, weight, data]) =>
            `@font-face{font-family:'${family}';font-weight:${weight};src:url(data:font/woff2;base64,${data})}`,
        )
        .join('');
      const source = svg.replace(/(<svg[^>]*>)/, `$1<style>${style}</style>`);
      image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(source)}`;
      await image.decode();
      ctx.drawImage(image, 0, 0, width, height);
      return {
        png: canvas.toDataURL('image/png').split(',')[1],
        pixels: Array.from(ctx.getImageData(0, 0, width, height).data),
      };
    },
    {
      svg,
      width,
      height,
      fonts: [
        ['Bricolage Grotesque', '800', font('bricolage-grotesque', 800)],
        ['Instrument Sans', '600', font('instrument-sans', 600)],
      ],
    },
  );
}

/** Fichier .ico à entrées PNG (Windows Vista et suivants). */
function ico(images) {
  const header = Buffer.alloc(6 + 16 * images.length);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(images.length, 4);
  let offset = header.length;
  images.forEach(({ size, png }, i) => {
    const at = 6 + 16 * i;
    header.writeUInt8(size === 256 ? 0 : size, at);
    header.writeUInt8(size === 256 ? 0 : size, at + 1);
    header.writeUInt16LE(1, at + 4);
    header.writeUInt16LE(32, at + 6);
    header.writeUInt32LE(png.length, at + 8);
    header.writeUInt32LE(offset, at + 12);
    offset += png.length;
  });
  return Buffer.concat([header, ...images.map((i) => i.png)]);
}

/** Bitmap 24 bits, seul format accepté par l'assistant d'installation. */
function bmp(pixels, width, height) {
  const row = Math.ceil((width * 3) / 4) * 4;
  const buffer = Buffer.alloc(54 + row * height);
  buffer.write('BM');
  buffer.writeUInt32LE(buffer.length, 2);
  buffer.writeUInt32LE(54, 10);
  buffer.writeUInt32LE(40, 14);
  buffer.writeInt32LE(width, 18);
  buffer.writeInt32LE(height, 22);
  buffer.writeUInt16LE(1, 26);
  buffer.writeUInt16LE(24, 28);
  buffer.writeUInt32LE(row * height, 34);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const from = (y * width + x) * 4;
      const to = 54 + (height - 1 - y) * row + x * 3;
      buffer[to] = pixels[from + 2];
      buffer[to + 1] = pixels[from + 1];
      buffer[to + 2] = pixels[from];
    }
  }
  return buffer;
}

fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const page = await browser.newPage();
const images = [];
for (const size of [16, 24, 32, 48, 64, 128, 256]) {
  const { png } = await render(page, icon(size), size, size);
  images.push({ size, png: Buffer.from(png, 'base64') });
}
fs.writeFileSync(path.join(out, 'icon.ico'), ico(images));
const large = await render(page, icon(512), 512, 512);
fs.writeFileSync(path.join(out, 'icon.png'), Buffer.from(large.png, 'base64'));
const side = await render(page, installerSidebar(), 164, 314);
fs.writeFileSync(path.join(out, 'installerSidebar.bmp'), bmp(side.pixels, 164, 314));
await browser.close();
console.log(`Icônes écrites dans ${out}`);
