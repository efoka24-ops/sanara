// Illustrations vectorielles des panneaux verts et icône de l'application, aux couleurs de la marque.
// Usage : node scripts/illustrations.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const out = path.join(root, 'apps', 'web', 'public', 'images');

const G9 = '#0b5d3b';
const G8 = '#0f6a45';
const G7 = '#14804f';
const G5 = '#3fae7a';
const G2 = '#7fcaa3';
const G3 = '#bfe5d0';
const G1 = '#e8f4ed';
const AM = '#e8a33d';
const AMD = '#c9841f';
const INK = '#14201a';
const W = '#ffffff';
const SKIN = '#8a5637';
const SKIN2 = '#74462b';

const rect = (x, y, w, h, fill, r = 0, extra = '') =>
  `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="${fill}" ${extra}/>`;
const circle = (cx, cy, r, fill, extra = '') =>
  `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}" ${extra}/>`;
const shape = (d, fill, extra = '') => `<path d="${d}" fill="${fill}" ${extra}/>`;
const line = (d, stroke, width = 4, extra = '') =>
  `<path d="${d}" fill="none" stroke="${stroke}" stroke-width="${width}" stroke-linecap="round" stroke-linejoin="round" ${extra}/>`;

/** Les quatre feuilles du logo, centrées sur (x, y). */
function leaves(x, y, size, colors = [W, G3, G2, AM], opacity = 1) {
  const k = size / 100;
  const d = [
    'M50 45C38 37 37 15 50 3C63 15 62 37 50 45Z',
    'M55 50C63 38 85 37 97 50C85 63 63 62 55 50Z',
    'M50 55C62 63 63 85 50 97C37 85 38 63 50 55Z',
    'M45 50C37 62 15 63 3 50C15 37 37 38 45 50Z',
  ];
  return `<g transform="translate(${x - size / 2} ${y - size / 2}) scale(${k})" opacity="${opacity}">${d
    .map((p, i) => shape(p, colors[i]))
    .join('')}</g>`;
}

const svg = (w, h, body, label) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" role="img" aria-label="${label}">\n${body.join('\n')}\n</svg>\n`;

const ground = (cx, cy, rx) =>
  `<ellipse cx="${cx}" cy="${cy}" rx="${rx}" ry="12" fill="#06402a" opacity="0.55"/>`;

function sprig(x, y, s = 1, color = G5) {
  const leaf = (dx, dy, a) =>
    `<path d="M0 0C-14 -10 -15 -34 0 -48C15 -34 14 -10 0 0Z" fill="${color}" transform="translate(${dx} ${dy}) rotate(${a})"/>`;
  return `<g transform="translate(${x} ${y}) scale(${s})">${line('M0 0C-2 -30 4 -60 0 -86', G2, 4)}${leaf(-2, -22, -52)}${leaf(2, -40, 50)}${leaf(-1, -60, -40)}${leaf(0, -80, 8)}</g>`;
}

const plant = (x, y, s = 1) =>
  `<g transform="translate(${x} ${y}) scale(${s})">${sprig(-10, -40, 0.9, G5)}${sprig(14, -40, 0.75, G2)}${shape('M-28 -44H28L20 0H-20Z', AM)}${rect(-31, -50, 62, 10, AMD, 4)}</g>`;

/** Boîte de médicament posée sur une étagère (y = dessus de l'étagère). */
function box(x, y, w, h, fill, band) {
  return `${rect(x, y - h, w, h, fill, 4)}${rect(x, y - h * 0.62, w, h * 0.24, band)}${rect(x + 5, y - h * 0.25, w * 0.5, 3, band, 1.5)}`;
}

function bottle(x, y, w, h, fill) {
  return `${rect(x + w * 0.22, y - h, w * 0.56, h * 0.14, W, 2)}${rect(x + w * 0.3, y - h * 0.88, w * 0.4, h * 0.14, fill)}${rect(x, y - h * 0.76, w, h * 0.76, fill, 7)}${rect(x + 4, y - h * 0.52, w - 8, h * 0.3, G1, 3)}`;
}

function shelf(y) {
  const items = [];
  return {
    items,
    draw: () => items.join('') + rect(92, y, 416, 9, G2, 4),
  };
}

function officine() {
  const a = shelf(150);
  a.items.push(
    box(112, 150, 40, 54, W, G7),
    box(156, 150, 30, 42, G3, G9),
    bottle(196, 150, 30, 58, AM),
    bottle(232, 150, 24, 44, G5),
    box(366, 150, 46, 48, G2, W),
    box(418, 150, 28, 62, W, AM),
    bottle(458, 150, 30, 52, G3),
  );
  const b = shelf(224);
  b.items.push(
    bottle(112, 224, 28, 50, G3),
    box(150, 224, 52, 38, AM, W),
    box(208, 224, 30, 52, W, G5),
    box(372, 224, 30, 56, W, G7),
    box(406, 224, 30, 44, G3, G9),
    bottle(448, 224, 34, 60, G5),
  );
  const c = shelf(298);
  c.items.push(box(114, 298, 62, 44, G2, G9), box(182, 298, 34, 58, W, AM));
  c.items.push(box(390, 298, 54, 40, W, G7), bottle(456, 298, 28, 48, AM));

  const person = [
    // Buste en blouse
    shape('M244 345C244 262 262 238 300 238C338 238 356 262 356 345Z', W),
    shape('M286 240L300 270L314 240Z', G5),
    line('M286 240L296 288M314 240L304 288', G3, 4),
    line('M270 262C262 280 262 300 270 312', G7, 5),
    circle(270, 316, 7, G7),
    rect(318, 286, 22, 26, G1, 3),
    rect(323, 280, 4, 14, AM, 2),
    // Cou, tête, cheveux
    rect(289, 212, 22, 32, SKIN2, 8),
    circle(300, 192, 33, SKIN),
    shape(
      'M267 192C262 154 288 148 302 152C326 146 342 166 333 194C330 178 322 170 312 168C298 180 280 178 272 174C269 180 268 186 267 192Z',
      INK,
    ),
    circle(335, 172, 14, INK),
    circle(289, 195, 3, INK),
    circle(313, 195, 3, INK),
    line('M290 208C296 214 306 214 312 208', INK, 3),
    circle(268, 198, 4, AM),
  ];

  const till = [
    rect(374, 262, 104, 72, INK, 10),
    rect(382, 270, 88, 56, G1, 5),
    rect(390, 278, 44, 5, G2, 2.5),
    rect(390, 289, 62, 5, G3, 2.5),
    rect(390, 300, 34, 5, G3, 2.5),
    rect(428, 308, 36, 12, G7, 4),
    rect(414, 334, 24, 14, INK),
    rect(396, 345, 60, 8, INK, 4),
  ];

  const printer = [
    shape('M132 316V278L139 284L146 278L153 284L160 278L167 284L174 278V316Z', W),
    rect(139, 291, 28, 3, G2, 1.5),
    rect(139, 298, 20, 3, G3, 1.5),
    rect(120, 314, 66, 38, INK, 8),
    circle(173, 340, 4, G5),
  ];

  return svg(
    600,
    480,
    [
      circle(300, 232, 212, G7, 'opacity="0.28"'),
      leaves(540, 70, 96, [W, G3, G2, AM], 0.95),
      leaves(52, 110, 44, [G2, G2, G2, G2], 0.55),
      rect(82, 68, 436, 284, G8, 18),
      rect(104, 78, 392, 4, G7, 2),
      a.draw(),
      b.draw(),
      c.draw(),
      ...person,
      // Comptoir
      rect(74, 350, 452, 16, G3, 8),
      rect(90, 366, 420, 80, G7, 6),
      rect(110, 382, 110, 48, G8, 8),
      rect(380, 382, 110, 48, G8, 8),
      circle(300, 406, 31, W),
      leaves(300, 406, 46, [G9, G7, G5, AM]),
      ...printer,
      ...till,
      ground(300, 450, 250),
      plant(44, 446, 1),
      plant(558, 446, 0.85),
    ],
    'Pharmacienne au comptoir devant les étagères de médicaments',
  );
}

function cloud(x, y, s, fill) {
  return `<g transform="translate(${x} ${y}) scale(${s})">${shape('M-62 28C-88 28 -92 -10 -62 -14C-64 -44 -22 -58 -6 -34C8 -62 62 -50 56 -14C90 -18 94 28 62 28Z', fill)}</g>`;
}

function horsLigne() {
  const row = (y, w) =>
    `${circle(196, y, 11, G7)}${line(`M190 ${y}L195 ${y + 5}L203 ${y - 5}`, W, 3.5)}${rect(218, y - 7, w, 6, G2, 3)}${rect(218, y + 4, w * 0.6, 5, G3, 2.5)}${rect(366, y - 6, 42, 11, G9, 5.5)}`;
  return svg(
    600,
    440,
    [
      circle(300, 230, 198, G7, 'opacity="0.28"'),
      cloud(448, 92, 1, G3),
      line('M404 48L496 138', AM, 12),
      line('M300 120C312 96 340 84 370 88', G2, 5, 'stroke-dasharray="2 14"'),
      leaves(96, 96, 66, [W, G3, G2, AM], 0.9),
      // Poste de caisse
      rect(150, 138, 300, 212, INK, 18),
      rect(164, 152, 272, 184, W, 8),
      rect(164, 152, 272, 28, AM, 8),
      rect(164, 168, 272, 12, AM),
      circle(180, 166, 5, INK),
      rect(194, 163, 104, 6, INK, 3),
      row(208, 108),
      row(246, 92),
      row(284, 118),
      rect(184, 306, 232, 20, G9, 10),
      rect(262, 313, 76, 6, G1, 3),
      rect(276, 350, 48, 30, INK),
      rect(226, 376, 148, 14, INK, 7),
      // Ticket imprimé
      shape('M478 330V236L488 244L498 236L508 244L518 236L528 244L538 236V330Z', W),
      rect(488, 258, 40, 5, G2, 2.5),
      rect(488, 272, 28, 5, G3, 2.5),
      rect(488, 286, 40, 5, G3, 2.5),
      rect(488, 306, 40, 9, G9, 4.5),
      rect(462, 326, 92, 64, G7, 12),
      circle(536, 372, 5, G3),
      // Bouclier : les données restent sur le poste
      shape('M96 238L136 252V292C136 322 118 340 96 350C74 340 56 322 56 292V252Z', AM),
      line('M76 292L91 307L119 277', W, 9),
      ground(300, 396, 262),
    ],
    'Poste de caisse qui continue de vendre sans connexion',
  );
}

function verification() {
  const doc = (x, y, r, fill) =>
    `<g transform="translate(${x} ${y}) rotate(${r})">${rect(-86, -112, 172, 224, fill, 14)}${rect(-62, -84, 76, 10, G9, 5)}${rect(-62, -60, 124, 7, G2, 3.5)}${rect(-62, -42, 108, 7, G3, 3.5)}${rect(-62, -24, 124, 7, G3, 3.5)}${rect(-62, -6, 84, 7, G3, 3.5)}${rect(-62, 22, 124, 7, G3, 3.5)}${rect(-62, 40, 96, 7, G3, 3.5)}</g>`;
  return svg(
    480,
    400,
    [
      circle(240, 200, 180, G7, 'opacity="0.3"'),
      doc(176, 196, -11, G3),
      doc(250, 204, 4, W),
      // Sceau
      shape('M222 262L208 318L226 308L238 326L248 270Z', AMD),
      shape('M262 262L276 318L258 308L246 326L236 270Z', AMD),
      circle(242, 258, 30, AM),
      leaves(242, 258, 36, [W, W, W, W]),
      // Loupe
      line('M368 282L418 338', INK, 22),
      circle(336, 244, 62, G1, 'opacity="0.96"'),
      circle(336, 244, 62, 'none', `stroke="${INK}" stroke-width="11"`),
      line('M308 244L328 264L366 224', G7, 13),
      leaves(404, 84, 62, [W, G3, G2, AM], 0.95),
      sprig(66, 352, 0.9),
      ground(240, 358, 190),
    ],
    'Documents de la pharmacie vérifiés',
  );
}

function carte() {
  const pin = (x, y, s, fill, inner) =>
    `<g transform="translate(${x} ${y}) scale(${s})">${shape('M0 0C-8 -14 -26 -28 -26 -48A26 26 0 0 1 26 -48C26 -28 8 -14 0 0Z', fill)}${circle(0, -48, 15, inner)}${rect(-3, -58, 6, 20, fill, 1.5)}${rect(-10, -51, 20, 6, fill, 1.5)}</g>`;
  return svg(
    520,
    400,
    [
      `<g transform="rotate(-6 250 210)">`,
      rect(52, 78, 396, 266, G1, 22),
      line('M52 168C130 150 190 212 262 196C340 178 380 130 448 142', W, 24),
      line('M150 78C140 150 190 230 168 344', W, 18),
      line('M330 78C310 160 372 250 356 344', W, 14),
      line('M52 286C150 268 280 320 448 270', G3, 30),
      rect(204, 100, 76, 52, G3, 10),
      rect(76, 196, 66, 52, G3, 10),
      rect(384, 180, 48, 62, G3, 10),
      line(
        'M120 262C170 230 230 262 262 232C286 210 290 190 292 176',
        AM,
        6,
        'stroke-dasharray="1 13"',
      ),
      circle(120, 262, 12, W),
      circle(120, 262, 7, '#2d7ff9'),
      `</g>`,
      ground(300, 196, 22),
      pin(300, 190, 1.45, AM, W),
      pin(140, 142, 0.85, G7, W),
      pin(414, 288, 0.9, G7, W),
      // Fiche de la pharmacie
      rect(304, 214, 176, 76, W, 14, 'stroke="#06402a" stroke-opacity="0.12"'),
      rect(320, 230, 104, 10, G9, 5),
      rect(320, 250, 78, 7, G2, 3.5),
      rect(320, 266, 54, 12, G1, 6),
      circle(452, 252, 15, G9),
      line('M446 252H458M453 246L459 252L453 258', W, 3),
      leaves(74, 66, 66, [W, G3, G2, AM], 0.95),
    ],
    'Carte montrant les pharmacies proches',
  );
}

function stock() {
  const carton = (x, y, w, h, fill, tape) =>
    `${rect(x, y - h, w, h, fill, 8)}${rect(x + w / 2 - 10, y - h, 20, h * 0.34, tape, 3)}${rect(x + 10, y - 22, w * 0.42, 6, tape, 3)}`;
  const check = (y, w) =>
    `${rect(246, y, 18, 18, G1, 5)}${line(`M250 ${y + 9}L254 ${y + 13}L261 ${y + 5}`, G7, 3)}${rect(274, y + 6, w, 6, G3, 3)}`;
  return svg(
    360,
    300,
    [
      circle(180, 150, 130, G7, 'opacity="0.32"'),
      carton(40, 262, 100, 80, AM, AMD),
      carton(146, 262, 74, 62, G2, G7),
      carton(66, 180, 82, 66, G3, G7),
      leaves(126, 150, 28, [G9, G7, G5, AM]),
      // Liste de réception
      rect(228, 74, 108, 172, W, 14),
      rect(256, 64, 52, 20, INK, 7),
      check(102, 44),
      check(132, 34),
      check(162, 46),
      rect(246, 202, 72, 22, G9, 11),
      rect(264, 210, 36, 6, G1, 3),
      ground(190, 264, 160),
      leaves(58, 62, 44, [W, G3, G2, AM], 0.9),
    ],
    'Cartons reçus et liste de contrôle du stock',
  );
}

function motif() {
  const faint = (x, y, s, o) => leaves(x, y, s, [W, W, W, W], o);
  return svg(
    240,
    240,
    [
      faint(48, 52, 56, 0.05),
      faint(172, 150, 84, 0.04),
      faint(184, 36, 22, 0.07),
      faint(56, 188, 28, 0.06),
    ],
    'Motif de feuilles',
  );
}

/** Icône de l'application : carré vert arrondi ; monochrome aux petites tailles. */
export function icon(size = 512) {
  const small = size <= 24;
  const colors = small ? [W, W, W, AM] : [W, G3, G2, AM];
  return svg(512, 512, [rect(0, 0, 512, 512, G9, 118), leaves(256, 256, 340, colors)], 'Sanara');
}

/** Bandeau de l'installateur Windows (164 × 314). */
export function installerSidebar() {
  return svg(
    164,
    314,
    [
      rect(0, 0, 164, 314, G9),
      leaves(150, 292, 130, [W, W, W, W], 0.06),
      leaves(10, 20, 80, [W, W, W, W], 0.05),
      leaves(82, 118, 78),
      `<text x="82" y="196" text-anchor="middle" font-family="'Bricolage Grotesque', 'Segoe UI', sans-serif" font-weight="800" font-size="30" letter-spacing="-1.2" fill="${W}">sanara</text>`,
      `<text x="82" y="218" text-anchor="middle" font-family="'Instrument Sans', 'Segoe UI', sans-serif" font-weight="600" font-size="8" letter-spacing="2.2" fill="${G3}">PHARMACIE CONNECTÉE</text>`,
    ],
    'Sanara',
  );
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  fs.mkdirSync(out, { recursive: true });
  const files = {
    'officine.svg': officine(),
    'hors-ligne.svg': horsLigne(),
    'verification.svg': verification(),
    'carte.svg': carte(),
    'stock.svg': stock(),
    'motif-feuilles.svg': motif(),
    'icone.svg': icon(),
  };
  for (const [name, content] of Object.entries(files)) {
    fs.writeFileSync(path.join(out, name), content);
  }
  console.log(`${Object.keys(files).length} images écrites dans ${out}`);
}
